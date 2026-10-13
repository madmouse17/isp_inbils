<?php

namespace Modules\SPK\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SPK\Models\WorkOrderItem;

/**
 * One material line of an SPK as the technician app lists it, in the SPK detail and as the answer to adding one.
 *
 * @property WorkOrderItem $resource
 */
class TechnicianWorkOrderItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $item = $this->resource;

        return [
            'id' => $item->id,
            /** @var string|null */
            'product_name' => data_get($item, 'product.name'),
            /** @var string|null */
            'unit' => data_get($item, 'product.unit.symbol') ?? data_get($item, 'product.unit.name'),
            'quantity_reserved' => (float) $item->quantity_reserved,
            'quantity_used' => (float) $item->quantity_used,
            'note' => $item->note,
        ];
    }
}
