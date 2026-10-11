<?php

namespace Modules\Inventory\Http\Requests;

/** Break-glass path: same payload as a request, gated by the admin-only direct permission. */
class StockAdjustDirectRequest extends StockAdjustRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.stock.adjust') ?? false;
    }
}
