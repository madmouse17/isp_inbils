<?php

namespace App\Support\Notifications;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The people of one company who hold a permission: who hears of an event that has no single addressee ("the billing
 * staff", "dispatch"). The one place that decides who counts as staff of a company, so every notice and every
 * "may this person be given the work" check resolves the same people.
 *
 * A user belongs to a company through their home company or a `company_user` row. `User` is exempt from the company
 * scope, so this membership is the tenant boundary: it is never read from `users.company_id` alone. What a member
 * holds is read in the company named here, never in the ambient one: a webhook, a queued job or the scheduler has
 * nobody signed in, and a request of company A must not count a member's roles in A for a notice of company B.
 */
final class StaffRecipients
{
    /**
     * Active members of the company who hold the permission there, directly or through one of that company's roles.
     * One query; a permission nobody holds gives an empty collection, so a notice never takes the change that raised
     * it down.
     *
     * @return Collection<int, User>
     */
    public static function holding(int $companyId, string $permission, ?int $exceptUserId = null): Collection
    {
        return self::members($companyId)
            ->withPermissionIn($permission, $companyId)
            ->when($exceptUserId !== null, fn (Builder $query) => $query->whereKeyNot($exceptUserId))
            ->get();
    }

    /**
     * Active members of the company, as a query the caller narrows (HoldsPermission asks about one person).
     *
     * @return Builder<User>
     */
    public static function members(int $companyId): Builder
    {
        return User::query()
            ->where('users.is_active', true)
            ->where(fn (Builder $members) => $members
                ->where('users.company_id', $companyId)
                ->orWhereExists(fn ($membership) => $membership
                    ->from('company_user')
                    ->whereColumn('company_user.user_id', 'users.id')
                    ->where('company_user.company_id', $companyId)));
    }
}
