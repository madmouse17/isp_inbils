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
     * Asking the platform for a subsidiary: whoever manages the company profile, and only in a top-level company, the
     * kind that has a page in the operator's panel. A subsidiary has none, so its users ask in their parent.
     */
    public function requestSubsidiary(User $user, Company $company): bool
    {
        return $company->parent_company_id === null && $user->can('company.manage');
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
