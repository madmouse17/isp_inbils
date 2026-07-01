<?php

namespace Modules\Service\Policies;

use App\Models\User;
use Modules\Service\Models\ServicePackage;

class ServicePackagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('service.view') || $user->can('service.manage');
    }

    public function view(User $user, ServicePackage $servicePackage): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('service.create') || $user->can('service.manage');
    }

    public function store(User $user): bool
    {
        return $this->create($user);
    }

    public function update(User $user, ServicePackage $servicePackage): bool
    {
        return $user->can('service.update') || $user->can('service.manage');
    }

    public function edit(User $user, ServicePackage $servicePackage): bool
    {
        return $this->update($user, $servicePackage);
    }

    public function delete(User $user, ServicePackage $servicePackage): bool
    {
        return $user->can('service.delete') || $user->can('service.manage');
    }
}
