<?php

namespace Modules\Inventory\Services;

use App\Models\Core\Location;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Exceptions\InsufficientStockException;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Models\StockMovement;
use Modules\SPK\Models\WorkOrderItem;

class StockService
{
    public static function receive(int $productId, int $locationId, float $quantity, ?string $note = null, ?string $refType = null, ?int $refId = null): StockMovement
    {
        abort_if($quantity <= 0, 422, 'Quantity must be positive for receive.');
        self::assertProductLocation($productId, $locationId);

        return DB::transaction(function () use ($productId, $locationId, $quantity, $note, $refType, $refId) {
            self::assertProductLocation($productId, $locationId);
            self::assertReference($refType, $refId);
            if ($movement = self::existingMovement($productId, $locationId, 'receive', $refType, $refId)) {
                return $movement;
            }

            $stock = self::lockedStock($productId, $locationId);
            $stock->quantity += $quantity;
            $stock->save();

            $movement = StockMovement::create([
                'product_id' => $productId,
                'from_location_id' => null,
                'to_location_id' => $locationId,
                'movement_type' => 'receive',
                'quantity' => $quantity,
                'balance_after' => $stock->quantity,
                'reserved_after' => $stock->reserved_quantity,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'note' => $note,
                'created_by' => Auth::id(),
            ]);

            AuditService::log('inventory', 'stock_received', [
                'product_id' => $productId,
                'location_id' => $locationId,
                'quantity' => $quantity,
            ]);

            return $movement;
        });
    }

    public static function issue(int $productId, int $locationId, float $quantity, ?string $note = null, ?string $refType = null, ?int $refId = null): StockMovement
    {
        abort_if($quantity <= 0, 422, 'Quantity must be positive for issue.');
        self::assertProductLocation($productId, $locationId);

        return DB::transaction(function () use ($productId, $locationId, $quantity, $note, $refType, $refId) {
            self::assertProductLocation($productId, $locationId);
            self::assertReference($refType, $refId);
            if ($movement = self::existingMovement($productId, $locationId, 'issue', $refType, $refId)) {
                return $movement;
            }

            $stock = self::lockedStock($productId, $locationId);
            $available = (float) $stock->quantity - (float) $stock->reserved_quantity;

            if ($available < $quantity) {
                throw InsufficientStockException::forIssue($quantity, $available);
            }

            $stock->quantity -= $quantity;
            $stock->save();

            $movement = StockMovement::create([
                'product_id' => $productId,
                'from_location_id' => $locationId,
                'to_location_id' => null,
                'movement_type' => 'issue',
                'quantity' => -$quantity,
                'balance_after' => $stock->quantity,
                'reserved_after' => $stock->reserved_quantity,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'note' => $note,
                'created_by' => Auth::id(),
            ]);

            AuditService::log('inventory', 'stock_issued', [
                'product_id' => $productId,
                'location_id' => $locationId,
                'quantity' => $quantity,
            ]);

            return $movement;
        });
    }

    public static function transfer(int $productId, int $fromLocationId, int $toLocationId, float $quantity, ?string $note = null): array
    {
        abort_if($quantity <= 0, 422, 'Quantity must be positive for transfer.');
        abort_if($fromLocationId === $toLocationId, 422, 'From and to locations must differ.');
        self::assertProductLocation($productId, $fromLocationId);
        self::assertProductLocation($productId, $toLocationId);

        return DB::transaction(function () use ($productId, $fromLocationId, $toLocationId, $quantity, $note) {
            self::assertProductLocation($productId, $fromLocationId);
            self::assertProductLocation($productId, $toLocationId);
            $stocks = self::lockedStocks($productId, [$fromLocationId, $toLocationId]);
            $fromStock = $stocks[$fromLocationId];
            $toStock = $stocks[$toLocationId];

            $available = (float) $fromStock->quantity - (float) $fromStock->reserved_quantity;

            if ($available < $quantity) {
                throw InsufficientStockException::forIssue($quantity, $available);
            }

            $fromStock->quantity -= $quantity;
            $fromStock->save();

            $toStock->quantity += $quantity;
            $toStock->save();

            $out = StockMovement::create([
                'product_id' => $productId,
                'from_location_id' => $fromLocationId,
                'to_location_id' => null,
                'movement_type' => 'transfer',
                'quantity' => -$quantity,
                'balance_after' => $fromStock->quantity,
                'reserved_after' => $fromStock->reserved_quantity,
                'note' => $note,
                'created_by' => Auth::id(),
            ]);

            $in = StockMovement::create([
                'product_id' => $productId,
                'from_location_id' => null,
                'to_location_id' => $toLocationId,
                'movement_type' => 'transfer',
                'quantity' => $quantity,
                'balance_after' => $toStock->quantity,
                'reserved_after' => $toStock->reserved_quantity,
                'note' => $note,
                'created_by' => Auth::id(),
            ]);

            return [$out, $in];
        });
    }

