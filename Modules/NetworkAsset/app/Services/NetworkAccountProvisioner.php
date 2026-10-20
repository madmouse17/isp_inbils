<?php

namespace Modules\NetworkAsset\Services;

use App\Models\Core\ServiceSubscription;
use App\Services\Core\AuditService;
use App\Services\Core\IntegrationConfig;
use Illuminate\Support\Str;
use Modules\Ipam\Services\IpAssignmentService;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Services\Radius\DisconnectPendingException;
use Modules\NetworkAsset\Services\Radius\RadiusDirectory;
use Modules\NetworkAsset\Services\Radius\RadiusDisconnectClient;
use Modules\NetworkAsset\Services\Radius\RadiusException;
use Modules\NetworkAsset\Services\Radius\Realm;
use Modules\Service\Models\SpeedProfile;

/**
 * Brings RADIUS in line with the database for one network account (desired-state convergence).
 *
 * The desired state is derived on every run, never stored: the subscription status, block flag and the speed profile of
 * the package version it was sold under decide it (a package edit appends a version; subscriptions stay on theirs).
 * apply() is one code path for every trigger (observer, account save, resync, sync drift) and idempotent: a second
 * run against an unchanged RADIUS makes no write. Callers run it inside CompanyService::runAs().
 *
 * The record (`provisioned_*`) says what RADIUS was last known to hold. It advances once RADIUS is written, before the
 * Disconnects go out, so a router that does not answer leaves the group that was really written on the record and the
 * Disconnect still owed (`pending_disconnect_at`); the retry finishes it. A Disconnect owed to a name that only the old
 * record remembers (a rename, a removal) keeps the old record until it succeeded: an isolated or removed subscriber
 * never keeps a running session.
 *
 * A Disconnect is owed when the group changed, the static address RADIUS hands out differs from the one IPAM holds
 * (a session only gets its address when it starts), the user was removed or renamed, or staff pressed Resync (which
 * sets `pending_disconnect_at` and forces a rewrite). The flag is written before RADIUS is touched and cleared once every
 * Disconnect succeeded. A secret change alone owes none: the running session keeps working.
 *
 * The static address of the subscription (IPAM) is the user's `Framed-IP-Address` reply, kept in every state: an
 * isolated subscriber keeps its address. RADIUS itself is read for what it holds, so the record needs no column for it.
 */
class NetworkAccountProvisioner
{
    public const ABSENT = 'absent';

    public const ENABLED = 'enabled';

    public const ISOLATED = 'isolated';

    private const INTERIM_SECONDS = 300;

    public function __construct(private readonly RadiusDirectory $radius, private readonly RadiusDisconnectClient $coa) {}

    /**
     * pending and active subscriptions are enabled; suspended or blocked ones isolated; terminated or deleted ones absent.
     * A pending one whose installation was cancelled, with no installation underway, is absent too (K18).
     */
    public function desiredState(NetworkAccount $account): string
    {
        return $this->stateOf($account->subscription);
    }

    /** The same rule for a subscription that has no network account (yet). */
    public function stateOf(?ServiceSubscription $subscription): string
    {
        if ($subscription === null || $subscription->trashed() || ! in_array($subscription->status, ['pending', 'active', 'suspended'], true) || NetworkAccountService::installationAbandoned($subscription)) {
            return self::ABSENT;
        }

        return $this->isolatedBecause($subscription) === [] ? self::ENABLED : self::ISOLATED;
    }

    /** @return list<'suspended'|'blocked'> */
    public function isolatedBecause(?ServiceSubscription $subscription): array
    {
        if ($subscription === null || $subscription->trashed() || $subscription->status === 'terminated') {
            return [];
        }

        return array_values(array_filter([
            $subscription->status === 'suspended' ? 'suspended' : null,
            $subscription->blocked_at !== null ? 'blocked' : null,
        ]));
    }

