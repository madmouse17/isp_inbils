<?php

namespace Modules\Reporting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\StockMovement;

/**
 * @property StockMovement $resource
 */
class StockMovementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'product_id' => $this->resource->product_id,
            'product' => $this->resource->product?->name,
            'movement_type' => $this->resource->movement_type,
            'quantity' => $this->resource->quantity,
            'balance_after' => $this->resource->balance_after,
            'from_location' => $this->resource->fromLocation?->name,
            'to_location' => $this->resource->toLocation?->name,
            'reference_type' => $this->resource->reference_type,
            'reference_id' => $this->resource->reference_id,
            'reference' => $this->resource->getAttribute('reference_link'),
            'note' => $this->resource->note,
            'created_at' => $this->resource->created_at?->toISOString(),
        ];
    }
}
