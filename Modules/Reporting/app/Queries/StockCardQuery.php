<?php

namespace Modules\Reporting\Queries;

use Illuminate\Database\Eloquent\Builder;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockMovement;
use Modules\Reporting\Queries\Concerns\AppliesDateRange;

class StockCardQuery
{
    use AppliesDateRange;

    public static function execute(
        int $companyId,
        int $productId,
        ?int $locationId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        string $sortBy = 'created_at',
        string $sortDir = 'desc',
        int $perPage = 25,
    ): array {
        $movements = self::ledger($companyId, $productId, $locationId, $dateFrom, $dateTo, $sortBy, $sortDir)
            ->with(['product', 'fromLocation', 'toLocation'])
            ->paginate($perPage)
            ->withQueryString();

        return ['product_id' => $productId, 'movements' => $movements];
    }

    /**
     * The ledger of one product for the filters, in display order: the rows the page pages through
     * and the export streams. The product must belong to the company (a soft-deleted one does).
     *
     * @return Builder<StockMovement>
     */
    public static function ledger(
        int $companyId,
        int $productId,
        ?int $locationId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        string $sortBy = 'created_at',
        string $sortDir = 'desc',
    ): Builder {
        Product::withTrashed()->where('company_id', $companyId)->findOrFail($productId);

        $query = StockMovement::query()
            ->where('company_id', $companyId)
            ->where('product_id', $productId);

        if ($locationId) {
            $query->where(function ($q) use ($locationId) {
                $q->where('from_location_id', $locationId)->orWhere('to_location_id', $locationId);
            });
        }

        self::applyDateRange($query, 'created_at', $dateFrom, $dateTo);

        $sortColumn = match ($sortBy) {
            'quantity' => 'quantity',
            'balance_after' => 'balance_after',
            default => 'created_at',
        };
        $direction = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sortColumn, $direction)->orderBy('id', $direction);
    }
}
