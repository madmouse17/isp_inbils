<?php

namespace Tests\Traits;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Support\Str;
use Modules\NetworkAsset\Jobs\ApplyNetworkAccountJob;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\Service\Database\Factories\SpeedProfileFactory;
use Modules\Service\Models\ServicePackage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeRouterOs;

/**
 * NAS routers, packages with a router profile name and network accounts, built for one explicit company.
 * Use together with InteractsWithRouterOs (and BuildsSubscriptionBilling for the subscriptions).
 */
trait BuildsNetworkFixtures
{
    /** A package whose speed profile names the given router profile (null = none, so it cannot be applied). */
    protected function packageWithRouterProfile(Company $company, ?string $routerProfile = 'home-20m'): ServicePackage
    {
        $speed = SpeedProfileFactory::new()->create(['company_id' => $company->id, 'name' => 'Speed '.Str::random(10), 'radius_profile_name' => $routerProfile]);

        return ServicePackageFactory::new()->create(['company_id' => $company->id, 'name' => 'Package '.Str::random(10), 'speed_profile_id' => $speed->id]);
    }

    /**
     * A NAS router (writes on by default) with its in-memory router, which already holds the given profiles.
     *
     * @param  list<string>  $profiles
     * @return array{0: NasDevice, 1: FakeRouterOs}
     */
    protected function nasRouter(Company $company, array $profiles = ['home-20m', 'home-50m', 'isolir'], bool $writable = true): array
    {
        $nas = NasDevice::factory()->create(['company_id' => $company->id, 'provisioning_enabled' => $writable]);

        return [$nas, $this->routerFor($nas)->profile(...$profiles)];
    }

    /** @param  array<string, mixed>  $attributes */
    protected function networkAccountFor(ServiceSubscription $subscription, NasDevice $nas, array $attributes = []): NetworkAccount
    {
        return NetworkAccount::factory()->create($attributes + [
            'company_id' => $subscription->company_id,
            'subscription_id' => $subscription->id,
            'nas_device_id' => $nas->id,
            'username' => 'alice',
            'password' => 'fake-subscriber-secret',
        ]);
    }

    /**
     * What saving or resyncing an account does: queue the apply job (the sync queue runs it at once in tests).
     * By default as an administrator would (network_asset.nas.manage): the job carries the approval of the target as
     * it is stored now, so taking over an existing secret is allowed. Pass false for what the sync or a plain staff save does.
     */
    protected function applyAccount(NetworkAccount $account, bool $mayAdopt = true): void
    {
        ApplyNetworkAccountJob::dispatch((int) $account->company_id, $account->id, $mayAdopt ? ApplyNetworkAccountJob::adoptionApprovalFor($account->fresh()) : null);
    }

    /** @return list<string> */
    protected function networkPermissions(): array
    {
        return ['network_asset.nas.view', 'network_asset.nas.manage', 'network_asset.account.view', 'network_asset.account.manage', 'network_asset.account.reveal'];
    }

    /** The seeded admin role does not hold the new permissions yet (RolePermissionSeeder is the lead's): grant them for the test. */
    protected function grantNetworkPermissionsToAdminRole(): void
    {
        foreach ($this->networkPermissions() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findByName('admin', 'web')->givePermissionTo($this->networkPermissions());
    }

    /** A user of the company holding exactly these permissions and no role. */
    protected function userWithPermissions(Company $company, string ...$permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create(['company_id' => $company->id, 'is_active' => true, 'email_verified_at' => now()]);
        $user->givePermissionTo($permissions);

        return $user;
    }
}
