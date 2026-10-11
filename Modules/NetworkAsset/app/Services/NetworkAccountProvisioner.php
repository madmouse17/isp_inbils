<?php

namespace Modules\NetworkAsset\Services;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\AuditService;
use Illuminate\Support\Str;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;

/**
 * Brings the router in line with the database for one network account (desired-state convergence).
 *
 * The desired state is derived on every run, never stored: the subscription status and package decide it.
 * apply() is one code path for every trigger (observer, account save, resync, sync drift) and idempotent:
 * a second run against an unchanged router makes no write. Callers run it inside CompanyService::runAs().
 *
 * The record (`provisioned_*`) says what the router was last known to serve and only advances after every router
 * call of the run, the session drop included, succeeded. A run that failed half way therefore finds the old
 * record again and finishes the job: an isolated or removed subscriber never keeps a running session.
 *
 * Where the record cannot tell that a drop is still owed (drift corrected on a matching record, a first adoption, a
 * removed secret nothing recorded), `pending_disconnect_at` is written before the router is touched and cleared
 * together with the record advance.
 */
class NetworkAccountProvisioner
{
    public const ABSENT = 'absent';

    public const ENABLED = 'enabled';

    public const ISOLATED = 'isolated';

    /** Written into every secret Inbils manages; the sync uses it to tell managed secrets from stray ones. */
    public const COMMENT_PREFIX = 'inbils:';

    /** A router found unreachable is left alone for this long. */
    private const UNREACHABLE_SKIP_MINUTES = 2;

    public function __construct(private readonly RouterOsClient $client, private readonly NasDeviceHealth $health) {}

    /** pending and active subscriptions are enabled, suspended ones isolated, terminated or deleted ones absent. */
    public function desiredState(NetworkAccount $account): string
    {
        return $this->stateOf($account->subscription);
    }

    /** The same rule for a subscription that has no network account (yet). */
    public function stateOf(?ServiceSubscription $subscription): string
    {
        if ($subscription === null || $subscription->trashed()) {
            return self::ABSENT;
        }

        return match ($subscription->status) {
            'pending', 'active' => self::ENABLED,
            'suspended' => self::ISOLATED,
            default => self::ABSENT,
        };
    }

    /** The router profile the account carries now: the package's speed profile, the isolir profile, or none. */
    public function desiredProfile(NetworkAccount $account): ?string
    {
        return match ($this->desiredState($account)) {
            self::ENABLED => $this->speedProfileName($account),
            self::ISOLATED => $account->nasDevice->isolir_profile,
            default => null,
        };
    }

    /** Why a router must not be touched right now, or null. */
    public function skipReason(NasDevice $nas): ?string
    {
        return match (true) {
            ! config('services.routeros.enabled') => 'Router provisioning is switched off for this installation.',
            ! $nas->provisioning_enabled => 'Writes are switched off for this router.',
            $nas->unreachable_since !== null && $nas->last_checked_at?->gt(now()->subMinutes(self::UNREACHABLE_SKIP_MINUTES)) === true => 'The router was unreachable a moment ago; it is tried again shortly.',
            default => null,
        };
    }

