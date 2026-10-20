<?php

namespace Modules\NetworkAsset\Services;

use App\Models\Core\ServiceSubscription;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use App\Services\Core\IntegrationConfig;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\NetworkAsset\Jobs\ApplyNetworkAccountJob;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Services\Radius\Realm;
use Modules\SPK\Models\WorkOrder;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Creating and changing the network account of a subscription, and the audited reveal of its secret.
 * Staff type the local part of the username; the company realm is appended here, so a RADIUS username is
 * `local@realm`, unique across all companies. Every save queues the apply job after commit; nothing here
 * calls RADIUS. Audit entries list field names and usernames only, never a secret.
 *
 * K18: the account of an installation is made when its SPK is first assigned (provisionForInstallation) and has no RADIUS
 * user when that installation was cancelled and none other is underway (installationAbandoned).
 */
class NetworkAccountService
{
    public const LOCAL_PART_PATTERN = '/^[a-z0-9._-]{3,40}$/';

    private const GENERATED_SECRET_LENGTH = 12;

    /** The statuses of an installation SPK that keep the account of a pending subscription served: someone is installing it. */
    private const INSTALLATION_UNDERWAY = ['assigned', 'in_progress', 'waiting_review', 'rejected', 'completed'];

    /** `local@realm`; the realm is the lower-cased code of the company. */
    public static function fullUsername(string $localPart, int $companyId): string
    {
        return $localPart.'@'.Realm::forCompany($companyId);
    }

    /** Across companies: the check sees every account and its answer never says whose it is. */
    public static function usernameTaken(string $username, ?int $exceptAccountId = null): bool
    {
        return NetworkAccount::withoutCompany()
            ->where('username', $username)
            ->when($exceptAccountId, fn ($query) => $query->whereKeyNot($exceptAccountId))
            ->exists();
    }

    /**
     * @param  array{service_type: string, username: string, password?: ?string}  $data  username is the local part
     * @param  array<string, mixed>  $audit  more properties of the `created` entry: who asked for the account
     * @return array{0: NetworkAccount, 1: ?string} the account, and the secret when it was generated (shown once)
     */
    public static function create(ServiceSubscription $subscription, array $data, array $audit = []): array
    {
        $typed = $data['password'] ?? null;
        $generated = filled($typed) ? null : Str::password(self::GENERATED_SECRET_LENGTH, symbols: false);

        $account = DB::transaction(function () use ($subscription, $data, $typed, $generated, $audit): NetworkAccount {
            $username = self::guard($subscription, $data['username']);

            abort_if(NetworkAccount::query()->where('subscription_id', $subscription->id)->exists(), 422, 'Langganan ini sudah punya akun jaringan.');

            try {
                $account = NetworkAccount::query()->create([
                    'subscription_id' => $subscription->id,
                    'service_type' => $data['service_type'],
                    'username' => $username,
                    'password' => $generated ?? $typed,
                ]);
            } catch (UniqueConstraintViolationException) {
                // A parallel create passed the same pre-check: answer as the pre-check would.
                abort_if(NetworkAccount::query()->where('subscription_id', $subscription->id)->exists(), 422, 'Langganan ini sudah punya akun jaringan.');
                abort(422, 'Nama pengguna ini tidak tersedia.');
            }

            AuditService::log('network_account', 'created', [
                'username' => $account->username,
                'service_type' => $account->service_type,
                'subscription_id' => $subscription->id,
                'secret_generated' => $generated !== null,
                ...$audit,
            ], $account);

            ApplyNetworkAccountJob::dispatchAfterCommit((int) $account->company_id, $account->id);

            return $account;
        });

        return [$account, $generated];
    }

    /**
     * A blank secret keeps the stored one. A secret change alone owes no Disconnect: the engine decides.
     *
     * @param  array{service_type?: string, username?: string, password?: ?string}  $data  username is the local part
     */
    public static function update(NetworkAccount $account, array $data): NetworkAccount
    {
        return DB::transaction(function () use ($account, $data): NetworkAccount {
            $subscription = ServiceSubscription::query()->findOrFail($account->subscription_id);
            $secret = $data['password'] ?? null;
            $username = self::guard($subscription, $data['username'] ?? self::localPart($account), $account->id);

            $account->fill(Arr::only($data, ['service_type']));
            $account->username = $username;

            $secretChanged = is_string($secret) && $secret !== '';

            if ($secretChanged) {
                $account->password = $secret;
            }

            $changed = array_values(array_diff(array_keys($account->getDirty()), ['password']));

            if ($changed === [] && ! $secretChanged) {
                return $account;
            }

            $account->save();

            if ($changed !== []) {
                AuditService::log('network_account', 'updated', ['username' => $account->username, 'changed' => $changed], $account);
            }

            if ($secretChanged) {
                AuditService::log('network_account', 'secret_changed', ['username' => $account->username], $account);
            }

            ApplyNetworkAccountJob::dispatchAfterCommit((int) $account->company_id, $account->id);

            return $account;
        });
    }

