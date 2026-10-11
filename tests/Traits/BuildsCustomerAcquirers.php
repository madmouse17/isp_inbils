<?php

namespace Tests\Traits;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\EmployeeProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Role;

/**
 * Fixtures for the customer sales attribution tests. Requires RefreshDatabase.
 */
trait BuildsCustomerAcquirers
{
    protected Company $company;

    protected function seedRolesAndCompany(): void
    {
        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->company = Company::factory()->create(['is_active' => true]);
    }

    /**
     * A user of the company holding a seeded role, with an active EmployeeProfile unless $profile is false.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|false  $profile
     */
    protected function employee(string $role, ?Company $company = null, array $attributes = [], array|false $profile = []): User
    {
        $user = $this->companyUser($company, $attributes);
        $user->assignRole($role);
        $this->giveProfile($user, $profile);

        return $user;
    }

    /**
     * A user of the company whose only role is a custom role holding exactly $permissions.
     *
     * @param  list<string>  $permissions
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|false  $profile
     */
    protected function employeeWithPermissions(array $permissions, ?Company $company = null, array $attributes = [], array|false $profile = []): User
    {
        $role = Role::firstOrCreate(['name' => 'custom-'.md5(implode('|', $permissions)), 'guard_name' => 'web']);
        $role->syncPermissions($permissions);
        app()['cache']->forget('spatie.permission.cache');

        $user = $this->companyUser($company, $attributes);
        $user->assignRole($role);
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
