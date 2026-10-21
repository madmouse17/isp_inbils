<?php

namespace Tests\Traits;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Support\Str;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\Service\Database\Factories\ServicePackageFactory;

/**
 * Companies, users, locations, subscriptions and assets for the IPAM and ODN tests, each built for an
 * explicit company. The IPAM and ODN permissions belong to the catalogue and to the templates, so a company's copies
 * of the five personas hold them without any seeding here.
 */
trait BuildsIpamOdnFixtures
{
    protected Company $company;

    protected User $admin;

    /**
     * Company and an admin acting in it. Call from setUp(). The admin holds every guarded permission, so the tests
     * that also drive routes of other modules (asset create, SPK show...) need nothing more.
     */
    protected function bootIpamOdnWorld(): void
    {
        $this->company = Company::factory()->create(['is_active' => true]);
        $this->admin = $this->makeUser($this->company, 'admin');
        $this->actingAs($this->admin);
    }

    /** A user of the company holding its copy of the template `$role` (`admin`, `manager`, `staff`...). */
    protected function makeUser(Company $company, string $role): User
    {
        $user = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        return $this->grantRole($user, $role, $company);
    }

    /**
     * A user of the company whose only role is a custom one holding exactly `app.web` and $permissions. makeUser()
     * would add a template's permissions on top, because roles add up.
     *
     * @param  list<string>  $permissions
     */
    protected function makeUserWithPermissions(Company $company, array $permissions): User
    {
        $user = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        return $this->grantPermissions($user, ['app.web', ...$permissions], $company);
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
