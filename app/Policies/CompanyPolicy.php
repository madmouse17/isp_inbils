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

    /** Switching needs the permission and a membership of an active company; roles stay global. */
    public function switchTo(User $user, Company $company): bool
    {
        return $user->can('company.switch') && CompanyService::canActIn($user, (int) $company->id);
    }
}