    /**
     * @param  bool  $mayAdopt  an administrator (network_asset.nas.manage) asked for this run: a secret that already
     *                          exists on the router without our comment may be taken over (PPPoE secrets only)
     * @return bool true when the router was contacted, false when the run was skipped (the account stays pending)
     *
     * @throws RouterOsException a router call failed; the caller decides between retrying and failing
     */
    public function apply(NetworkAccount $account, bool $mayAdopt = false): bool
    {
        $nas = $account->nasDevice;

        if (($reason = $this->skipReason($nas)) !== null) {
            return $this->skip($account, $reason);
        }

        $type = $account->service_type;
        $state = $this->desiredState($account);
        $profile = $this->desiredProfile($account);

        if ($state !== self::ABSENT && $profile === null) {
            throw RouterOsException::rejected('The speed profile of the package has no router profile name.');
        }

        $before = $this->recorded($account);
        $moved = $account->provisioned_profile !== null && ! $this->sameTarget($account, $nas);

        if ($moved && ! $this->cleanUp($account)) {
            return false;
        }

        $recordedProfile = $account->provisioned_profile;
        $managed = $recordedProfile !== null;
        $found = $this->client->find($nas, $type, $account->username);

        if ($state !== self::ABSENT && $found !== null && ! $managed && ! $this->isOurs($found)) {
            $this->assertAdoptable($account, $found);

            if (! $mayAdopt) {
                return $this->skip($account, "A secret named '{$account->username}' already exists on the router and is not managed by Inbils. An administrator (network_asset.nas.manage) adopts it with Resync.");
            }
        }

        // A running session keeps the profile it was opened with: it is dropped when the secret goes (also when an
        // earlier run removed it and failed before the drop), after a profile change, when the record says the router
        // served another profile (a retry), on first contact with an existing secret straight into isolir, and
        // whenever an earlier run left a drop owed.
        if ($state === self::ABSENT) {
            $dropSessions = $managed || ($found !== null && $this->isOurs($found));
        } else {
            $dropSessions = ($found !== null && $found['profile'] !== $profile)
                || ($managed && $recordedProfile !== $profile)
                || ($state === self::ISOLATED && ! $managed && $found !== null);
        }

        $dropSessions = $dropSessions || $account->pending_disconnect_at !== null;

        if ($dropSessions && $account->pending_disconnect_at === null) {
            // Before the router is touched: once it was written, nothing else may be left to say that a drop is owed.
            $account->forceFill(['pending_disconnect_at' => now()])->save();
        }

        $wrote = false;
        $driftFixed = false;

        if ($state === self::ABSENT) {
            if ($found !== null && ($managed || $this->isOurs($found))) {
                $this->client->remove($nas, $type, $found['id']);
                $wrote = true;
            }
        } else {
            [$wrote, $driftFixed] = $this->converge($account, $nas, $found, (string) $profile, $managed);
        }

        if ($dropSessions) {
            $this->dropSessions($nas, $type, $account->username);
        }

        $after = [
            'provisioned_nas_device_id' => $state === self::ABSENT ? null : $nas->id,
            'provisioned_username' => $state === self::ABSENT ? null : $account->username,
            'provisioned_service_type' => $state === self::ABSENT ? null : $type,
            'provisioned_profile' => $profile,
        ];
        $changed = $before !== $after;

        $account->forceFill($after + [
            'provisioned_password_hash' => $state === self::ABSENT ? null : $this->passwordHash($account),
            'provisioned_at' => $state === self::ABSENT ? null : ($changed || $wrote || $account->provisioned_at === null ? now() : $account->provisioned_at),
            'pending_disconnect_at' => null,
            'sync_status' => 'synced',
            'last_error' => null,
            'last_attempt_at' => now(),
        ])->save();

        $this->health->reachable($nas);

        $details = ['username' => $account->username, 'service_type' => $type, 'nas_device_id' => $nas->id, 'state' => $state, 'profile' => $profile];

        if ($changed) {
            AuditService::log('network_account', 'provisioned', $details + ['previous_profile' => $before['provisioned_profile']], $account);
        } elseif ($driftFixed) {
            AuditService::log('network_account', 'drift_corrected', $details, $account);
        }

        return true;
    }

    /**
     * Create or correct the secret. Passwords cannot be read back from the router (no `sensitive` policy), so
     * the password is pushed on creation, on adoption and whenever its fingerprint changed.
     *
     * @param  array{id: string, name: string, profile: ?string, disabled: bool, comment: ?string, service: ?string}|null  $found  the router's record for the username
     * @return array{0: bool, 1: bool} wrote anything, fixed drift the record did not account for
     */
    private function converge(NetworkAccount $account, NasDevice $nas, ?array $found, string $profile, bool $managed): array
    {
        $type = $account->service_type;
        $comment = $this->comment($account);

        if ($found === null) {
            $fields = ['name' => $account->username, 'password' => $account->password, 'profile' => $profile, 'comment' => $comment, 'disabled' => false];
            $this->client->create($nas, $type, $type === 'pppoe' ? $fields + ['service' => 'pppoe'] : $fields);

            return [true, $managed];
        }

        $fields = [];

        if ($found['profile'] !== $profile) {
            $fields['profile'] = $profile;
        }

        if ($found['disabled']) {
            $fields['disabled'] = false;
        }

        if ($found['comment'] !== $comment) {
            $fields['comment'] = $comment;
        }

        // A secret open to "any" service (adopted, or drifted) is narrowed to PPPoE.
        if ($type === 'pppoe' && $found['service'] !== 'pppoe') {
            $fields['service'] = 'pppoe';
        }

        $drifted = $fields !== [];

        if (! $managed || $account->provisioned_password_hash !== $this->passwordHash($account)) {
            $fields['password'] = $account->password;
        }

        if ($fields === []) {
            return [false, false];
        }

        $this->client->update($nas, $type, $found['id'], $fields);

        return [true, $managed && $drifted];
    }

