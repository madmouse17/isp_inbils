<?php

namespace Modules\SPK\Actions;

use App\Actions\CreateInvoiceFromSpkAction;
use App\Models\Core\Customer;
use App\Services\Core\AuditService;
use App\Services\Core\SubscriptionService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\Inventory\Models\MaterialReturnLine;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Services\StockService;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Services\NetworkAccountService;
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

            abort_if($workOrder->getMedia('evidence')->isEmpty(), 422, 'Bukti pekerjaan wajib diunggah sebelum SPK diselesaikan.');

            self::reconcileUsedQuantitiesFromMaterialReturns($workOrder);
            $workOrder->load(['items.product', 'items.networkAsset.product']);

            self::consumeStock($workOrder);
            self::installAsset($workOrder);
            self::activateSubscription($workOrder);
            NetworkAccountService::provisionForInstallation($workOrder);
            self::createInvoice($workOrder);

            AuditService::log('work_order', 'completed_orchestrated', [
                'code' => $workOrder->code,
                'items' => $workOrder->items->count(),
                'subscription_id' => $workOrder->subscription_id,
            ], $workOrder);

            return $workOrder->fresh(['items', 'subscription']);
        }, 3);
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
        $coverage = self::issuedCoverage($workOrder);

        foreach ($workOrder->items as $item) {
            $productId = (int) $item->product_id;
            // A product the SPK was handed over comes out of the warehouse its request left, not out of the SPK's own location.
            $locationId = $coverage[$productId]['location_id'] ?? $workOrder->location_id;
            $used = (string) $item->quantity_used;

            if (! Money::gt($used, '0') || ! $locationId) {
                continue;
            }

            $available = $coverage[$productId]['quantity'] ?? '0';
            $covered = Money::compare($used, $available) < 0 ? $used : $available;
            $uncovered = Money::sub($used, $covered);
            $isCovered = Money::gt($covered, '0');

            if ($isCovered) {
                $coverage[$productId]['quantity'] = Money::sub($available, $covered);
                AuditService::log('work_order', 'stock_consumption_skipped_ir_backed', [
                    'code' => $workOrder->code,
                    'work_order_id' => $workOrder->id,
                    'work_order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity_covered' => (float) $covered,
                    'quantity_uncovered' => (float) $uncovered,
                ], $workOrder);
            }

            if (Money::gt($uncovered, '0')) {
                StockService::issue(
                    $productId,
                    $locationId,
                    (float) $uncovered,
                    'SPK '.$workOrder->code.($isCovered ? ' (partial IR covered '.(float) $covered.')' : ''),
                    WorkOrderItem::class,
                    $item->id
                );

                AuditService::log('work_order', 'stock_consumed', [
                    'product_id' => $item->product_id,
                    'quantity' => (float) $uncovered,
                ], $workOrder);
            }
        }
    }

    /**
     * What the handed-over inventory requests of this SPK already took out of stock, per product: the quantity, and the
     * location it left (the item's source, else the request's warehouse; of several items of a product, the last one's).
     * Both reads lock, in the order the lane keeps (SPK, requests, items): a handover that commits between a plain read
     * and the issues below would otherwise be missed and taken out of stock a second time.
     *
     * @return array<int, array{quantity: string, location_id: ?int}>
     */
    private static function issuedCoverage(WorkOrder $workOrder): array
    {
        $warehouses = DB::table('inventory_requests')
            ->where('company_id', $workOrder->company_id)
            ->where('source_type', 'work_order')
            ->where('source_id', $workOrder->id)
            ->where('status', 'issued')
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('warehouse_location_id', 'id');

        if ($warehouses->isEmpty()) {
            return [];
        }

        $coverage = [];

        $items = DB::table('inventory_request_items')
            ->whereIn('inventory_request_id', $warehouses->keys())
            ->where('quantity_issued', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['inventory_request_id', 'product_id', 'source_location_id', 'quantity_issued']);

        foreach ($items as $row) {
            $productId = (int) $row->product_id;
            $coverage[$productId] = [
                'quantity' => Money::add($coverage[$productId]['quantity'] ?? '0', $row->quantity_issued),
                'location_id' => $row->source_location_id ?? $warehouses[$row->inventory_request_id],
            ];
        }

        return $coverage;
    }

    private static function installAsset(WorkOrder $workOrder): void
    {
        if (! in_array($workOrder->type, ['installation', 'install'], true) || ! $workOrder->location_id) {
            return;
        }

        // The relations keep a deleted parent for the history pages; an installation needs both of them alive.
        abort_unless(
            $workOrder->subscription && ! $workOrder->subscription->trashed() && $workOrder->customer && ! $workOrder->customer->trashed(),
            422,
            'SPK instalasi wajib memiliki pelanggan dan langganan.'
        );
        /** @var Customer|null $subCustomer */
        $subCustomer = $workOrder->subscription->customer;
        abort_unless($subCustomer && $subCustomer->company_id === $workOrder->company_id, 422, 'Langganan harus milik perusahaan yang sama dengan SPK.');
        abort_unless($workOrder->subscription->customer_id === $workOrder->customer_id, 422, 'Langganan harus milik pelanggan pada SPK ini.');

        $serializedItems = $workOrder->items->filter(fn (WorkOrderItem $item) => Product::withoutCompany()->whereKey($item->product_id)->value('type') === 'asset');
        if ($serializedItems->isEmpty()) {
            return;
        }

        $serializedItems->each(function (WorkOrderItem $item) use ($workOrder): void {
            abort_unless((bool) $item->network_asset_id, 422, 'Instalasi aset berserial wajib memilih aset jaringan.');

            $asset = NetworkAsset::withoutCompany()
                ->lockForUpdate()
                ->find($item->network_asset_id);

            abort_unless($asset instanceof NetworkAsset, 422, 'Aset jaringan yang dipilih tidak tersedia.');
            abort_unless($asset->company_id === $workOrder->company_id, 422, 'Aset jaringan yang dipilih harus milik perusahaan yang sama dengan SPK.');
            $product = Product::withoutCompany()->find($item->product_id);
            abort_unless($product instanceof Product, 422, 'Barang yang dipilih harus milik perusahaan yang sama dengan SPK.');
            abort_unless($product->company_id === $workOrder->company_id, 422, 'Barang yang dipilih harus milik perusahaan yang sama dengan SPK.');
            abort_unless($product->type === 'asset', 422, 'Barang yang dipilih harus berupa aset berserial.');
            abort_unless($asset->product_id === $item->product_id, 422, 'Aset jaringan yang dipilih harus sesuai dengan barang pada item SPK.');
            abort_unless($asset->status === 'available', 422, 'Aset jaringan yang dipilih harus berstatus tersedia.');

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
        $subscription = $workOrder->subscription;

        if ($subscription === null || $subscription->trashed() || $subscription->status !== 'pending') {
            return;
        }

        SubscriptionService::activate($subscription);
    }

    /** An installation is billed when its approval completes it (K17); the other SPK types are billed by hand. */
    private static function createInvoice(WorkOrder $workOrder): void
    {
        if ($workOrder->type === 'installation') {
            CreateInvoiceFromSpkAction::afterApproval($workOrder);
        }
    }
}