    public static function adjust(int $productId, int $locationId, float $newQuantity, string $note): StockMovement
    {
        abort_if(empty($note), 422, 'Note is required for adjustment.');
        abort_if($newQuantity < 0, 422, 'Quantity cannot be negative.');
        self::assertProductLocation($productId, $locationId);

        return DB::transaction(function () use ($productId, $locationId, $newQuantity, $note) {
            self::assertProductLocation($productId, $locationId);
            $stock = self::lockedStock($productId, $locationId);
            if ($newQuantity < (float) $stock->reserved_quantity) {
                throw InsufficientStockException::forIssue((float) $stock->reserved_quantity, $newQuantity);
            }

            $delta = $newQuantity - (float) $stock->quantity;

            $stock->quantity = $newQuantity;
            $stock->save();

            $movement = StockMovement::create([
                'product_id' => $productId,
                'from_location_id' => $locationId,
                'to_location_id' => $locationId,
                'movement_type' => 'adjustment',
                'quantity' => $delta,
                'balance_after' => $stock->quantity,
                'reserved_after' => $stock->reserved_quantity,
                'note' => $note,
                'created_by' => Auth::id(),
            ]);

            AuditService::log('inventory', 'stock_adjusted', [
                'product_id' => $productId,
                'location_id' => $locationId,
                'new_quantity' => $newQuantity,
                'delta' => $delta,
            ]);

            return $movement;
        });
    }

    public static function reserve(int $productId, int $locationId, float $quantity, ?string $refType = null, ?int $refId = null): StockMovement
    {
        abort_if($quantity <= 0, 422, 'Quantity must be positive for reserve.');
        self::assertProductLocation($productId, $locationId);

        return DB::transaction(function () use ($productId, $locationId, $quantity, $refType, $refId) {
            self::assertProductLocation($productId, $locationId);
            self::assertReference($refType, $refId);
            if ($movement = self::existingMovement($productId, $locationId, 'reserve', $refType, $refId)) {
                return $movement;
            }

            $stock = self::lockedStock($productId, $locationId);
            $available = (float) $stock->quantity - (float) $stock->reserved_quantity;

            if ($available < $quantity) {
                throw InsufficientStockException::forReserve($quantity, $available);
            }

            $stock->reserved_quantity += $quantity;
            $stock->save();

            return StockMovement::create([
                'product_id' => $productId,
                'from_location_id' => null,
                'to_location_id' => $locationId,
                'movement_type' => 'reserve',
                'quantity' => $quantity,
                'balance_after' => $stock->quantity,
                'reserved_after' => $stock->reserved_quantity,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'created_by' => Auth::id(),
            ]);
        });
    }

    public static function release(int $productId, int $locationId, float $quantity, ?string $refType = null, ?int $refId = null): StockMovement
    {
        abort_if($quantity <= 0, 422, 'Quantity must be positive for release.');
        self::assertProductLocation($productId, $locationId);

        return DB::transaction(function () use ($productId, $locationId, $quantity, $refType, $refId) {
            self::assertProductLocation($productId, $locationId);
            self::assertReference($refType, $refId);
            if ($movement = self::existingMovement($productId, $locationId, 'release', $refType, $refId)) {
                return $movement;
            }

            $stock = self::lockedStock($productId, $locationId);
            if ((float) $stock->reserved_quantity < $quantity) {
                throw InsufficientStockException::forReserve($quantity, (float) $stock->reserved_quantity);
            }

            $stock->reserved_quantity -= $quantity;
            $stock->save();

            return StockMovement::create([
                'product_id' => $productId,
                'from_location_id' => null,
                'to_location_id' => $locationId,
                'movement_type' => 'release',
                'quantity' => -$quantity,
                'balance_after' => $stock->quantity,
                'reserved_after' => $stock->reserved_quantity,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'created_by' => Auth::id(),
            ]);
        });
    }

