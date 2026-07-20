<?php

namespace Modules\Inventory\Http\Resources;

use App\Http\Resources\LocationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'from_location_id' => $this->from_location_id,
            'to_location_id' => $this->to_location_id,
            'movement_type' => $this->movement_type,
            'quantity' => $this->quantity,
            'balance_after' => $this->balance_after,
            'reserved_after' => $this->reserved_after,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'note' => $this->note,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'product' => new ProductResource($this->whenLoaded('product')),
            'from_location' => new LocationResource($this->whenLoaded('fromLocation')),
            'to_location' => new LocationResource($this->whenLoaded('toLocation')),
        ];
    }
}
