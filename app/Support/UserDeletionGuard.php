<?php

namespace App\Support;

use App\Models\User;
use App\Queries\Admin\CustomerAcquirerQuery;
use App\Services\Core\CompanyRoleService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Models\StockAdjustmentRequest;

/**
 * Users referenced as actors by durable evidence cannot be hard-deleted (the foreign keys restrict it
 * too), and neither can the last administrator of a company he belongs to. Refuse with a validation error on the
 * `user` key before anything is torn down.
 */
final class UserDeletionGuard
{
    /**
     * Deletes the user, or refuses. The check of the last administrator (G5) and the delete are one transaction that
     * holds the locks of every company of the user (CompanyRoleService::guardingAdministratorsOf), so two
     * administrators deleting each other cannot both pass. Every deletion goes through here: the user screen and the
     * profile page.
     *
     * @param  Closure(): void|null  $beforeDelete  runs once the user may go, before the row does (the profile page signs out there)
     *
     * @throws ValidationException
     */
    public static function delete(User $user, ?Closure $beforeDelete = null): void
    {
        self::ensureNoEvidence($user);

        app(CompanyRoleService::class)->guardingAdministratorsOf($user, 'user', function () use ($user, $beforeDelete): void {
            if ($beforeDelete !== null) {
                $beforeDelete();
            }

            $user->delete();
        });
    }

    /**
     * Read before the transaction of delete() starts, in autocommit: a read inside it would fix the snapshot ahead of
     * the company locks. A row that appears in between is still refused, by the foreign keys.
     *
     * @throws ValidationException
     */
    private static function ensureNoEvidence(User $user): void
    {
        CustomerAcquirerQuery::ensureUserDeletable($user);

        $isAdjustmentActor = StockAdjustmentRequest::withoutCompany()
            ->where(fn (Builder $query) => $query->where('requested_by', $user->id)->orWhere('approved_by', $user->id))
            ->exists();

        if ($isAdjustmentActor) {
            throw ValidationException::withMessages([
                'user' => 'Pengguna yang pernah mengajukan atau memeriksa penyesuaian stok tidak bisa dihapus. Nonaktifkan akunnya.',
            ]);
        }
    }
}
