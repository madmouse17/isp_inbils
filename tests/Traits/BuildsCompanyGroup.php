<?php

namespace Tests\Traits;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;

/**
 * A company group for tenancy tests: one Head, two active subsidiaries (A and B) that all use IDR,
 * and an outsider that belongs to no group. Users are created with their home membership through
 * CompanyMembershipService, the way production code grants it.
 */
trait BuildsCompanyGroup
{
    protected Company $head;

    protected Company $subA;

    protected Company $subB;

    protected Company $outsider;

    protected function buildGroup(): void
    {
        $this->head = Company::factory()->create(['name' => 'Group Head', 'code' => 'HDQ', 'currency' => 'IDR', 'timezone' => 'Asia/Jakarta']);
        $this->subA = $this->subsidiary('Sub A', 'SBA');
        $this->subB = $this->subsidiary('Sub B', 'SBB');
        $this->outsider = Company::factory()->create(['name' => 'Outsider', 'code' => 'OUT', 'currency' => 'IDR']);
    }

    protected function subsidiary(string $name, string $code, string $currency = 'IDR', string $timezone = 'Asia/Jakarta'): Company
    {
        $company = Company::factory()->create(['name' => $name, 'code' => $code, 'currency' => $currency, 'timezone' => $timezone]);
        $company->forceFill(['parent_company_id' => $this->head->id])->save();

        return $company;
    }

    /**
     * A verified, active user homed in $home, also a member of every company in $others, holding the company's copy of
     * the template `$role` in each of them (roles are per company).
     */
    protected function groupUser(Company $home, string $role = 'admin', Company ...$others): User
    {
        $user = User::factory()->create([
            'company_id' => $home->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $memberships = app(CompanyMembershipService::class);
        $memberships->grant($user, $home, true);
        $this->grantRole($user, $role, $home);
        foreach ($others as $company) {
            $memberships->grant($user, $company);
            $this->grantRole($user, $role, $company);
        }

        return $user;
    }
}
