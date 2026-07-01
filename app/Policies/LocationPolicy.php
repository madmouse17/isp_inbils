<?php

namespace App\Policies;

use App\Models\Core\Location;
use App\Models\User;

class LocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('location.view');
    }

    public function view(User $user, Location $location): bool
    {
        return $user->can('location.view');
    }

    public function create(User $user): bool
    {
        return $user->can('location.create');
    }

    public function store(User $user): bool
    {
        return $this->create($user);
    }

    public function update(User $user, Location $location): bool
    {
        return $user->can('location.update');
    }

    public function edit(User $user, Location $location): bool
    {
        return $this->update($user, $location);
    }

    public function move(User $user, Location $location): bool
    {
        return $this->update($user, $location);
    }

    public function delete(User $user, Location $location): bool
    {
        return $user->can('location.delete');
    }
}
