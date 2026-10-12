<?php

namespace Modules\Inventory\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\InventoryRequestItem;
use Modules\SPK\Models\WorkOrder;

/**
 * The technician's own request as the app lists it: no approver, warehouse or audit fields.
 *
 * @property InventoryRequest $resource
 */
class TechnicianInventoryRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $inventoryRequest = $this->resource;
        $source = $inventoryRequest->relationLoaded('source') ? $inventoryRequest->getRelation('source') : null;

        return [
            'id' => $inventoryRequest->id,
            'code' => $inventoryRequest->code,
            'status' => $inventoryRequest->status,
            'items' => $inventoryRequest->items->map(fn (InventoryRequestItem $item): array => [
                /** @var string|null */
                'product_name' => data_get($item, 'product.name'),
                /** @var string|null */
                'unit' => data_get($item, 'product.unit.symbol') ?? data_get($item, 'product.unit.name'),
                'quantity' => (float) $item->quantity_requested,
            ])->values()->all(),
            'work_order' => $source instanceof WorkOrder ? ['id' => $source->id, 'code' => $source->code] : null,
            /** @var string|null */
            'updated_at' => $inventoryRequest->getAttribute('updated_at'),
        ];
    }
}
