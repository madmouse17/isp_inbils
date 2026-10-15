<?php

namespace App\Queries\Admin;

use App\Models\Core\Customer;
use App\Models\Core\EmployeeProfile;
use App\Models\User;
use App\Services\Core\CompanyService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Single source of truth for who may be credited as the acquirer of a customer. The validation rule,
 * the create default and the Create/Edit option lists all go through eligible(). User has no company
 * global scope, so every company condition here is explicit.
 */
class CustomerAcquirerQuery
{
    private const PERMISSION = 'customer.create';

    /**
     * Active users of the company with an active employee profile of that company who hold
     * customer.create, directly or through a role.
     *
     * @return Builder<User>
     */
    public static function eligible(int $companyId): Builder
    {
        return User::query()
            ->where('users.company_id', $companyId)
            ->where('users.is_active', true)
            ->whereHas('employeeProfile', fn (Builder $profile) => $profile
                ->withoutGlobalScope('company')
                ->where('employee_profiles.company_id', $companyId)
                ->where('employee_profiles.status', 'active'))
            ->where(fn (Builder $query) => $query
                ->whereHas('permissions', fn (Builder $permission) => $permission->where('permissions.name', self::PERMISSION))
                ->orWhereHas('roles.permissions', fn (Builder $permission) => $permission->where('permissions.name', self::PERMISSION)));
    }

    public static function isEligible(int $companyId, int $userId): bool
    {
        return self::eligible($companyId)->whereKey($userId)->exists();
    }

    /** A submitted acquirer id: an int or a string of digits. Booleans, floats, arrays and "1abc" are not ids. */
    public static function isId(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && ctype_digit($value));
    }

    /** Validation rule: the value must pass isId() (null is skipped by `nullable`). */
    public static function idRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! self::isId($value)) {
                $fail('validation.integer')->translate();
            }
        };
    }

    /**
     * Users credited with a customer (soft-deleted ones included) cannot be hard-deleted: the foreign
     * key restricts it. Refuse with a validation error on the `user` key before anything is torn down.
     *
     * @throws ValidationException
     */
    public static function ensureUserDeletable(User $user): void
    {
        if (Customer::withTrashed()->where('acquired_by_user_id', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'user' => 'Pengguna yang dicatat memperoleh pelanggan tidak bisa dihapus. Nonaktifkan akunnya.',
            ]);
        }
    }

    /**
     * Options for the Create/Edit select: eligible employees only, by name.
     *
     * @return list<array{id: int, name: string, is_active: bool, employee_number: string|null}>
     */
    public static function options(int $companyId): array
    {
        return self::eligible($companyId)
            ->select(['users.id', 'users.name'])
            ->addSelect(['employee_number' => EmployeeProfile::query()
                ->select('employee_profiles.employee_number')
                ->whereColumn('employee_profiles.user_id', 'users.id')
                ->withoutGlobalScope('company')
                ->where('employee_profiles.company_id', $companyId)
                ->limit(1)])
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'is_active' => true,
                'employee_number' => $user->getAttribute('employee_number'),
            ])
            ->all();
    }

    /**
     * Options for the index filter: the eligible employees plus everybody already credited on a
     * customer of the company, so former employees stay filterable (flagged inactive).
     *
     * @return list<array{id: int, name: string, is_active: bool}>
     */
    public static function filterOptions(int $companyId): array
    {
        return User::query()
            ->where('users.company_id', $companyId)
            ->where(fn (Builder $query) => $query
                ->whereIn('users.id', self::eligible($companyId)->select('users.id'))
                ->orWhereIn('users.id', self::creditedIds($companyId)))
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->get(['users.id', 'users.name', 'users.is_active'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'is_active' => (bool) $user->is_active,
            ])
            ->all();
    }

    /**
     * Users of the company credited on at least one customer, keyed by id (one query for exports).
     *
     * @return Collection<int, User>
     */
    public static function credited(int $companyId): Collection
    {
        return User::query()
            ->where('users.company_id', $companyId)
            ->whereIn('users.id', self::creditedIds($companyId))
            ->get(['users.id', 'users.name', 'users.is_active'])
            ->keyBy('id');
    }

    /** The creator is the default acquirer when eligible, otherwise the customer starts Unassigned. */
    public static function defaultId(User $actor): ?int
    {
        return self::isEligible((int) CompanyService::idFor($actor), $actor->id) ? $actor->id : null;
    }

    /**
     * @return Builder<Customer>
     */
    private static function creditedIds(int $companyId): Builder
    {
        return Customer::query()
            ->forCompany($companyId)
            ->whereNotNull('acquired_by_user_id')
            ->select('acquired_by_user_id');
    }
}
