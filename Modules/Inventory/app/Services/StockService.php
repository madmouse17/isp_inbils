<?php

namespace Modules\Inventory\Services;

use App\Models\Core\Location;
use App\Services\Core\AuditService;
use Modules\Inventory\Exceptions\InsufficientStockException;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class StockService
{
    public static function receive(int $productId, int $locationId, float $quantity, ?string $note = null, ?string $refType = null, ?int $refId = null): StockMovement
    {
        abort_if($quantity <= 0, 422, 'Quantity must be positive for receive.');

        return DB::transaction(function () use ($productId, $locationId, $quantity, $note, $refType, $refId) {
            $stock = self::getOrCreateStock($productId, $locationId);
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

        return DB::transaction(function () use ($productId, $locationId, $quantity, $note, $refType, $refId) {
            $stock = self::getOrCreateStock($productId, $locationId);
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

        return DB::transaction(function () use ($productId, $fromLocationId, $toLocationId, $quantity, $note) {
            $fromStock = self::getOrCreateStock($productId, $fromLocationId);
            $toStock = self::getOrCreateStock($productId, $toLocationId);

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

        return DB::transaction(function () use ($productId, $locationId, $newQuantity, $note) {
            $stock = self::getOrCreateStock($productId, $locationId);
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

        return DB::transaction(function () use ($productId, $locationId, $quantity, $refType, $refId) {
            $stock = self::getOrCreateStock($productId, $locationId);
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

        return DB::transaction(function () use ($productId, $locationId, $quantity, $refType, $refId) {
            $stock = self::getOrCreateStock($productId, $locationId);
            $stock->reserved_quantity = max(0, (float) $stock->reserved_quantity - $quantity);
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

        return DB::transaction(function () use ($productId, $locationId, $quantity, $note, $refType, $refId) {
            $stock = self::getOrCreateStock($productId, $locationId);
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

    private static function getOrCreateStock(int $productId, int $locationId): Stock
    {
        return Stock::firstOrCreate(
            ['product_id' => $productId, 'location_id' => $locationId],
            ['quantity' => 0, 'reserved_quantity' => 0]
        );
    }
}
