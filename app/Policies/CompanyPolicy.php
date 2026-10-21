<?php

namespace App\Policies;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyService;

class CompanyPolicy
{
    public function viewProfile(User $user, Company $company): bool
    {
        return $user->can('company.manage');
    }

    public function viewSettings(User $user, Company $company): bool
    {
        return $user->can('company.manage');
    }

    public function updateProfile(User $user, Company $company): bool
    {
        return $user->can('company.manage');
    }

    public function updateSettings(User $user, Company $company): bool
    {
        return $user->can('company.manage');
    }

    /**
     * Switching needs the permission, held in the company the user is in now, and a membership of an active company.
     * Going home is always allowed: roles are per company, so someone who lacks the permission in the company they
     * switched into would otherwise be stuck there.
     */
    public function switchTo(User $user, Company $company): bool
    {
        return CompanyService::canActIn($user, (int) $company->id)
            && ((int) $company->id === (int) $user->company_id || $user->can('company.switch'));
    }
}
