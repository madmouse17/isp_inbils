<?php

namespace Tests\Traits;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\EmployeeProfile;
use App\Models\User;

/**
 * Fixtures for the customer sales attribution tests. Requires RefreshDatabase.
 */
trait BuildsCustomerAcquirers
{
    protected Company $company;

    /** The company the fixtures live in; the roles come with the migrations, so nothing is seeded. */
    protected function seedRolesAndCompany(): void
    {
        $this->company = Company::factory()->create(['is_active' => true]);
    }

    /**
     * A user of the company holding its copy of a template (`admin`, `manager`, `staff`...), with an active
     * EmployeeProfile unless $profile is false.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|false  $profile
     */
    protected function employee(string $role, ?Company $company = null, array $attributes = [], array|false $profile = []): User
    {
        $user = $this->companyUser($company, $attributes);
        $this->grantRole($user, $role, $company ?? $this->company);
        $this->giveProfile($user, $profile);

        return $user;
    }

    /**
     * A Staf user who can still register customers: the Staf template lacks `customer.create` for new companies (D9),
     * but the staff copies of existing companies keep it, and these tests model that seller.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|false  $profile
     */
    protected function salesStaff(?Company $company = null, array $attributes = [], array|false $profile = []): User
    {
        return $this->grantPermissions($this->employee('staff', $company, $attributes, $profile), ['customer.create']);
    }

    /**
     * A user of the company whose only role is a custom role holding exactly $permissions and web access.
     *
     * @param  list<string>  $permissions
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|false  $profile
     */
    protected function employeeWithPermissions(array $permissions, ?Company $company = null, array $attributes = [], array|false $profile = []): User
    {
        $user = $this->companyUser($company, $attributes);
        $this->grantPermissions($user, ['app.web', ...$permissions], $company ?? $this->company);
        $this->giveProfile($user, $profile);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function companyUser(?Company $company = null, array $attributes = []): User
    {
        return User::factory()->create([
            'company_id' => ($company ?? $this->company)->id,
            'email_verified_at' => now(),
            'is_active' => true,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>|false  $profile
     */
    protected function giveProfile(User $user, array|false $profile = []): void
    {
        if ($profile === false) {
            return;
        }

        EmployeeProfile::factory()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'status' => 'active',
            ...$profile,
        ]);
    }

    /**
     * A customer of the company, optionally already acquired by $acquirer (the column is not mass assignable;
     * the factory is unguarded).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function customerAcquiredBy(?User $acquirer, array $attributes = [], ?Company $company = null): Customer
    {
        return Customer::factory()->create([
            'company_id' => ($company ?? $this->company)->id,
            'acquired_by_user_id' => $acquirer?->id,
            ...$attributes,
        ]);
    }
}
