<?php

namespace App\Http\Resources;

use App\Queries\Admin\ApprovalInboxQuery;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\InventoryRequest;
use Modules\SPK\Models\WorkOrder;

/**
 * Lightweight row payload for the approval inbox. Only the fields the queue
 * actually needs are exposed; no cross-domain or off-limits data is attached.
 */
class ApprovalInboxItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        if ($this->resource instanceof WorkOrder) {
            /** @var WorkOrder $wo */
            $wo = $this->resource;
            $makerCheckerEligible = ApprovalInboxQuery::eligibleFor($wo, $user);
            $canView = (bool) $user?->can('view', $wo);
            // The moment the technician submitted the SPK; one that reached review before that moment was recorded keeps its creation time.
            $submittedAt = $wo->submitted_at ?? $wo->created_at;

            return [
                'id' => $wo->public_id,
                'domain' => 'spk',
                'composite_id' => "spk:{$wo->public_id}",
                'code' => $wo->code,
                'type' => $wo->type,
                'title' => $wo->title,
                'priority' => $wo->priority,
                'status' => $wo->status,
                // The requester of an SPK is the technician it is assigned to, who did the work and submitted it.
                'requester' => $wo->assignee === null ? null : ['id' => $wo->assignee->public_id, 'name' => $wo->assignee->name],
                'assigned_to' => $wo->assignee?->public_id,
                'submitted_at' => $submittedAt?->toISOString(),
                'age_seconds' => $submittedAt ? max(0, (int) $submittedAt->diffInSeconds(now())) : null,
                'scheduled_date' => $wo->scheduled_date?->toDateString(),
                'summary' => $this->summarizeWorkOrder($wo),
                'actions' => [
                    'approve' => $makerCheckerEligible && (bool) $user?->can('spk.approve'),
                    'reject' => $makerCheckerEligible && (bool) $user?->can('spk.reject'),
                    'view' => $canView,
                ],
                'detail_url' => $canView ? route('admin.spk.show', $wo) : null,
            ];
        }

        if ($this->resource instanceof InventoryRequest) {
            /** @var InventoryRequest $ir */
            $ir = $this->resource;
            $makerCheckerEligible = ApprovalInboxQuery::eligibleForInventoryRequest($ir, $user);
            $canView = (bool) $user?->can('view', $ir);

            $createdAt = $ir->getAttribute('created_at');
            $createdCarbon = $createdAt instanceof Carbon ? $createdAt : null;
            $submittedTime = $ir->submitted_at ?? $createdCarbon;

            return [
                'id' => $ir->public_id,
                'domain' => 'inventory_request',
                'composite_id' => "inventory_request:{$ir->public_id}",
                'code' => $ir->code,
                'type' => ($ir->source_type !== null && $ir->source_type !== '') ? (string) $ir->source_type : 'inventory_request',
                'title' => ($ir->reason !== null && $ir->reason !== '') ? (string) $ir->reason : 'Permintaan Material #'.$ir->code,
                'priority' => 'medium',
                'status' => $ir->status,
                'requester' => $ir->relationLoaded('requester') && $ir->requester
                    ? ['id' => $ir->requester->public_id, 'name' => $ir->requester->name]
                    : null,
                'assigned_to' => null,
                'submitted_at' => $submittedTime?->toISOString(),
                'age_seconds' => $submittedTime ? max(0, (int) $submittedTime->diffInSeconds(now())) : null,
                'scheduled_date' => $ir->needed_at?->toDateString(),
                'summary' => $this->summarizeInventoryRequest($ir),
                'actions' => [
                    'approve' => $makerCheckerEligible && (bool) $user?->can('inventory.request.approve'),
                    'reject' => $makerCheckerEligible && (bool) $user?->can('inventory.request.reject'),
                    'view' => $canView,
                ],
                'detail_url' => $canView ? route('admin.inventory.requests.show', $ir) : null,
            ];
        }

        return parent::toArray($request);
    }

    /**
     * Compact human-readable impact summary for WorkOrder.
     *
     * @return array<string, mixed>
     */
    private function summarizeWorkOrder(WorkOrder $wo): array
    {
        return self::summarizeItems(
            $wo->relationLoaded('items') ? $wo->items : null,
            fn (Model $item): float => max((float) $item->getAttribute('quantity_reserved'), (float) $item->getAttribute('quantity_used')),
        );
    }

    /**
     * Compact human-readable impact summary for InventoryRequest.
     *
     * @return array<string, mixed>
     */
    private function summarizeInventoryRequest(InventoryRequest $ir): array
    {
        return self::summarizeItems(
            $ir->relationLoaded('items') ? $ir->items : null,
            fn (Model $item): float => (float) ($item->getAttribute('quantity_requested') ?? 0),
        );
    }

    /**
     * What an approval moves: the names, and one line per product with its quantity in the product's own unit. The lines
     * are never added together (F4-D09): 18 m of cable and 2 pcs of connectors are not 20 of anything. The queue queries
     * load `product.unit`; a line whose unit is not loaded carries none rather than costing a query per product.
     *
     * @param  iterable<int, Model>|null  $items
     * @param  callable(Model): float  $quantityOf
     * @return array{label: string, count: int, materials: list<array{product: string, quantity: float, unit: string|null}>}
     */
    private static function summarizeItems(?iterable $items, callable $quantityOf): array
    {
        $items = collect($items);

        if ($items->isEmpty()) {
            return ['label' => 'Tidak ada item', 'count' => 0, 'materials' => []];
        }

        $materials = $items->map(function (Model $item) use ($quantityOf): array {
            /** @var object|null $product */
            $product = $item->getAttribute('product');
            $unit = $product instanceof Model && $product->relationLoaded('unit') ? $product->getRelation('unit') : null;

            return [
                'product' => (string) ($product->name ?? 'Produk tidak tersedia'),
                'quantity' => round($quantityOf($item), 2),
                'unit' => $unit?->symbol ?? $unit?->name,
            ];
        });
        $names = $materials->pluck('product');

        return [
            'label' => $names->take(2)->implode(', ').($names->count() > 2 ? ' (+'.($names->count() - 2).')' : ''),
            'count' => $materials->count(),
            'materials' => $materials->values()->all(),
        ];
    }
}
