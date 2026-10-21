<?php

namespace Tests\Traits;

use App\Models\Core\Company;
use App\Models\User;

/**
 * Users for report capability tests. Every call creates its own company so tenant data never overlaps.
 */
trait CreatesReportingUsers
{
    /** A user holding the company's copy of a template: `admin`, `manager`, `staff`, `technician`, `customer`... */
    protected function userWithRole(string $roleName): User
    {
        return $this->grantRole($this->userInNewCompany(), $roleName);
    }

    /**
     * A user whose only role is a custom role of his company holding the given permissions and web access (a custom
     * role is never an app-only login).
     *
     * @param  list<string>  $permissions
     */
    protected function userWithPermissions(array $permissions, string $roleName = 'custom-report-reader'): User
    {
        $user = $this->userInNewCompany();
        $role = $this->companyRole((int) $user->company_id, $roleName.'-'.md5(implode('|', $permissions)), ['app.web', ...$permissions]);

        return $this->grantRole($user, $role);
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
