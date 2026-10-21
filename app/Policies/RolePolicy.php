<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Services\Core\CompanyRoleService;
use App\Support\PermissionCatalogue;

/**
 * A role belongs to one company (ADR-004), and whoever holds roles.manage there reads and writes its roles. He may
 * not change a role richer than himself: one that carries a guarded permission he does not hold (G2), or he could
 * hand himself what he lacks by editing it. What he adds to a role is checked in CompanyRoleService (G1). A role of
 * another company never reaches a policy: the role model's company scope makes route binding answer 404.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionCatalogue::ADMINISTRATION);
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can(PermissionCatalogue::ADMINISTRATION);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionCatalogue::ADMINISTRATION);
    }

    public function store(User $user): bool
    {
        return $this->create($user);
    }

    public function update(User $user, Role $role): bool
    {
        return $user->can(PermissionCatalogue::ADMINISTRATION)
            && app(CompanyRoleService::class)->covers($user, $role->permissions->pluck('name')->all());
    }

    public function edit(User $user, Role $role): bool
    {
        return $this->update($user, $role);
    }

    public function delete(User $user, Role $role): bool
    {
        return $this->update($user, $role);
    }
}
