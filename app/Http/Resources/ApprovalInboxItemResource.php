<?php

namespace App\Http\Resources;

use App\Queries\Admin\ApprovalInboxQuery;
use Carbon\Carbon;
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

            return [
                'id' => $wo->id,
                'domain' => 'spk',
                'composite_id' => "spk:{$wo->id}",
                'code' => $wo->code,
                'type' => $wo->type,
                'title' => $wo->title,
                'priority' => $wo->priority,
                'status' => $wo->status,
                'requester' => $wo->relationLoaded('creator') && $wo->creator
                    ? ['id' => $wo->creator->id, 'name' => $wo->creator->name]
                    : null,
                'assigned_to' => $wo->assigned_to,
                'submitted_at' => $wo->created_at?->toISOString(),
                'age_seconds' => $wo->created_at ? max(0, now()->diffInSeconds($wo->created_at)) : null,
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
                'id' => $ir->id,
                'domain' => 'inventory_request',
                'composite_id' => "inventory_request:{$ir->id}",
                'code' => $ir->code,
                'type' => ($ir->source_type !== null && $ir->source_type !== '') ? (string) $ir->source_type : 'inventory_request',
                'title' => ($ir->reason !== null && $ir->reason !== '') ? (string) $ir->reason : 'Permintaan Material #'.$ir->code,
                'priority' => 'medium',
                'status' => $ir->status,
                'requester' => $ir->relationLoaded('requester') && $ir->requester
                    ? ['id' => $ir->requester->id, 'name' => $ir->requester->name]
                    : null,
                'assigned_to' => null,
                'submitted_at' => $submittedTime?->toISOString(),
                'age_seconds' => $submittedTime ? max(0, now()->diffInSeconds($submittedTime)) : null,
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
        $items = $wo->relationLoaded('items') ? $wo->items : null;

        if ($items === null || $items->isEmpty()) {
            return ['label' => 'Tidak ada item', 'quantity' => 0, 'count' => 0];
        }

        $totalQuantity = 0.0;
        foreach ($items as $item) {
            $reserved = (float) $item->quantity_reserved;
            $used = (float) $item->quantity_used;
            $totalQuantity += max($reserved, $used);
        }

        $labels = $items->take(2)->map(function ($item): string {
            /** @var object|null $product */
            $product = $item->product;

            return (string) ($product->name ?? '#'.$item->product_id);
        })->values()->all();

        $suffix = $items->count() > 2 ? ' (+'.($items->count() - 2).')' : '';

        return [
            'label' => implode(', ', $labels).$suffix,
            'quantity' => round($totalQuantity, 2),
            'count' => $items->count(),
        ];
    }

    /**
     * Compact human-readable impact summary for InventoryRequest.
     *
     * @return array<string, mixed>
     */
    private function summarizeInventoryRequest(InventoryRequest $ir): array
    {
        $items = $ir->relationLoaded('items') ? $ir->items : null;

        if ($items === null || $items->isEmpty()) {
            return ['label' => 'Tidak ada item', 'quantity' => 0, 'count' => 0];
        }

        $totalQuantity = 0.0;
        foreach ($items as $item) {
            $totalQuantity += (float) ($item->quantity_requested ?? 0);
        }

        $labels = $items->take(2)->map(function ($item): string {
            /** @var object|null $product */
            $product = $item->product;

            return (string) ($product->name ?? '#'.$item->product_id);
        })->values()->all();

        $suffix = $items->count() > 2 ? ' (+'.($items->count() - 2).')' : '';

        return [
            'label' => implode(', ', $labels).$suffix,
            'quantity' => round($totalQuantity, 2),
            'count' => $items->count(),
        ];
    }
}
