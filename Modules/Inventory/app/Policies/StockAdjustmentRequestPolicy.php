<?php

namespace Modules\Inventory\Policies;

use App\Models\User;
use Modules\Inventory\Models\StockAdjustmentRequest;

class StockAdjustmentRequestPolicy
{
    public function approve(User $user, StockAdjustmentRequest $adjustment): bool
    {
        return $this->canReview($user, $adjustment);
    }

    public function reject(User $user, StockAdjustmentRequest $adjustment): bool
    {
        return $this->canReview($user, $adjustment);
    }

    /** Maker-checker: only another controller may review a still-pending request. */
    private function canReview(User $user, StockAdjustmentRequest $adjustment): bool
    {
        return $user->can('inventory.stock.adjust.approve')
            && $adjustment->status === 'pending'
            && (int) $adjustment->requested_by !== (int) $user->id;
    }
}
