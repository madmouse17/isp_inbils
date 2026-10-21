<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyRoleService;
use App\Services\Core\CompanyService;
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
     * The roles a member holds in the company the actor acts in. The member may be homed elsewhere: the roles are
     * the company's own, so membership of this company is all it takes, and a user who is no member is hidden (404).
     * Like change(), never a user richer than the actor.
     */
    public function assignRoles(User $actor, User $target): bool|Response
    {
        if (! $actor->can('users.manage')) {
            return false;
        }

        if (! $this->isMember($target)) {
            return Response::denyAsNotFound();
        }

        return $this->covers($actor, $target);
    }

    /**
     * The permission, and only a user whose guarded permissions the actor holds in full (G2), in every company the
     * target belongs to: an administrator covers everyone he shares every company with, a manager does not cover an
     * administrator. A user the actor does not answer for (see administers) is hidden from them: that denial is a 404,
     * a missing permission or role stays a 403.
     */
    private function change(User $actor, User $model, string $permission): bool|Response
    {
        if (! $actor->can($permission) || ! $this->covers($actor, $model)) {
            return false;
        }

        if (! $this->administers($actor, $model)) {
            return Response::denyAsNotFound();
        }

        return $this->coversInOtherCompanies($actor, $model);
    }

    /**
     * Roles are per company, and a login reaches all of them: the target may hold more in another company than here.
     * Without this check an administrator here, who is a plain member there, could change the credentials of the user who
     * administers there and sign in as him. Each company is judged as the company it is: what the actor and the target
     * hold there. Both carry what they hold in every company (User::permissionNamesIn()), which a page of users loads
     * with one query for all its rows, so the check switches no company and reloads no role.
     */
    private function coversInOtherCompanies(User $actor, User $model): bool
    {
        if ($actor->is($model)) {
            return true;
        }

        $current = CompanyService::currentId();
        $roles = app(CompanyRoleService::class);

        return $model->companyIds()
            ->reject(fn (int $companyId): bool => $companyId === $current)
            ->every(fn (int $companyId): bool => $roles->covers($actor, $model->permissionNamesIn($companyId), $companyId));
    }

    /** G2: every guarded permission the target holds in the company the actor acts in is held by the actor too. */
    private function covers(User $actor, User $model): bool
    {
        return $actor->is($model)
            || app(CompanyRoleService::class)->covers($actor, $model->getAllPermissions()->pluck('name')->all());
    }

    /** Home company or a granted membership of the company the actor acts in. */
    private function isMember(User $user): bool
    {
        $companyId = CompanyService::currentId();

        return $companyId !== null && $user->companyIds()->contains($companyId);
    }

    /**
     * One login acts in every company it belongs to. Changing its email, password or status therefore
     * reaches all of them, so the actor must belong to each.
     */
    private function administers(User $actor, User $model): bool
    {
        return app(CompanyMembershipService::class)->administers($actor, $model);
    }
}
