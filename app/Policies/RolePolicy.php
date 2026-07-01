<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    private const PROTECTED_ROLES = ['admin', 'manager', 'staff', 'technician', 'customer'];

    public function viewAny(User $user): bool
    {
        return $user->can('roles.manage');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can('roles.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('roles.manage');
    }

    public function store(User $user): bool
    {
        return $this->create($user);
    }

    public function update(User $user, Role $role): bool
    {
        return $user->can('roles.manage');
    }

    public function edit(User $user, Role $role): bool
    {
        return $this->update($user, $role);
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->can('roles.manage') && ! in_array($role->name, self::PROTECTED_ROLES, true);
    }
}
