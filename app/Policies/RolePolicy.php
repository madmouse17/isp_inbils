<?php

namespace App\Policies;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyService;
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
        return $user->can('roles.manage') && $this->ownsRoleDefinitions($user);
    }

    public function store(User $user): bool
    {
        return $this->create($user);
    }

    public function update(User $user, Role $role): bool
    {
        if (! $this->ownsRoleDefinitions($user)) {
            return false;
        }

        if (in_array($role->name, self::PROTECTED_ROLES, true)) {
            return $user->hasRole('admin');
        }

        return $user->can('roles.manage');
    }

    public function edit(User $user, Role $role): bool
    {
        return $this->update($user, $role);
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->can('roles.manage')
            && $this->ownsRoleDefinitions($user)
            && ! in_array($role->name, self::PROTECTED_ROLES, true);
    }

    /**
     * Roles are global (teams=false): one change reaches the users of every company. So only an actor
     * acting in a top-level company (a Head or a standalone company) may change them, never one
     * acting in a subsidiary.
     */
    private function ownsRoleDefinitions(User $user): bool
    {
        $companyId = CompanyService::idFor($user);

        return $companyId !== null
            && Company::query()->whereKey($companyId)->whereNull('parent_company_id')->exists();
    }
}
