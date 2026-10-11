<?php

namespace Tests\Traits;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Companies, users, locations, subscriptions and assets for the IPAM and ODN tests, each built for an
 * explicit company. The six IPAM/ODN permissions and their role grants (Q14) are seeded here so the
 * tests do not depend on RolePermissionSeeder.
 */
trait BuildsIpamOdnFixtures
{
    /** @var array<string, list<string>> */
    private const IPAM_ODN_ROLE_GRANTS = [
        'admin' => ['ipam.view', 'ipam.manage', 'ipam.assign', 'odn.view', 'odn.manage', 'odn.assign'],
        'manager' => ['ipam.view', 'ipam.manage', 'ipam.assign', 'odn.view', 'odn.manage', 'odn.assign'],
        'staff' => ['ipam.view', 'odn.view', 'odn.assign'],
        'technician' => ['ipam.view', 'odn.view'],
        'customer' => [],
    ];

    protected Company $company;

    protected User $admin;

    /** Adds the six IPAM/ODN permissions to the five personas; it composes with RolePermissionSeeder (it only adds). */
    protected function seedIpamOdnPermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::IPAM_ODN_ROLE_GRANTS as $role => $permissions) {
            foreach ($permissions as $permission) {
                Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            }

            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web'])->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Company, an admin acting in it, and the permissions. Call from setUp(). Pass true when the test also
     * drives routes of other modules (asset create, SPK show...), which need their own permissions.
     */
    protected function bootIpamOdnWorld(bool $withSeededRoles = false): void
    {
        if ($withSeededRoles) {
            $this->seed(RolePermissionSeeder::class);
        }

        $this->seedIpamOdnPermissions();

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->admin = $this->makeUser($this->company, 'admin');
        $this->actingAs($this->admin);
    }

    protected function makeUser(Company $company, string $role): User
    {
        $user = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** Runs the callback as a user of another company (fixtures created through models need a company scope). */
    protected function inCompany(Company $company, callable $callback): mixed
    {
        return CompanyService::runAs($company->id, $callback);
    }

    protected function otherCompany(): Company
    {
        return Company::factory()->create(['is_active' => true]);
    }

    protected function makeLocation(Company $company, string $code, string $type = 'site', ?Location $parent = null): Location
    {
        return Location::factory()->create([
            'company_id' => $company->id,
            'parent_id' => $parent?->id,
            'code' => $code,
            'name' => $code,
            'type' => $type,
            'path' => $parent === null ? $code : $parent->path.' > '.$code,
        ]);
    }

    protected function makeSubscription(Company $company, string $status = 'active', ?Location $pop = null): ServiceSubscription
    {
        $customer = Customer::factory()->create(['company_id' => $company->id]);

        return ServiceSubscription::factory()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'service_package_id' => ServicePackageFactory::new()->create(['company_id' => $company->id])->id,
            'installation_address_id' => CustomerAddress::factory()->create([
                'company_id' => $company->id,
                'customer_id' => $customer->id,
                'label' => Str::random(12),
            ])->id,
            'serving_pop_id' => $pop?->id,
            'status' => $status,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    protected function makeAsset(Company $company, string $type, ?Location $location = null, array $attributes = []): NetworkAsset
    {
        return NetworkAssetFactory::new()->create([
            'company_id' => $company->id,
            'asset_type' => $type,
            'location_id' => $location?->id,
            'ip_address' => null,
            'management_ip' => null,
            ...$attributes,
        ]);
    }
}
