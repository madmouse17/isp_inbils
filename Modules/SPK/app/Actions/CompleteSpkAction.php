<?php

namespace Modules\SPK\Actions;

use App\Actions\CreateInvoiceFromSpkAction;
use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Services\Core\AuditService;
use App\Services\Core\SettingService;
use App\Services\Core\SubscriptionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\Inventory\Models\MaterialReturnLine;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Services\StockService;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Services\NetworkAssetService;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderItem;

class CompleteSpkAction
{
    public static function execute(WorkOrder $workOrder): WorkOrder
    {
        return DB::transaction(function () use ($workOrder) {
            $workOrder = WorkOrder::with(['media', 'items.product', 'items.networkAsset.product', 'subscription.customer', 'customer'])
                ->lockForUpdate()
                ->findOrFail($workOrder->id);

            abort_if($workOrder->getMedia('evidence')->isEmpty(), 422, 'Evidence required before completion.');

            self::reconcileUsedQuantitiesFromMaterialReturns($workOrder);
            $workOrder->load(['items.product', 'items.networkAsset.product']);

            self::consumeStock($workOrder);
            self::installAsset($workOrder);
            self::activateSubscription($workOrder);
            self::createInvoice($workOrder);

            AuditService::log('work_order', 'completed_orchestrated', [
                'code' => $workOrder->code,
                'items' => $workOrder->items->count(),
                'subscription_id' => $workOrder->subscription_id,
            ], $workOrder);

            return $workOrder->fresh(['items', 'subscription']);
        });
    }

    public static function releaseReservedStock(WorkOrder $workOrder): void
    {
        DB::transaction(function () use ($workOrder) {
            $workOrder = WorkOrder::with('items')
                ->lockForUpdate()
                ->findOrFail($workOrder->id);

            foreach ($workOrder->items as $item) {
                $reserved = (float) $item->quantity_reserved;

                if ($reserved <= 0 || ! $workOrder->location_id) {
                    continue;
                }

                StockService::release(
                    $item->product_id,
                    $workOrder->location_id,
                    $reserved,
                    WorkOrderItem::class,
                    $item->id
                );

                $item->update(['quantity_reserved' => 0]);

                AuditService::log('work_order', 'reserved_stock_released', [
                    'product_id' => $item->product_id,
                    'quantity' => $reserved,
                ], $workOrder);
            }
        });
    }

    public static function reconcileUsedQuantitiesFromMaterialReturns(WorkOrder $workOrder): void
    {
        // Find all material return cases for this work order
        $caseIds = MaterialReturnCase::withoutCompany()
            ->where('company_id', $workOrder->company_id)
            ->where(function ($q) use ($workOrder) {
                $q->where('work_order_id', $workOrder->id)
                    ->orWhereIn('inventory_request_id', function ($sub) use ($workOrder) {
                        $sub->select('id')
                            ->from('inventory_requests')
                            ->where('company_id', $workOrder->company_id)
                            ->where('source_type', 'work_order')
                            ->where('source_id', $workOrder->id);
                    });
            })
            ->whereIn('status', ['reconciled', 'resolved'])
            ->pluck('id');

        if ($caseIds->isEmpty()) {
            return;
        }

        $lineStats = MaterialReturnLine::withoutCompany()
            ->where('company_id', $workOrder->company_id)
            ->whereIn('material_return_case_id', $caseIds)
            ->groupBy('product_id')
            ->select(
                'product_id',
                DB::raw('SUM(issued_qty) as total_issued'),
                DB::raw('SUM(actual_used_qty) as total_actual_used')
            )
            ->get()
            ->keyBy('product_id');

        foreach ($lineStats as $productId => $stat) {
            /** @var object{total_issued: float|int|string, total_actual_used: float|int|string} $stat */
            $totalIssued = (float) $stat->total_issued;
            $totalActualUsed = (float) $stat->total_actual_used;

            /** @var Collection<int, WorkOrderItem> $items */
            $items = $workOrder->items()->where('product_id', $productId)->orderBy('id')->get();

            if ($items->isEmpty()) {
                if ($totalActualUsed > 0) {
                    WorkOrderItem::create([
                        'company_id' => $workOrder->company_id,
                        'work_order_id' => $workOrder->id,
                        'product_id' => $productId,
                        'quantity_used' => $totalActualUsed,
                        'quantity_reserved' => 0,
                    ]);
                }

                continue;
            }

            $currentTotalUsed = (float) $items->sum('quantity_used');
            $legacyPortion = max(0.0, $currentTotalUsed - $totalIssued);
            $targetTotalUsed = $legacyPortion + $totalActualUsed;

            if ($items->count() === 1) {
                $items->first()->update(['quantity_used' => $targetTotalUsed]);
            } else {
                $remaining = $targetTotalUsed;
                foreach ($items as $index => $item) {
                    /** @var WorkOrderItem $item */
                    if ($index === $items->count() - 1) {
                        $item->update(['quantity_used' => $remaining]);
                    } else {
                        $target = (float) $item->quantity_reserved > 0
                            ? (float) $item->quantity_reserved
                            : ((float) $item->quantity_used > 0 ? (float) $item->quantity_used : $remaining);
                        $alloc = min($target, $remaining);
                        $item->update(['quantity_used' => $alloc]);
                        $remaining -= $alloc;
                    }
                }
            }
        }
    }

