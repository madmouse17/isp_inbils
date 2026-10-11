<?php

namespace Tests\Traits;

use App\Models\Core\Company;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Users for report capability tests. Every call creates its own company so tenant data never overlaps.
 * Requires Database\Seeders\RolePermissionSeeder to have run.
 */
trait CreatesReportingUsers
{
    protected function userWithRole(string $roleName): User
    {
        $user = $this->userInNewCompany();
        $user->assignRole(Role::where('name', $roleName)->firstOrFail());

        return $user;
    }

    /**
     * A user whose only role is a custom Spatie role holding exactly the given permissions.
     *
     * @param  list<string>  $permissions
     */
    protected function userWithPermissions(array $permissions, string $roleName = 'custom-report-reader'): User
    {
        $role = Role::firstOrCreate(['name' => $roleName.'-'.md5(implode('|', $permissions)), 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        $user = $this->userInNewCompany();
        $user->assignRole($role);

        return $user;
    }

    private function userInNewCompany(): User
    {
        $company = Company::factory()->create(['is_active' => true]);

        return User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }
}