    /**
     * The audit entry comes first: no entry, no secret. `$audit` says who asked and for what (the technician app adds
     * the SPK); it never carries the secret.
     *
     * @param  array<string, mixed>  $audit
     */
    public static function reveal(NetworkAccount $account, array $audit = []): string
    {
        AuditService::log('network_account', 'secret_revealed', [
            'username' => $account->username,
            'subscription_id' => $account->subscription_id,
            ...$audit,
        ], $account);

        return $account->password;
    }

    /**
     * Rewrite the account in RADIUS and cut its session: a Disconnect is owed before the job runs. A Disconnect that is
     * already owed keeps its first moment, so "the router has not answered since" does not move on a retry.
     */
    public static function resync(NetworkAccount $account): void
    {
        $account->forceFill(['pending_disconnect_at' => $account->pending_disconnect_at ?? now()])->save();

        ApplyNetworkAccountJob::dispatch((int) $account->company_id, $account->id);
    }

    /**
     * K18: the PPPoE login of an installation, made when its SPK is first assigned (and by the approval, when it is still
     * missing), so the technician has it on site before the review. Only for a company with RADIUS enabled: without it no
     * account is made. Idempotent: a subscription that has an account keeps it, and the apply job is queued again, which
     * serves the account of an installation that was cancelled and planned
     * anew. The login is the customer code and the secret is generated; nobody types either.
     *
     * A refusal of create() (no RADIUS profile name on the speed profile, no realm, no free name) never blocks the
     * assignment or the approval: it is audited with its reason, the SPK page and the app say the account is not made,
     * and staff create it by hand. Nothing here calls RADIUS.
     *
     * @return ?NetworkAccount the account this call made; null when it made none
     */
    public static function provisionForInstallation(WorkOrder $wo): ?NetworkAccount
    {
        if ($wo->type !== 'installation' || $wo->subscription_id === null) {
            return null;
        }

        $subscription = ServiceSubscription::query()->with('customer')->find($wo->subscription_id);

        if ($subscription === null || $subscription->status === 'terminated') {
            return null;
        }

        $existing = NetworkAccount::query()->where('subscription_id', $subscription->id)->first(['id', 'company_id']);

        if ($existing !== null) {
            ApplyNetworkAccountJob::dispatchAfterCommit((int) $existing->company_id, $existing->id);

            return null;
        }

        // A secret no RADIUS server knows would mislead the technician, and a server enabled later would receive it: without
        // RADIUS nothing is made here, and staff can still create the account by hand.
        if (! app(IntegrationConfig::class)->isEnabled('radius', (int) $subscription->company_id)) {
            return null;
        }

        try {
            [$account] = self::create(
                $subscription,
                ['service_type' => 'pppoe', 'username' => self::installationLocalPart($subscription)],
                ['via' => 'installation_spk', 'work_order_id' => $wo->id],
            );

            return $account;
        } catch (HttpException $refusal) {
            AuditService::log('network_account', 'auto_create_skipped', [
                'work_order_id' => $wo->id,
                'subscription_id' => $subscription->id,
                'reason' => $refusal->getMessage(),
            ], $wo);

            return null;
        }
    }

    /**
     * K18: the installation of a pending subscription was cancelled after it had been assigned, and no installation SPK
     * is underway now. Its account then has no RADIUS user (NetworkAccountProvisioner reads this), so a login handed out
     * for an installation nobody will make stops working; a new assignment serves it again. A subscription that is active,
     * suspended or terminated, and a cancelled SPK nobody was assigned, change nothing.
     */
    public static function installationAbandoned(ServiceSubscription $subscription): bool
    {
        if ($subscription->status !== 'pending') {
            return false;
        }

        $installations = DB::table('work_orders')
            ->where('company_id', $subscription->company_id)
            ->where('subscription_id', $subscription->id)
            ->where('type', 'installation');

        // A deleted cancelled SPK still counts: housekeeping must not bring a login back.
        return (clone $installations)->where('status', 'cancelled')->whereNotNull('assigned_to')->exists()
            && ! (clone $installations)->whereNull('deleted_at')->whereIn('status', self::INSTALLATION_UNDERWAY)->exists();
    }