    /**
     * @return bool true when RADIUS was converged, false when the run was skipped (the company has no enabled RADIUS integration; the account stays pending)
     *
     * @throws RadiusException kind unavailable (retry) or rejected (retrying cannot help)
     * @throws DisconnectPendingException a Disconnect got no usable answer; the run is retried
     */
    public function apply(NetworkAccount $account): bool
    {
        if (! app(IntegrationConfig::class)->isEnabled('radius')) {
            $this->markPending($account, RadiusException::disabled()->getMessage());

            return false;
        }

        $state = $this->desiredState($account);
        $present = $state !== self::ABSENT;
        $group = $present ? $this->groupFor($account, $state) : null;
        $username = $account->username;
        $secretHash = $this->secretHash($account);
        $framedIp = $present ? IpAssignmentService::staticAddressFor($account->subscription) : null;
        $heldIp = $present ? $this->radius->framedIpOf($username) : null;
        $moved = $heldIp !== $framedIp;

        $recordedGroup = $account->provisioned_group;
        $recordedName = $recordedGroup === null ? null : $account->provisioned_username;
        $forced = $account->pending_disconnect_at !== null;

        $owed = $this->owedUsernames($present, $username, $recordedName, $group !== $recordedGroup || $moved, $forced);
        $drifted = false;

        $needsWrite = $present
            && ($recordedName !== $username || $group !== $recordedGroup || $moved || $account->provisioned_password_hash !== $secretHash || $forced
                || ($drifted = ! $this->radius->hasUser($username, (string) $group, $secretHash)));

        if ($owed !== [] && ! $forced) {
            // Before RADIUS is touched: once it was written, nothing else may be left to say that a Disconnect is owed.
            $account->forceFill(['pending_disconnect_at' => now()])->save();
        }

        if ($recordedName !== null && ($recordedName !== $username || ! $present)) {
            $this->radius->removeUser($recordedName);
        }

        if ($needsWrite) {
            $this->radius->putGroup((string) $group, $this->repliesFor($account, $state));
            $this->radius->putUser($username, $account->password, (string) $group, $framedIp);
        }

        $after = ['provisioned_username' => $present ? $username : null, 'provisioned_group' => $group];
        $changed = $moved || $after !== ['provisioned_username' => $recordedName, 'provisioned_group' => $recordedGroup];

        // The record goes first unless the Disconnect is owed to a name only the old record remembers.
        $recordFirst = $present && $owed !== [] && array_diff($owed, [$username]) === [];

        $record = $after + [
            'provisioned_password_hash' => $present ? $secretHash : null,
            'provisioned_at' => $present ? ($changed || $needsWrite || $account->provisioned_at === null ? now() : $account->provisioned_at) : null,
        ];

        $details = ['username' => $username, 'service_type' => $account->service_type, 'state' => $state, 'group' => $group, 'framed_ip' => $framedIp];
        $audit = function () use ($account, $changed, $drifted, $details, $recordedGroup, $heldIp): void {
            if ($changed) {
                AuditService::log('network_account', 'provisioned', $details + ['previous_group' => $recordedGroup, 'previous_framed_ip' => $heldIp], $account);
            } elseif ($drifted) {
                AuditService::log('network_account', 'drift_corrected', $details, $account);
            }
        };

        if ($recordFirst) {
            $account->forceFill($record)->save();
            $audit();
        }

        $unsent = $this->disconnect($account, $owed);

        $account->forceFill(($recordFirst ? [] : $record) + [
            'pending_disconnect_at' => null,
            'sync_status' => 'synced',
            'last_error' => $unsent === [] ? null : Str::limit('Sesi terbuka berasal dari router yang belum didaftarkan: '.implode(', ', $unsent).'.', 255, ''),
            'last_attempt_at' => now(),
        ])->save();

        if (! $recordFirst) {
            $audit();
        }

        return true;
    }

    /**
     * True when RADIUS (or the record of it) differs from the desired state. Reads RADIUS; may throw RadiusException.
     * An account that cannot be applied at all (no profile name, invalid realm) is not drift.
     */
    public function hasDrifted(NetworkAccount $account): bool
    {
        $state = $this->desiredState($account);

        if ($state === self::ABSENT) {
            return $account->provisioned_group !== null;
        }

        try {
            $group = $this->groupFor($account, $state);
        } catch (RadiusException $e) {
            if ($e->kind === RadiusException::REJECTED) {
                return false;
            }

            throw $e;
        }

        return $account->provisioned_group !== $group
            || $account->provisioned_username !== $account->username
            || $this->radius->framedIpOf($account->username) !== IpAssignmentService::staticAddressFor($account->subscription)
            || ! $this->radius->hasUser($account->username, $group, $this->secretHash($account));
    }

    /** Rewrite the group of one speed profile (no Disconnect: online sessions keep their rate until they redial). */
    public function writeSpeedGroup(SpeedProfile $profile): void
    {
        $name = (string) $profile->getAttribute('radius_profile_name');
        $realm = Realm::forCompany((int) $profile->getAttribute('company_id'));

        if ($name === '' || $realm === null) {
            return;
        }

        $this->radius->putGroup($this->groupName($realm, $name), $this->speedReplies($profile));
    }

    /**
     * Write one NAS as a FreeRADIUS client (and drop the previous address when it changed). A failure is recorded
     * on the row for the sync to retry and never thrown: the office keeps working while RADIUS is down.
     */
    public function writeNas(NasDevice $nas): bool
    {
        try {
            $realm = $nas->realm ?? throw RadiusException::invalidRealm();
            $label = (string) $nas->networkAsset?->code;
            $name = (string) $nas->networkAsset?->name;

            $this->radius->putNas((string) $nas->nasname, $realm, (string) $nas->radius_secret, trim($label.' '.$name), $nas->radius_nasname);

            if ($nas->radius_nasname !== null && $nas->radius_nasname !== $nas->nasname) {
                $this->radius->removeNas($nas->radius_nasname);
            }
        } catch (RadiusException $e) {
            $nas->forceFill(['last_error' => Str::limit($e->getMessage(), 255, '')])->save();
            AuditService::log('nas_device', 'radius_failed', ['asset_code' => $nas->networkAsset?->code, 'error_kind' => $e->kind], $nas);

            return false;
        }

        $nas->forceFill(['radius_nasname' => $nas->nasname, 'radius_synced_at' => now(), 'last_error' => null])->save();
        AuditService::log('nas_device', 'radius_written', ['asset_code' => $nas->networkAsset?->code], $nas);

        return true;
    }

