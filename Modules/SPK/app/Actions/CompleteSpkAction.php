<?php

namespace Modules\SPK\Actions;

use App\Services\Core\AuditService;
use App\Services\Core\SettingService;
use App\Services\Core\SubscriptionService;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Services\BillingService;
use Modules\Inventory\Models\Product;
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
            $workOrder = WorkOrder::with(['media', 'items.product', 'subscription.customer', 'customer'])
                ->lockForUpdate()
                ->findOrFail($workOrder->id);

            abort_if($workOrder->getMedia('evidence')->isEmpty(), 422, 'Evidence required before completion.');

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

        abort_unless($workOrder->subscription && $workOrder->customer, 422, 'Installation SPK requires customer and subscription.');
        abort_unless($workOrder->subscription->customer?->company_id === $workOrder->company_id, 422, 'Subscription must belong to the SPK company.');
        abort_unless($workOrder->subscription->customer_id === $workOrder->customer_id, 422, 'Subscription must belong to the SPK customer.');

        $selectedItems = $workOrder->items->filter(fn (WorkOrderItem $item) => $item->network_asset_id !== null);
        if ($selectedItems->isEmpty()) {
            return;
        }

        abort_unless($selectedItems->count() === 1, 422, 'Installation SPK can only install one selected network asset.');

        $selectedItem = $selectedItems->first();
        $asset = NetworkAsset::withoutCompany()
            ->lockForUpdate()
            ->find($selectedItem->network_asset_id);

        abort_unless($asset, 422, 'Selected network asset is unavailable.');
        abort_unless($asset->company_id === $workOrder->company_id, 422, 'Selected network asset must belong to the SPK company.');

        $product = Product::withoutCompany()->find($selectedItem->product_id);

        abort_unless($product?->company_id === $workOrder->company_id, 422, 'Selected product must belong to the SPK company.');
        abort_unless($asset->product_id === $selectedItem->product_id, 422, 'Selected network asset must match the SPK item product.');
        abort_unless($asset->status === 'available', 422, 'Selected network asset must be available.');

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
