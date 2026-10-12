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

/**
 * NAS clients, packages with a RADIUS profile name and network accounts, built for one explicit company.
 * Use together with InteractsWithRadius and InteractsWithNasCoa (and BuildsSubscriptionBilling for the subscriptions).
 */
trait BuildsNetworkFixtures
{
    /** A package whose speed profile names the given RADIUS profile (null = none, so it cannot be applied). */
    protected function packageWithRadiusProfile(Company $company, ?string $radiusProfile = 'home-20m', int $down = 20, int $up = 10): ServicePackage
    {
        $speed = SpeedProfileFactory::new()->create([
            'company_id' => $company->id,
            'name' => 'Speed '.Str::random(10),
            'radius_profile_name' => $radiusProfile,
            'download_max_mbps' => $down,
            'upload_max_mbps' => $up,
        ]);

        return ServicePackageFactory::new()->create(['company_id' => $company->id, 'name' => 'Package '.Str::random(10), 'speed_profile_id' => $speed->id]);
    }

    /** A registered NAS client of the company (RADIUS row not written yet) at an address of its own. */
    protected function nasClient(Company $company, ?string $nasname = null): NasDevice
    {
        return NasDevice::factory()->create(['company_id' => $company->id] + ($nasname === null ? [] : ['nasname' => $nasname]));
    }

    /** The realm of a company, as the engine derives it. */
    protected function realmOf(Company $company): string
    {
        return strtolower($company->code);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function networkAccountFor(ServiceSubscription $subscription, array $attributes = []): NetworkAccount
    {
        $realm = $this->realmOf(Company::query()->findOrFail($subscription->company_id));

        return NetworkAccount::factory()->create($attributes + [
            'company_id' => $subscription->company_id,
            'subscription_id' => $subscription->id,
            'username' => 'alice@'.$realm,
            'password' => 'fake-subscriber-secret',
        ]);
    }

    /** What saving or resyncing an account does: queue the apply job (the sync queue runs it at once in tests). */
    protected function applyAccount(NetworkAccount $account): void
    {
        ApplyNetworkAccountJob::dispatch((int) $account->company_id, $account->id);
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
