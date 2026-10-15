<?php

namespace Modules\NetworkAsset\Services;

use App\Models\Core\ServiceSubscription;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\NetworkAsset\Jobs\ApplyNetworkAccountJob;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Services\Radius\Realm;

/**
 * Creating and changing the network account of a subscription, and the audited reveal of its secret.
 * Staff type the local part of the username; the company realm is appended here, so a RADIUS username is
 * `local@realm`, unique across all companies. Every save queues the apply job after commit; nothing here
 * calls RADIUS. Audit entries list field names and usernames only, never a secret.
 */
class NetworkAccountService
{
    public const LOCAL_PART_PATTERN = '/^[a-z0-9._-]{3,40}$/';

    private const GENERATED_SECRET_LENGTH = 12;

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
     * @return array{0: NetworkAccount, 1: ?string} the account, and the secret when it was generated (shown once)
     */
    public static function create(ServiceSubscription $subscription, array $data): array
    {
        $typed = $data['password'] ?? null;
        $generated = filled($typed) ? null : Str::password(self::GENERATED_SECRET_LENGTH, symbols: false);

        $account = DB::transaction(function () use ($subscription, $data, $typed, $generated): NetworkAccount {
            $username = self::guard($subscription, $data['username']);

            abort_if(NetworkAccount::query()->where('subscription_id', $subscription->id)->exists(), 422, 'Langganan ini sudah punya akun jaringan.');

            $account = NetworkAccount::query()->create([
                'subscription_id' => $subscription->id,
                'service_type' => $data['service_type'],
                'username' => $username,
                'password' => $generated ?? $typed,
            ]);

            AuditService::log('network_account', 'created', [
                'username' => $account->username,
                'service_type' => $account->service_type,
                'subscription_id' => $subscription->id,
                'secret_generated' => $generated !== null,
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

    /** The audit entry comes first: no entry, no secret. */
    public static function reveal(NetworkAccount $account): string
    {
        AuditService::log('network_account', 'secret_revealed', [
            'username' => $account->username,
            'subscription_id' => $account->subscription_id,
        ], $account);

        return $account->password;
    }

    /** Rewrite the account in RADIUS and cut its session: a Disconnect is owed before the job runs. */
    public static function resync(NetworkAccount $account): void
    {
        $account->forceFill(['pending_disconnect_at' => now()])->save();

        ApplyNetworkAccountJob::dispatch((int) $account->company_id, $account->id);
    }

    private static function localPart(NetworkAccount $account): string
    {
        return Str::before($account->username, '@');
    }

    /**
     * The subscription may still be served, has a RADIUS profile name, and the company has a realm.
     *
     * @return string the full username
     */
    private static function guard(ServiceSubscription $subscription, string $localPart, ?int $accountId = null): string
    {
        abort_unless((int) $subscription->company_id === CompanyService::currentId(), 404);
        abort_if($subscription->status === 'terminated', 422, 'Langganan yang sudah berhenti tidak dapat punya akun jaringan.');
        abort_if(blank($subscription->servicePackage?->speedProfile?->getAttribute('radius_profile_name')), 422, 'Profil kecepatan paket ini belum punya nama profil RADIUS.');
        abort_if(Realm::forCompany((int) $subscription->company_id) === null, 422, 'Kode perusahaan tidak dapat dipakai sebagai realm RADIUS.');
        abort_unless(preg_match(self::LOCAL_PART_PATTERN, $localPart) === 1, 422, 'Gunakan 3 sampai 40 huruf kecil, angka, titik, tanda hubung, atau garis bawah.');

        $username = self::fullUsername($localPart, (int) $subscription->company_id);

        abort_if(self::usernameTaken($username, $accountId), 422, 'Nama pengguna ini tidak tersedia.');

        return $username;
    }
}
