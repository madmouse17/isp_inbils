<?php

namespace Modules\Inventory\Services;

use App\Models\Core\Location;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Exceptions\InsufficientStockException;
use Modules\Inventory\Models\InventoryRequestItem;
use Modules\Inventory\Models\MaterialReturnLine;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Models\StockAdjustmentRequest;
use Modules\Inventory\Models\StockMovement;
use Modules\SPK\Models\WorkOrderItem;

class StockService
{
    public static function receive(int $productId, int $locationId, float $quantity, ?string $note = null, ?string $refType = null, ?int $refId = null): StockMovement
    {
        abort_if($quantity <= 0, 422, 'Jumlah penerimaan harus lebih dari 0.');
        self::assertProductLocation($productId, $locationId);

        return DB::transaction(function () use ($productId, $locationId, $quantity, $note, $refType, $refId) {
            self::assertProductLocation($productId, $locationId);
            self::assertReference($refType, $refId);
            if ($movement = self::existingMovement($productId, $locationId, 'receive', $refType, $refId)) {
                self::assertMovementMatches($movement, $quantity, $note);

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
        abort_if($quantity <= 0, 422, 'Jumlah pengeluaran harus lebih dari 0.');
        self::assertProductLocation($productId, $locationId);

        return DB::transaction(function () use ($productId, $locationId, $quantity, $note, $refType, $refId) {
            self::assertProductLocation($productId, $locationId);
            self::assertReference($refType, $refId);
            if ($movement = self::existingMovement($productId, $locationId, 'issue', $refType, $refId)) {
                self::assertMovementMatches($movement, $quantity, $note);

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
        abort_if($quantity <= 0, 422, 'Jumlah transfer harus lebih dari 0.');
        abort_if($fromLocationId === $toLocationId, 422, 'Lokasi asal dan tujuan harus berbeda.');
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

            AuditService::log('inventory', 'stock_transfer_out', [
                'product_id' => $productId,
                'from_location_id' => $fromLocationId,
                'to_location_id' => $toLocationId,
                'quantity' => $quantity,
            ]);
            AuditService::log('inventory', 'stock_transfer_in', [
                'product_id' => $productId,
                'from_location_id' => $fromLocationId,
                'to_location_id' => $toLocationId,
                'quantity' => $quantity,
            ]);

            return [$out, $in];
        });
    }

    /**
     * @param  array<string, mixed>  $evidence  Extra audit properties (maker-checker ids, break-glass marker).
     */
    public static function adjust(int $productId, int $locationId, float $newQuantity, string $note, array $evidence = []): StockMovement
    {
        abort_if(empty($note), 422, 'Catatan wajib diisi untuk penyesuaian stok.');
        abort_if($newQuantity < 0, 422, 'Jumlah tidak boleh negatif.');
        self::assertProductLocation($productId, $locationId);

        return DB::transaction(function () use ($productId, $locationId, $newQuantity, $note, $evidence) {
            self::assertProductLocation($productId, $locationId);
            $stock = self::lockedStock($productId, $locationId);
            if ($newQuantity < (float) $stock->reserved_quantity) {
                throw InsufficientStockException::forIssue((float) $stock->reserved_quantity, $newQuantity);
            }

            $delta = $newQuantity - (float) $stock->quantity;
            $oldQuantity = (float) $stock->quantity;

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

            AuditService::log('inventory', 'stock_adjustment', [
                'product_id' => $productId,
                'location_id' => $locationId,
                'old_quantity' => $oldQuantity,
                'new_quantity' => $newQuantity,
                'delta' => $delta,
                'note' => $note,
                ...$evidence,
            ]);

            return $movement;
        });
    }

    public static function requestAdjustment(int $productId, int $locationId, float $newQuantity, string $reason, ?int $requesterId = null): StockAdjustmentRequest
    {
        abort_if(empty($reason), 422, 'Alasan wajib diisi untuk permintaan penyesuaian stok.');
        abort_if($newQuantity < 0, 422, 'Jumlah tidak boleh negatif.');
        self::assertProductLocation($productId, $locationId);

        $requesterId = $requesterId ?? Auth::id();
        abort_if($requesterId === null, 401, 'Anda belum masuk.');

        $companyId = self::companyId();
        // Read-only snapshot: a request must not create or lock stock rows; approval re-reads under lock.
        $oldQuantity = (float) Stock::withoutCompany()
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->value('quantity');

        return DB::transaction(function () use ($companyId, $productId, $locationId, $newQuantity, $reason, $requesterId, $oldQuantity) {
            $adjustmentRequest = StockAdjustmentRequest::create([
                'company_id' => $companyId,
                'product_id' => $productId,
                'location_id' => $locationId,
                'old_quantity' => $oldQuantity,
                'new_quantity' => $newQuantity,
                'delta' => $newQuantity - $oldQuantity,
                'reason' => $reason,
                'status' => 'pending',
                'requested_by' => $requesterId,
            ]);

            AuditService::log('inventory', 'stock_adjustment_requested', [
                'product_id' => $productId,
                'location_id' => $locationId,
                'old_quantity' => $oldQuantity,
                'new_quantity' => $newQuantity,
                'reason' => $reason,
                'requested_by' => $requesterId,
            ], $adjustmentRequest);

            return $adjustmentRequest;
        });
    }

    public static function approveAdjustment(StockAdjustmentRequest $adjustmentRequest, ?int $approverId = null): StockMovement
    {
        $approverId = $approverId ?? Auth::id();
        abort_if($approverId === null, 401, 'Anda belum masuk.');

        return DB::transaction(function () use ($adjustmentRequest, $approverId) {
            $pending = self::lockedPendingAdjustment($adjustmentRequest, (int) $approverId, 'approve');

            $movement = self::adjust(
                $pending->product_id,
                $pending->location_id,
                (float) $pending->new_quantity,
                $pending->reason,
                [
                    'adjustment_request_id' => $pending->id,
                    'requested_by' => $pending->requested_by,
                    'approved_by' => $approverId,
                ],
            );

            $pending->update([
                'status' => 'approved',
                'approved_by' => $approverId,
                'approved_at' => now(),
            ]);

            return $movement;
        });
    }

    public static function rejectAdjustment(StockAdjustmentRequest $adjustmentRequest, string $rejectionReason, ?int $rejecterId = null): StockAdjustmentRequest
    {
        $rejecterId = $rejecterId ?? Auth::id();
        abort_if($rejecterId === null, 401, 'Anda belum masuk.');
        abort_if(empty($rejectionReason), 422, 'Alasan penolakan wajib diisi.');

        return DB::transaction(function () use ($adjustmentRequest, $rejectionReason, $rejecterId) {
            $pending = self::lockedPendingAdjustment($adjustmentRequest, (int) $rejecterId, 'reject');

            // shortcut: approved_by/approved_at double as reviewer and time for rejections (status tells which); split into rejected_by/rejected_at if reports need them apart.
            $pending->update([
                'status' => 'rejected',
                'approved_by' => $rejecterId,
                'approved_at' => now(),
                'rejection_reason' => $rejectionReason,
            ]);

            AuditService::log('inventory', 'stock_adjustment_rejected', [
                'product_id' => $pending->product_id,
                'location_id' => $pending->location_id,
                'requested_by' => $pending->requested_by,
                'rejected_by' => $rejecterId,
                'rejection_reason' => $rejectionReason,
            ], $pending);

            return $pending;
        });
    }

    /** Re-read under lock so concurrent approve/reject cannot both pass the pending check. */
    private static function lockedPendingAdjustment(StockAdjustmentRequest $adjustmentRequest, int $actorId, string $verb): StockAdjustmentRequest
    {
        $locked = StockAdjustmentRequest::query()->whereKey($adjustmentRequest->getKey())->lockForUpdate()->firstOrFail();

        abort_if($locked->status !== 'pending', 422, 'Permintaan penyesuaian stok tidak berstatus menunggu.');
        abort_if((int) $locked->requested_by === $actorId, 422, 'Maker-checker: pemohon tidak boleh '.($verb === 'approve' ? 'menyetujui' : 'menolak').' permintaan penyesuaian stoknya sendiri.');

        return $locked;
    }

    public static function reserve(int $productId, int $locationId, float $quantity, ?string $refType = null, ?int $refId = null): StockMovement
    {
        abort_if($quantity <= 0, 422, 'Jumlah reservasi harus lebih dari 0.');
        self::assertProductLocation($productId, $locationId);

        return DB::transaction(function () use ($productId, $locationId, $quantity, $refType, $refId) {
            self::assertProductLocation($productId, $locationId);
            self::assertReference($refType, $refId);
            if ($movement = self::existingMovement($productId, $locationId, 'reserve', $refType, $refId)) {
                self::assertMovementMatches($movement, $quantity, null);

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
        abort_if($quantity <= 0, 422, 'Jumlah pelepasan reservasi harus lebih dari 0.');
        self::assertProductLocation($productId, $locationId);

        return DB::transaction(function () use ($productId, $locationId, $quantity, $refType, $refId) {
            self::assertProductLocation($productId, $locationId);
            self::assertReference($refType, $refId);
            if ($movement = self::existingMovement($productId, $locationId, 'release', $refType, $refId)) {
                self::assertMovementMatches($movement, $quantity, null);

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
        abort_if($quantity <= 0, 422, 'Jumlah pengembalian harus lebih dari 0.');
        self::assertProductLocation($productId, $locationId);

        return DB::transaction(function () use ($productId, $locationId, $quantity, $note, $refType, $refId) {
            self::assertProductLocation($productId, $locationId);
            self::assertReference($refType, $refId);
            if ($movement = self::existingMovement($productId, $locationId, 'return', $refType, $refId)) {
                self::assertMovementMatches($movement, $quantity, $note);

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

    public static function stockMovement(
        int $productId,
        ?int $fromLocationId,
        ?int $toLocationId,
        string $movementType,
        float $quantity,
        ?string $note = null,
        ?string $refType = null,
        ?int $refId = null
    ): StockMovement {
        abort_if($quantity <= 0, 422, 'Jumlah mutasi stok harus lebih dari 0.');
        abort_if($fromLocationId === null && $toLocationId === null, 422, 'Isi setidaknya satu lokasi.');
        abort_if($fromLocationId !== null && $fromLocationId === $toLocationId, 422, 'Lokasi asal dan tujuan harus berbeda.');

        $signedQuantity = ($fromLocationId !== null && $toLocationId === null) ? -$quantity : $quantity;

        return DB::transaction(function () use (
            $productId,
            $fromLocationId,
            $toLocationId,
            $movementType,
            $quantity,
            $signedQuantity,
            $note,
            $refType,
            $refId
        ) {
            if ($fromLocationId !== null) {
                self::assertProductLocation($productId, $fromLocationId);
            }
            if ($toLocationId !== null) {
                self::assertProductLocation($productId, $toLocationId);
            }
            self::assertReference($refType, $refId);

            $primaryLocationId = $toLocationId ?? $fromLocationId;
            if ($movement = self::existingMovement($productId, $primaryLocationId, $movementType, $refType, $refId)) {
                self::assertMovementMatches($movement, $quantity, $note);

                return $movement;
            }

            $locIds = array_values(array_filter([$fromLocationId, $toLocationId]));
            sort($locIds);

            $stocks = self::lockedStocks($productId, $locIds);

            $balanceAfter = 0.0;
            $reservedAfter = 0.0;

            if ($fromLocationId !== null) {
                $fromStock = $stocks[$fromLocationId];
                $available = (float) $fromStock->quantity - (float) $fromStock->reserved_quantity;
                if ($available < $quantity) {
                    throw InsufficientStockException::forIssue($quantity, $available);
                }
                $fromStock->quantity -= $quantity;
                $fromStock->save();
                $balanceAfter = (float) $fromStock->quantity;
                $reservedAfter = (float) $fromStock->reserved_quantity;
            }

            if ($toLocationId !== null) {
                $toStock = $stocks[$toLocationId];
                $toStock->quantity += $quantity;
                $toStock->save();
                $balanceAfter = (float) $toStock->quantity;
                $reservedAfter = (float) $toStock->reserved_quantity;
            }

            $movement = StockMovement::create([
                'product_id' => $productId,
                'from_location_id' => $fromLocationId,
                'to_location_id' => $toLocationId,
                'movement_type' => $movementType,
                'quantity' => $signedQuantity,
                'balance_after' => $balanceAfter,
                'reserved_after' => $reservedAfter,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'note' => $note,
                'created_by' => Auth::id(),
            ]);

            AuditService::log('inventory', 'stock_movement', [
                'product_id' => $productId,
                'from_location_id' => $fromLocationId,
                'to_location_id' => $toLocationId,
                'movement_type' => $movementType,
                'quantity' => $quantity,
            ]);

            return $movement;
        });
    }

    private static function lockedStock(int $productId, int $locationId): Stock
    {
        $companyId = self::companyId();
        self::ensureStock($productId, $locationId);

        return Stock::withoutCompany()
            ->where('company_id', $companyId)
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
        $companyId = self::companyId();
        foreach ($locationIds as $locationId) {
            self::ensureStock($productId, $locationId);
        }

        return Stock::withoutCompany()
            ->where('company_id', $companyId)
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
        $companyId = self::companyId();
        if (Stock::withoutCompany()->where('company_id', $companyId)->where('product_id', $productId)->where('location_id', $locationId)->exists()) {
            return;
        }

        try {
            Stock::withoutCompany()->firstOrCreate(
                ['company_id' => $companyId, 'product_id' => $productId, 'location_id' => $locationId],
                ['quantity' => 0, 'reserved_quantity' => 0]
            );
        } catch (QueryException) {
            // Concurrent insert won unique race; next locked read owns row.
        }
    }

    private static function assertMovementMatches(StockMovement $movement, float $quantity, ?string $note): void
    {
        $existingQty = abs((float) $movement->quantity);
        if (abs($existingQty - $quantity) > 0.0001) {
            abort(409, "Konflik idempotensi: mutasi stok sudah ada dengan jumlah {$existingQty}, tetapi permintaan ulang meminta jumlah {$quantity}.");
        }

        $existingNote = $movement->note !== null ? trim($movement->note) : '';
        $incomingNote = $note !== null ? trim($note) : '';
        if ($existingNote !== $incomingNote) {
            abort(409, 'Konflik idempotensi: mutasi stok sudah ada dengan catatan yang berbeda.');
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

        abort_if($refType === null || $refId === null, 422, 'Jenis dan ID referensi stok harus diisi bersamaan.');

        $typeMap = self::referenceTypes();
        abort_unless(isset($typeMap[$refType]), 422, 'Jenis referensi stok tidak diizinkan.');

        /** @var mixed $modelClass */
        $modelClass = $typeMap[$refType];
        abort_unless(
            (bool) $modelClass::withoutCompany()->whereKey($refId)->where('company_id', self::companyId())->lockForUpdate()->first(),
            422,
            'Referensi stok tidak valid untuk perusahaan ini.'
        );
    }

    /** @return array<string, class-string<Model>> */
    private static function referenceTypes(): array
    {
        $woType = (new WorkOrderItem())->getMorphClass();
        $irItemType = (new InventoryRequestItem())->getMorphClass();
        $mrLineType = (new MaterialReturnLine())->getMorphClass();

        return array_unique([
            WorkOrderItem::class => WorkOrderItem::class,
            $woType => WorkOrderItem::class,
            InventoryRequestItem::class => InventoryRequestItem::class,
            $irItemType => InventoryRequestItem::class,
            MaterialReturnLine::class => MaterialReturnLine::class,
            $mrLineType => MaterialReturnLine::class,
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

        abort_unless((bool) Product::withoutCompany()->whereKey($productId)->where('company_id', $companyId)->lockForUpdate()->first(), 422, 'Barang tidak valid untuk perusahaan ini.');
        abort_unless((bool) Location::withoutCompany()->whereKey($locationId)->where('company_id', $companyId)->lockForUpdate()->first(), 422, 'Lokasi tidak valid untuk perusahaan ini.');
    }
}