    /** Remove the secret from the router it was provisioned on (router, username or type changed). */
    private function cleanUp(NetworkAccount $account): bool
    {
        $old = NasDevice::query()->find($account->provisioned_nas_device_id);

        if ($old !== null) {
            if (($reason = $this->skipReason($old)) !== null) {
                $this->skip($account, 'Cannot remove the secret from the previous router: '.$reason);

                return false;
            }

            $type = (string) $account->provisioned_service_type;
            $found = $this->client->find($old, $type, (string) $account->provisioned_username);

            if ($found !== null) {
                $this->client->remove($old, $type, $found['id']);
            }

            $this->dropSessions($old, $type, (string) $account->provisioned_username);
        }

        $account->forceFill([
            'provisioned_nas_device_id' => null,
            'provisioned_username' => null,
            'provisioned_service_type' => null,
            'provisioned_profile' => null,
            'provisioned_password_hash' => null,
            'provisioned_at' => null,
            'pending_disconnect_at' => null,
        ])->save();

        return true;
    }

    private function dropSessions(NasDevice $nas, string $type, string $username): void
    {
        foreach ($this->client->activeSessions($nas, $type, $username) as $session) {
            $this->client->disconnect($nas, $type, $session['id']);
        }
    }

    private function skip(NetworkAccount $account, string $reason): false
    {
        $account->forceFill(['sync_status' => 'pending', 'last_error' => Str::limit($reason, 255, ''), 'last_attempt_at' => now()])->save();

        return false;
    }

    /** @param  array{comment: ?string}  $found */
    private function isOurs(array $found): bool
    {
        return str_starts_with((string) $found['comment'], self::COMMENT_PREFIX);
    }

    /**
     * Only PPPoE secrets are taken over: the same menu also holds L2TP, PPTP, SSTP and OpenVPN users, which a
     * subscriber must never be able to claim by choosing their name.
     *
     * @param  array{service: ?string}  $found
     */
    private function assertAdoptable(NetworkAccount $account, array $found): void
    {
        if ($account->service_type !== 'pppoe' || in_array($found['service'], [null, 'pppoe', 'any'], true)) {
            return;
        }

        $service = Str::limit(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $found['service']) ?? '', 16, '');

        throw RouterOsException::rejected("The secret '{$account->username}' on the router is a {$service} secret, not PPPoE. Inbils does not take it over.");
    }

    /** @return array{provisioned_nas_device_id: ?int, provisioned_username: ?string, provisioned_service_type: ?string, provisioned_profile: ?string} */
    private function recorded(NetworkAccount $account): array
    {
        return [
            'provisioned_nas_device_id' => $account->provisioned_nas_device_id === null ? null : (int) $account->provisioned_nas_device_id,
            'provisioned_username' => $account->provisioned_username,
            'provisioned_service_type' => $account->provisioned_service_type,
            'provisioned_profile' => $account->provisioned_profile,
        ];
    }

    private function sameTarget(NetworkAccount $account, NasDevice $nas): bool
    {
        return (int) $account->provisioned_nas_device_id === $nas->id
            && $account->provisioned_username === $account->username
            && $account->provisioned_service_type === $account->service_type;
    }

    private function speedProfileName(NetworkAccount $account): ?string
    {
        $name = $account->subscription?->servicePackage?->speedProfile?->getAttribute('radius_profile_name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function comment(NetworkAccount $account): string
    {
        return self::COMMENT_PREFIX.Company::query()->whereKey($account->company_id)->value('code').':'.$account->subscription?->code;
    }

    private function passwordHash(NetworkAccount $account): string
    {
        return hash_hmac('sha256', $account->password, (string) config('app.key'));
    }
}
