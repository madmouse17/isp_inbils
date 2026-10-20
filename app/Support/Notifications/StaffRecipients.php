<?php

namespace App\Support\Notifications;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The people of one company who hold a permission: who hears of an event that has no single addressee ("the billing
 * staff", "dispatch"). The one place that decides who counts as staff of a company, so a change in how permissions
 * resolve per company lands here and nowhere else.
 *
 * A user belongs to a company through their home company or a `company_user` row. `User` is exempt from the company
 * scope, so this membership is the tenant boundary: it is never read from `users.company_id` alone.
 */
final class StaffRecipients
{
    /**
     * Active members of the company who hold the permission, directly or through a role. One query; a permission
     * nobody holds gives an empty collection, so a notice never takes the change that raised it down.
     *
     * @return Collection<int, User>
     */
    public static function holding(int $companyId, string $permission, ?int $exceptUserId = null): Collection
    {
        return User::query()
            ->where('users.is_active', true)
            ->where(fn (Builder $members) => $members
                ->where('users.company_id', $companyId)
                ->orWhereExists(fn ($membership) => $membership
                    ->from('company_user')
                    ->whereColumn('company_user.user_id', 'users.id')
                    ->where('company_user.company_id', $companyId)))
            ->where(fn (Builder $holders) => $holders
                ->whereHas('permissions', fn (Builder $query) => $query->where('name', $permission))
                ->orWhereHas('roles.permissions', fn (Builder $query) => $query->where('name', $permission)))
            ->when($exceptUserId !== null, fn (Builder $query) => $query->whereKeyNot($exceptUserId))
            ->get();
    }
}
