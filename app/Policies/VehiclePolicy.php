<?php

namespace App\Policies;

use App\Models\Core\Vehicle;
use App\Models\User;

class VehiclePolicy
{
    public function viewAny(User $user): bool { return $user->can('vehicle.view'); }
    public function view(User $user, Vehicle $vehicle): bool { return $user->can('vehicle.view'); }
    public function create(User $user): bool { return $user->can('vehicle.manage'); }
    public function store(User $user): bool { return $this->create($user); }
    public function update(User $user, Vehicle $vehicle): bool { return $user->can('vehicle.manage'); }
    public function delete(User $user, Vehicle $vehicle): bool { return $user->can('vehicle.manage'); }
}
