<?php

namespace Modules\NetworkAsset\Services;

use App\Models\Core\ServiceSubscription;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\NetworkAsset\Jobs\ApplyNetworkAccountJob;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;

/**
 * Creating and changing the network account of a subscription, and the audited reveal of its password.
 * Every save queues the apply job after commit; nothing here calls a router. Audit entries list field names
 * and ids only, never a password.
 */
class NetworkAccountService
{
    private const GENERATED_PASSWORD_LENGTH = 12;

    /**
     * @param  array{nas_device_id: int, service_type: string, username: string, password?: ?string}  $data
     * @return array{0: NetworkAccount, 1: ?string} the account, and the password when it was generated (shown once)
     */
    public static function create(ServiceSubscription $subscription, array $data): array
    {
        $typed = $data['password'] ?? null;
        $generated = filled($typed) ? null : Str::password(self::GENERATED_PASSWORD_LENGTH, symbols: false);

        $account = DB::transaction(function () use ($subscription, $data, $typed, $generated): NetworkAccount {
            self::guard($subscription, (int) $data['nas_device_id']);

            abort_if(NetworkAccount::query()->where('subscription_id', $subscription->id)->exists(), 422, 'This subscription already has a network account.');

            $account = NetworkAccount::query()->create([
                'subscription_id' => $subscription->id,
                'nas_device_id' => $data['nas_device_id'],
                'service_type' => $data['service_type'],
                'username' => $data['username'],
                'password' => $generated ?? $typed,
            ]);

            AuditService::log('network_account', 'created', [
                'username' => $account->username,
                'service_type' => $account->service_type,
                'nas_device_id' => $account->nas_device_id,
                'subscription_id' => $subscription->id,
                'password_generated' => $generated !== null,
            ], $account);

            ApplyNetworkAccountJob::dispatchAfterCommit((int) $account->company_id, $account->id, self::adoptionApproval($account));

            return $account;
        });

        return [$account, $generated];
    }

    /**
     * A blank password keeps the stored one.
     *
     * @param  array{nas_device_id?: int, service_type?: string, username?: string, password?: ?string}  $data
     */
    public static function update(NetworkAccount $account, array $data): NetworkAccount
    {
        return DB::transaction(function () use ($account, $data): NetworkAccount {
            self::guard(ServiceSubscription::query()->findOrFail($account->subscription_id), (int) ($data['nas_device_id'] ?? $account->nas_device_id));

            $password = $data['password'] ?? null;
            $account->fill(Arr::only($data, ['nas_device_id', 'service_type', 'username']));

            // Nothing recorded says where the first write went, so a retarget would leave the original secret and session alive.
            if ($account->pending_disconnect_at !== null && $account->provisioned_profile === null && $account->isDirty(['nas_device_id', 'service_type', 'username'])) {
                throw ValidationException::withMessages(['username' => 'An earlier sync left a session disconnect unfinished on the current router. Wait until it is retried before changing the router, service type or username.']);
            }

            $passwordChanged = is_string($password) && $password !== '';

            if ($passwordChanged) {
                $account->password = $password;
            }

            $changed = array_values(array_diff(array_keys($account->getDirty()), ['password']));

            if ($changed === [] && ! $passwordChanged) {
                return $account;
            }

            $account->save();

            if ($changed !== []) {
                AuditService::log('network_account', 'updated', ['username' => $account->username, 'changed' => $changed], $account);
            }

            if ($passwordChanged) {
                AuditService::log('network_account', 'password_changed', ['username' => $account->username], $account);
            }

            ApplyNetworkAccountJob::dispatchAfterCommit((int) $account->company_id, $account->id, self::adoptionApproval($account));

            return $account;
        });
    }

    /** The audit entry comes first: no entry, no password. */
    public static function reveal(NetworkAccount $account): string
    {
        AuditService::log('network_account', 'password_revealed', [
            'username' => $account->username,
            'subscription_id' => $account->subscription_id,
        ], $account);

        return $account->password;
    }

    /**
     * Taking over a secret that already exists on a router is a router-credential decision: administrators only.
     * The approval names the target as saved now, so a retarget by someone else afterwards is not covered by it.
     */
    public static function adoptionApproval(NetworkAccount $account): ?string
    {
        return Auth::user()?->can('network_asset.nas.manage') ? ApplyNetworkAccountJob::adoptionApprovalFor($account) : null;
    }

    /** The subscription may still be served, has a router profile name, and the router is this company's. */
    private static function guard(ServiceSubscription $subscription, int $nasDeviceId): void
    {
        abort_unless((int) $subscription->company_id === CompanyService::currentId(), 404);
        abort_if($subscription->status === 'terminated', 422, 'A terminated subscription cannot have a network account.');
        abort_if(blank($subscription->servicePackage?->speedProfile?->getAttribute('radius_profile_name')), 422, 'The speed profile of this package has no router profile name.');
        abort_if(NasDevice::query()->whereKey($nasDeviceId)->doesntExist(), 422, 'Choose a NAS router of this company.');
    }
}
