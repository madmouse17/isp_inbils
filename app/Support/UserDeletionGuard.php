<?php

namespace App\Support;

use App\Models\User;
use App\Queries\Admin\CustomerAcquirerQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Models\StockAdjustmentRequest;

/**
 * Users referenced as actors by durable evidence cannot be hard-deleted (the foreign keys restrict it
 * too). Refuse with a validation error on the `user` key before anything is torn down.
 */
final class UserDeletionGuard
{
    /** @throws ValidationException */
    public static function ensureDeletable(User $user): void
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
