<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function view(User $user, User $model): bool|Response
    {
        if ($user->is($model)) {
            return true;
        }

        if (! $user->can('users.manage')) {
            return false;
        }

        return $this->administers($user, $model) ?: Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function store(User $user): bool
    {
        return $this->create($user);
    }

    public function update(User $user, User $model): bool|Response
    {
        return $this->change($user, $model, 'users.manage');
    }

    public function edit(User $user, User $model): bool|Response
    {
        return $this->update($user, $model);
    }

    public function delete(User $user, User $model): bool|Response
    {
        return $user->is($model) ? false : $this->change($user, $model, 'users.manage');
    }

    /**
     * Clearing someone's second factor lets whoever can reach their password log in: never your own
     * (turn it off from your profile), and only for a user you may change.
     */
    public function resetTwoFactor(User $user, User $model): bool|Response
    {
        return $user->is($model) ? false : $this->change($user, $model, 'users.two_factor.reset');
    }

    /**
     * The permission, and only an administrator may change an administrator. A user the actor does not answer for
     * (see administers) is hidden from them: that denial is a 404, a missing permission or role stays a 403.
     */
    private function change(User $actor, User $model, string $permission): bool|Response
    {
        if (! $actor->can($permission) || ($model->hasRole('admin') && ! $actor->hasRole('admin'))) {
            return false;
        }

        return $this->administers($actor, $model) ?: Response::denyAsNotFound();
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