    public static function returnStock(int $productId, int $locationId, float $quantity, ?string $note = null, ?string $refType = null, ?int $refId = null): StockMovement
    {
        abort_if($quantity <= 0, 422, 'Quantity must be positive for return.');
        self::assertProductLocation($productId, $locationId);

        return DB::transaction(function () use ($productId, $locationId, $quantity, $note, $refType, $refId) {
            self::assertProductLocation($productId, $locationId);
            self::assertReference($refType, $refId);
            if ($movement = self::existingMovement($productId, $locationId, 'return', $refType, $refId)) {
                return $movement;
            }

            $stock = self::lockedStock($productId, $locationId);
            $stock->quantity += $quantity;
            $stock->save();

            return StockMovement::create([
                'product_id' => $productId,
                'from_location_id' => null,
                'to_location_id' => $locationId,
                'movement_type' => 'return',
                'quantity' => $quantity,
                'balance_after' => $stock->quantity,
                'reserved_after' => $stock->reserved_quantity,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'note' => $note,
                'created_by' => Auth::id(),
            ]);
        });
    }

    private static function lockedStock(int $productId, int $locationId): Stock
    {
        self::ensureStock($productId, $locationId);

        return Stock::query()
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @return array<int, Stock>
     */
    private static function lockedStocks(int $productId, array $locationIds): array
    {
        foreach ($locationIds as $locationId) {
            self::ensureStock($productId, $locationId);
        }

        return Stock::query()
            ->where('product_id', $productId)
            ->whereIn('location_id', $locationIds)
            ->orderBy('location_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('location_id')
            ->all();
    }

    private static function ensureStock(int $productId, int $locationId): void
    {
        try {
            Stock::query()->firstOrCreate(
                ['product_id' => $productId, 'location_id' => $locationId],
                ['quantity' => 0, 'reserved_quantity' => 0]
            );
        } catch (QueryException) {
            // Concurrent insert won unique race; next locked read owns row.
        }
    }

    private static function existingMovement(int $productId, int $locationId, string $movementType, ?string $refType, ?int $refId): ?StockMovement
    {
        if ($refType === null || $refId === null) {
            return null;
        }

        return StockMovement::query()
            ->where('product_id', $productId)
            ->where(function ($query) use ($locationId) {
                $query->where('from_location_id', $locationId)
                    ->orWhere('to_location_id', $locationId);
            })
            ->where('movement_type', $movementType)
            ->where('reference_type', $refType)
            ->where('reference_id', $refId)
            ->lockForUpdate()
            ->first();
    }

    private static function assertReference(?string $refType, ?int $refId): void
    {
        if ($refType === null && $refId === null) {
            return;
        }

        abort_if($refType === null || $refId === null, 422, 'Stock reference type and id are required together.');

        $typeMap = self::referenceTypes();
        abort_unless(isset($typeMap[$refType]), 422, 'Stock reference type is not allowed.');

        $modelClass = $typeMap[$refType];
        abort_unless(
            $modelClass::withoutCompany()->whereKey($refId)->where('company_id', self::companyId())->lockForUpdate()->first(),
            422,
            'Stock reference is invalid for this company.'
        );
    }

    /** @return array<string, class-string<WorkOrderItem>> */
    private static function referenceTypes(): array
    {
        $type = (new WorkOrderItem())->getMorphClass();

        return array_unique([
            WorkOrderItem::class => WorkOrderItem::class,
            $type => WorkOrderItem::class,
        ]);
    }

    private static function companyId(): int
    {
        $companyId = CompanyService::currentId();
        abort_if($companyId === null, 403, 'Company context is required.');

        return $companyId;
    }

    private static function assertProductLocation(int $productId, int $locationId): void
    {
        $companyId = self::companyId();

        abort_unless(Product::withoutCompany()->whereKey($productId)->where('company_id', $companyId)->lockForUpdate()->first(), 422, 'Invalid product for this company.');
        abort_unless(Location::withoutCompany()->whereKey($locationId)->where('company_id', $companyId)->lockForUpdate()->first(), 422, 'Invalid location for this company.');
    }
}