    private static function consumeStock(WorkOrder $workOrder): void
    {
        // Check if this work order was backed by issued Inventory Request(s).
        $issuedIrCoverage = DB::table('inventory_requests')
            ->join('inventory_request_items', 'inventory_requests.id', '=', 'inventory_request_items.inventory_request_id')
            ->where('inventory_requests.company_id', $workOrder->company_id)
            ->where('inventory_requests.source_type', 'work_order')
            ->where('inventory_requests.source_id', $workOrder->id)
            ->where('inventory_requests.status', 'issued')
            ->where('inventory_request_items.quantity_issued', '>', 0)
            ->groupBy('inventory_request_items.product_id')
            ->select('inventory_request_items.product_id', DB::raw('SUM(inventory_request_items.quantity_issued) as total_issued'))
            ->pluck('total_issued', 'product_id')
            ->map(fn ($qty) => (float) $qty)
            ->all();

        $remainingIrCoverage = $issuedIrCoverage;

        foreach ($workOrder->items as $item) {
            $reserved = (float) $item->quantity_reserved;
            if ($reserved > 0 && $workOrder->location_id) {
                $stock = Stock::where('product_id', $item->product_id)
                    ->where('location_id', $workOrder->location_id)
                    ->first();
                $availableReserved = $stock ? (float) $stock->reserved_quantity : 0.0;
                $releaseQty = min($reserved, $availableReserved);

                if ($releaseQty > 0) {
                    StockService::release(
                        $item->product_id,
                        $workOrder->location_id,
                        $releaseQty,
                        WorkOrderItem::class,
                        $item->id
                    );
                }
                $item->update(['quantity_reserved' => 0]);
            }

            $used = (float) $item->quantity_used;
            if ($used <= 0 || ! $workOrder->location_id) {
                continue;
            }

            $productId = (int) $item->product_id;
            $availableIr = $remainingIrCoverage[$productId] ?? 0.0;
            $coveredByIr = min($used, $availableIr);
            $uncoveredQuantity = max(0.0, $used - $coveredByIr);

            if ($coveredByIr > 0) {
                $remainingIrCoverage[$productId] = max(0.0, $availableIr - $coveredByIr);
                AuditService::log('work_order', 'stock_consumption_skipped_ir_backed', [
                    'code' => $workOrder->code,
                    'work_order_id' => $workOrder->id,
                    'work_order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity_covered' => $coveredByIr,
                    'quantity_uncovered' => $uncoveredQuantity,
                ], $workOrder);
            }

            if ($uncoveredQuantity > 0) {
                StockService::issue(
                    $item->product_id,
                    $workOrder->location_id,
                    $uncoveredQuantity,
                    'SPK '.$workOrder->code.($coveredByIr > 0 ? " (partial IR covered {$coveredByIr})" : ''),
                    WorkOrderItem::class,
                    $item->id
                );

                AuditService::log('work_order', 'stock_consumed', [
                    'product_id' => $item->product_id,
                    'quantity' => $uncoveredQuantity,
                ], $workOrder);
            }
        }
    }

    private static function installAsset(WorkOrder $workOrder): void
    {
        if (! in_array($workOrder->type, ['installation', 'install'], true) || ! $workOrder->location_id) {
            return;
        }

        abort_unless($workOrder->subscription && $workOrder->customer, 422, 'Installation SPK requires customer and subscription.');
        /** @var Customer|null $subCustomer */
        $subCustomer = $workOrder->subscription->customer;
        abort_unless($subCustomer && $subCustomer->company_id === $workOrder->company_id, 422, 'Subscription must belong to the SPK company.');
        abort_unless($workOrder->subscription->customer_id === $workOrder->customer_id, 422, 'Subscription must belong to the SPK customer.');

        $serializedItems = $workOrder->items->filter(fn (WorkOrderItem $item) => Product::withoutCompany()->whereKey($item->product_id)->value('type') === 'asset');
        if ($serializedItems->isEmpty()) {
            return;
        }

        $serializedItems->each(function (WorkOrderItem $item) use ($workOrder): void {
            abort_unless((bool) $item->network_asset_id, 422, 'Serialized installation requires a selected network asset.');

            $asset = NetworkAsset::withoutCompany()
                ->lockForUpdate()
                ->find($item->network_asset_id);

            abort_unless($asset instanceof NetworkAsset, 422, 'Selected network asset is unavailable.');
            abort_unless($asset->company_id === $workOrder->company_id, 422, 'Selected network asset must belong to the SPK company.');
            $product = Product::withoutCompany()->find($item->product_id);
            abort_unless($product instanceof Product, 422, 'Selected product must belong to the SPK company.');
            abort_unless($product->company_id === $workOrder->company_id, 422, 'Selected product must belong to the SPK company.');
            abort_unless($product->type === 'asset', 422, 'Selected product must be a serialized asset.');
            abort_unless($asset->product_id === $item->product_id, 422, 'Selected network asset must match the SPK item product.');
            abort_unless($asset->status === 'available', 422, 'Selected network asset must be available.');

            NetworkAssetService::install(
                $asset,
                $workOrder->location_id,
                $workOrder->customer_id,
                $workOrder->subscription_id,
                $workOrder->id
            );

            if (! $workOrder->subscription->ont_asset_id) {
                $workOrder->subscription->update(['ont_asset_id' => $asset->id]);
            }
        });
    }

    private static function activateSubscription(WorkOrder $workOrder): void
    {
        if ($workOrder->subscription?->status !== 'pending') {
            return;
        }

        SubscriptionService::activate($workOrder->subscription);
    }

    private static function createInvoice(WorkOrder $workOrder): void
    {
        if (! self::autoInvoiceEnabled($workOrder)) {
            return;
        }

        CreateInvoiceFromSpkAction::execute($workOrder->id);
    }

    private static function autoInvoiceEnabled(WorkOrder $workOrder): bool
    {
        /** @var Company|null $company */
        $company = $workOrder->company;
        $companySetting = (bool) ($company?->settings['spk_auto_invoice'] ?? false);

        return $companySetting || (bool) SettingService::get('spk_auto_invoice', false);
    }
}