    /**
     * The usernames whose open sessions must be cut.
     *
     * @return list<string>
     */
    private function owedUsernames(bool $present, string $username, ?string $recordedName, bool $grantChanged, bool $forced): array
    {
        $owed = [];

        if ($recordedName !== null && ($recordedName !== $username || ! $present)) {
            $owed[] = $recordedName;
        }

        if ($present && ($grantChanged || $forced)) {
            $owed[] = $username;
        }

        return array_values(array_unique($owed));
    }

    /**
     * Cut every open session of the owed usernames on the NAS that holds it. Done = ACK or NAK "session not found".
     *
     * @param  list<string>  $usernames
     * @return list<string> addresses of sessions no NAS of the company owns (recorded, not sent, not retried)
     *
     * @throws DisconnectPendingException at least one Disconnect got no usable answer (the others were still sent)
     */
    private function disconnect(NetworkAccount $account, array $usernames): array
    {
        $unsent = [];
        $failed = 0;

        foreach ($usernames as $username) {
            foreach ($this->radius->openSessions($username) as $session) {
                $nas = NasDevice::query()->with('networkAsset:id,code,management_ip')->where('nasname', $session['nas_ip'])->first();
                $host = (string) $nas?->networkAsset?->getAttribute('management_ip');

                if ($nas === null || $nas->radius_secret === null || $host === '') {
                    $unsent[] = $session['nas_ip'];

                    continue;
                }

                $result = $this->coa->disconnect($host, (int) $nas->coa_port, $nas->radius_secret, [
                    'User-Name' => $username,
                    'Acct-Session-Id' => $session['session_id'],
                    'NAS-IP-Address' => $session['nas_ip'],
                ]);

                AuditService::log('network_account', 'disconnected', [
                    'username' => $username,
                    'nas' => $nas->networkAsset?->code,
                    'result' => $result->status.($result->errorCause === null ? '' : ':'.$result->errorCause),
                ], $account);

                $failed += $result->isDone() ? 0 : 1;
            }
        }

        if ($failed > 0) {
            throw new DisconnectPendingException('Router belum menjawab permintaan pemutusan sesi; dicoba lagi otomatis.');
        }

        return array_values(array_unique($unsent));
    }

    private function markPending(NetworkAccount $account, string $reason): void
    {
        $account->forceFill(['sync_status' => 'pending', 'last_error' => Str::limit($reason, 255, ''), 'last_attempt_at' => now()])->save();
    }

    private function groupFor(NetworkAccount $account, string $state): string
    {
        $realm = Realm::forCompany((int) $account->company_id)
            ?? throw RadiusException::invalidRealm();

        if ($state === self::ISOLATED) {
            return $this->groupName($realm, 'isolir');
        }

        $name = $account->subscription?->packageVersion?->speedProfile?->getAttribute('radius_profile_name');

        if (! is_string($name) || $name === '') {
            throw RadiusException::rejected('Profil kecepatan paket ini belum punya nama profil RADIUS.');
        }

        return $this->groupName($realm, $name);
    }

    private function groupName(string $realm, string $name): string
    {
        $group = $realm.'/'.$name;

        if (mb_strlen($group) > 64) {
            throw RadiusException::rejected('Nama grup RADIUS lebih dari 64 karakter.');
        }

        return $group;
    }

    /** @return array<string, string|int> */
    private function repliesFor(NetworkAccount $account, string $state): array
    {
        if ($state === self::ISOLATED) {
            return ['Mikrotik-Group' => 'isolir', 'Mikrotik-Address-List' => 'isolir', 'Acct-Interim-Interval' => self::INTERIM_SECONDS];
        }

        /** @var SpeedProfile $profile */
        $profile = $account->subscription->packageVersion->speedProfile;

        return $this->speedReplies($profile);
    }

    /** `Mikrotik-Rate-Limit` is rx/tx seen from the router: rx = the client's upload. */
    private function speedReplies(SpeedProfile $profile): array
    {
        return [
            'Mikrotik-Group' => (string) $profile->getAttribute('radius_profile_name'),
            'Mikrotik-Rate-Limit' => $profile->getAttribute('upload_max_mbps').'M/'.$profile->getAttribute('download_max_mbps').'M',
            'Acct-Interim-Interval' => self::INTERIM_SECONDS,
        ];
    }

    private function secretHash(NetworkAccount $account): string
    {
        return hash_hmac('sha256', $account->password, (string) config('app.key'));
    }
}
