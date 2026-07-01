<?php

namespace App\Policies;

use App\Models\Core\Company;
use App\Models\User;

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
}
