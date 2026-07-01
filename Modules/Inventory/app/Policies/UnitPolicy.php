<?php

namespace Modules\Inventory\Policies;

use App\Models\User;
use Modules\Inventory\Models\Unit;

class UnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventory.view');
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.create');
    }

    public function store(User $user): bool
    {
        return $this->create($user);
    }

    public function update(User $user, Unit $unit): bool
    {
        return $user->can('inventory.update');
    }

    public function delete(User $user, Unit $unit): bool
    {
        return $user->can('inventory.delete');
    }
}
