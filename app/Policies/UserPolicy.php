<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Core\CompanyMembershipService;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function view(User $user, User $model): bool
    {
        return $user->is($model) || ($user->can('users.manage') && $this->administers($user, $model));
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
        return $user->can('users.manage') && $this->mayChange($user, $model);
    }

    public function edit(User $user, User $model): bool
    {
        return $this->update($user, $model);
    }

    public function delete(User $user, User $model): bool
    {
        return $user->can('users.manage') && ! $user->is($model) && $this->mayChange($user, $model);
    }

    /**
     * Clearing someone's second factor lets whoever can reach their password log in: never your own
     * (turn it off from your profile), and only for a user you may change.
     */
    public function resetTwoFactor(User $user, User $model): bool
    {
        return $user->can('user.two_factor.reset') && ! $user->is($model) && $this->mayChange($user, $model);
    }

    /** Only an administrator may edit or remove an administrator, and only one who answers for the user's companies. */
    private function mayChange(User $actor, User $model): bool
    {
        return (! $model->hasRole('admin') || $actor->hasRole('admin')) && $this->administers($actor, $model);
    }

    /**
     * One login acts, with its global roles, in every company it belongs to. Changing its email,
     * password, roles or status therefore reaches all of them, so the actor must belong to each.
     */
    private function administers(User $actor, User $model): bool
    {
        return app(CompanyMembershipService::class)->administers($actor, $model);
    }
}
