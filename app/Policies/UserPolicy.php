<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('users.manage') || $user->is($model);
    }

    public function create(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function store(User $user): bool
    {
        return $this->create($user);
    }

    public function update(User $user, User $model): bool
    {
        return $user->can('users.manage');
    }

    public function edit(User $user, User $model): bool
    {
        return $this->update($user, $model);
    }

    public function delete(User $user, User $model): bool
    {
        return $user->can('users.manage') && ! $user->is($model);
    }
}
