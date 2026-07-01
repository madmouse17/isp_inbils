<?php

namespace App\Policies;

use App\Models\Core\EmployeeProfile;
use App\Models\User;

class EmployeePolicy
{
    public function viewAny(User $user): bool { return $user->can('employee.view'); }
    public function view(User $user, EmployeeProfile $profile): bool { return $user->can('employee.view'); }
    public function create(User $user): bool { return $user->can('employee.manage'); }
    public function store(User $user): bool { return $this->create($user); }
    public function update(User $user, EmployeeProfile $profile): bool { return $user->can('employee.manage'); }
    public function delete(User $user, EmployeeProfile $profile): bool { return $user->can('employee.manage'); }
}
