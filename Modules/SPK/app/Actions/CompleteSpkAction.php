<?php

namespace Modules\SPK\Actions;

use App\Services\Core\AuditService;
use App\Services\Core\SettingService;
use App\Services\Core\SubscriptionService;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Services\BillingService;
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
            $workOrder = WorkOrder::with(['evidence', 'items.product', 'subscription', 'customer'])
                ->lockForUpdate()
                ->findOrFail($workOrder->id);

            abort_if($workOrder->evidence->isEmpty(), 422, 'Evidence required before completion.');

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

    private static function consumeStock(WorkOrder $workOrder): void
    {
        foreach ($workOrder->items as $item) {
            $used = (float) $item->quantity_used;

            if ($used <= 0 || ! $workOrder->location_id) {
                continue;
            }

            $reserved = (float) $item->quantity_reserved;
            $release = min($reserved, $used);

            if ($release > 0) {
                StockService::release($item->product_id, $workOrder->location_id, $release, WorkOrderItem::class, $item->id);
            }

            StockService::issue(
                $item->product_id,
                $workOrder->location_id,
                $used,
                'SPK '.$workOrder->code,
                WorkOrderItem::class,
                $item->id
            );

            $remainingReserved = max(0, $reserved - $used);
            $item->update(['quantity_reserved' => $remainingReserved]);

            AuditService::log('work_order', 'stock_consumed', [
                'product_id' => $item->product_id,
                'quantity' => $used,
            ], $workOrder);
        }
    }

    private static function installAsset(WorkOrder $workOrder): void
    {
        if (! in_array($workOrder->type, ['installation', 'install'], true) || ! $workOrder->location_id) {
            return;
        }

        $asset = NetworkAsset::where('status', 'available')
            ->whereIn('product_id', $workOrder->items->pluck('product_id')->filter()->values())
            ->first();

        // ponytail: exact asset selection needs work_order_items.network_asset_id or work_orders.network_asset_id.
        // Current safe path installs first available asset matching SPK product.
        if (! $asset) {
            return;
        }

        NetworkAssetService::install(
            $asset,
            $workOrder->location_id,
            $workOrder->customer_id,
            $workOrder->subscription_id,
            $workOrder->id
        );

        if ($workOrder->subscription && ! $workOrder->subscription->ont_asset_id) {
            $workOrder->subscription->update(['ont_asset_id' => $asset->id]);
        }
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

        BillingService::createFromSpk($workOrder->id);
    }

    private static function autoInvoiceEnabled(WorkOrder $workOrder): bool
    {
        $companySetting = (bool) ($workOrder->company?->settings['spk_auto_invoice'] ?? false);

        return $companySetting || (bool) SettingService::get('spk_auto_invoice', false);
    }
}