    /**
     * K18: called by the cancel of an installation SPK, inside its transaction. When that leaves the subscription
     * abandoned, the secret is replaced (the technician has seen the old one) and the apply job is queued: the
     * provisioner now reads the account as absent and removes the user and the open session. The row stays, because the
     * sweep needs it to remove what RADIUS still holds and the next assignment serves it again.
     */
    public static function disableForCancelledInstallation(WorkOrder $wo): void
    {
        if ($wo->type !== 'installation' || $wo->subscription_id === null) {
            return;
        }

        $subscription = ServiceSubscription::query()->find($wo->subscription_id);

        if ($subscription === null || ! self::installationAbandoned($subscription)) {
            return;
        }

        $account = NetworkAccount::query()->where('subscription_id', $subscription->id)->lockForUpdate()->first();

        if ($account === null) {
            return;
        }

        self::rotateSecret($account);

        AuditService::log('network_account', 'disabled', [
            'username' => $account->username,
            'subscription_id' => $subscription->id,
            'work_order_id' => $wo->id,
            'reason' => 'installation_cancelled',
        ], $account);

        ApplyNetworkAccountJob::dispatchAfterCommit((int) $account->company_id, $account->id);
    }

    /**
     * K18: an installation SPK of a pending subscription changed hands. The previous technician may have revealed the
     * secret and it would keep logging the customer in, so a new secret replaces it and the apply job is queued. The
     * same technician named again, a non-installation SPK, a subscription that is not pending and a missing account
     * change nothing.
     */
    public static function rotateForReassignedInstallation(WorkOrder $wo, ?int $previousTechnicianId, int $technicianId): void
    {
        if ($wo->type !== 'installation' || $wo->subscription_id === null || $previousTechnicianId === null || $previousTechnicianId === $technicianId) {
            return;
        }

        $subscription = ServiceSubscription::query()->find($wo->subscription_id);

        if ($subscription === null || $subscription->status !== 'pending') {
            return;
        }

        $account = NetworkAccount::query()->where('subscription_id', $subscription->id)->lockForUpdate()->first();

        if ($account === null) {
            return;
        }

        self::rotateSecret($account);

        AuditService::log('network_account', 'secret_rotated', [
            'username' => $account->username,
            'subscription_id' => $subscription->id,
            'work_order_id' => $wo->id,
            'reason' => 'installation_reassigned',
        ], $account);

        ApplyNetworkAccountJob::dispatchAfterCommit((int) $account->company_id, $account->id);
    }

    private static function rotateSecret(NetworkAccount $account): void
    {
        $account->forceFill(['password' => Str::password(self::GENERATED_SECRET_LENGTH, symbols: false)])->save();
    }

    private static function localPart(NetworkAccount $account): string
    {
        return Str::before($account->username, '@');
    }

    /**
     * The customer code as a login: lower case, only `[a-z0-9._-]`, short enough for `local@realm` to fit the 64 characters
     * RADIUS and the column hold, and a number added while the name is taken (`-2`, `-3`). A name that is still taken after
     * fifty tries is left for create() to refuse.
     */
    private static function installationLocalPart(ServiceSubscription $subscription): string
    {
        $companyId = (int) $subscription->company_id;
        $base = (string) preg_replace('/[^a-z0-9._-]/', '', Str::lower((string) $subscription->customer?->code));
        $room = min(40, 63 - strlen((string) Realm::forCompany($companyId)));

        $local = '';

        for ($number = 1; $number <= 50; $number++) {
            $suffix = $number === 1 ? '' : "-{$number}";
            $local = substr($base, 0, $room - strlen($suffix)).$suffix;

            if (! self::usernameTaken(self::fullUsername($local, $companyId))) {
                break;
            }
        }

        return $local;
    }

    /**
     * The subscription may still be served, its sold version has a RADIUS profile name, and the company has a realm.
     *
     * @return string the full username
     */
    private static function guard(ServiceSubscription $subscription, string $localPart, ?int $accountId = null): string
    {
        abort_unless((int) $subscription->company_id === CompanyService::currentId(), 404);
        abort_if($subscription->status === 'terminated', 422, 'Langganan yang sudah berhenti tidak dapat punya akun jaringan.');
        abort_if(blank($subscription->packageVersion?->speedProfile?->getAttribute('radius_profile_name')), 422, 'Profil kecepatan paket ini belum punya nama profil RADIUS.');
        abort_if(Realm::forCompany((int) $subscription->company_id) === null, 422, 'Kode perusahaan tidak dapat dipakai sebagai realm RADIUS.');
        abort_unless(preg_match(self::LOCAL_PART_PATTERN, $localPart) === 1, 422, 'Gunakan 3 sampai 40 huruf kecil, angka, titik, tanda hubung, atau garis bawah.');

        $username = self::fullUsername($localPart, (int) $subscription->company_id);

        abort_if(self::usernameTaken($username, $accountId), 422, 'Nama pengguna ini tidak tersedia.');

        return $username;
    }
}
