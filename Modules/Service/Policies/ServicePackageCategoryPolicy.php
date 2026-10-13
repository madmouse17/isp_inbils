<?php

namespace Modules\Service\Policies;

use App\Models\User;
use Modules\Service\Models\ServicePackageCategory;

class ServicePackageCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('service.view') || $user->can('service.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('service.create') || $user->can('service.manage');
    }

    public function update(User $user, ServicePackageCategory $category): bool
    {
        return $user->can('service.update') || $user->can('service.manage');
    }

    public function delete(User $user, ServicePackageCategory $category): bool
    {
        return $user->can('service.delete') || $user->can('service.manage');
    }
}
