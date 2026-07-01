<?php

namespace App\Policies;

use App\Models\Core\OrganizationUnit;
use App\Models\User;

class OrganizationPolicy
{
    public function viewAny(User $user): bool { return $user->can('organization.view'); }
    public function view(User $user, OrganizationUnit $unit): bool { return $user->can('organization.view'); }
    public function create(User $user): bool { return $user->can('organization.manage'); }
    public function store(User $user): bool { return $this->create($user); }
    public function update(User $user, OrganizationUnit $unit): bool { return $user->can('organization.manage'); }
    public function delete(User $user, OrganizationUnit $unit): bool { return $user->can('organization.manage'); }
}
