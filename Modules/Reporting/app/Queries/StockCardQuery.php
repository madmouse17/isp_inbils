<?php

namespace Modules\Reporting\Queries;

use Modules\Inventory\Models\StockMovement;

class StockCardQuery
{
    public static function execute(int $productId, ?int $locationId = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $query = StockMovement::query()->where('product_id', $productId);
        if ($locationId) $query->where(function ($q) use ($locationId) {
            $q->where('from_location_id', $locationId)->orWhere('to_location_id', $locationId);
        });
        if ($dateFrom && $dateTo) $query->whereBetween('created_at', [$dateFrom, $dateTo]);

        $movements = $query->with(['product', 'fromLocation', 'toLocation'])
            ->orderBy('created_at', 'desc')->get()->map(fn ($m) => [
                'id' => $m->id,
                'movement_type' => $m->movement_type,
                'quantity' => $m->quantity,
                'balance_after' => $m->balance_after,
                'from_location' => $m->fromLocation?->name,
                'to_location' => $m->toLocation?->name,
                'note' => $m->note,
                'created_at' => $m->created_at,
            ])->toArray();

        return ['product_id' => $productId, 'movements' => $movements];
    }
}
