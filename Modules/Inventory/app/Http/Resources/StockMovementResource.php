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
            'id' => $this->public_id,
            'product_id' => $this->product?->public_id,
            'from_location_id' => $this->fromLocation?->public_id,
            'to_location_id' => $this->toLocation?->public_id,
            'movement_type' => $this->movement_type,
            'quantity' => $this->quantity,
            'balance_after' => $this->balance_after,
            'reserved_after' => $this->reserved_after,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->referencePublicId(),
            'note' => $this->note,
            'created_by' => $this->creator?->public_id,
            'actor' => $this->whenLoaded('creator', fn (): ?array => $this->creator ? ['id' => $this->creator->public_id, 'name' => $this->creator->name] : null),
            'supplier_name' => $this->supplier_name,
            'purchase_reference' => $this->purchase_reference,
            'unit_cost' => $this->unit_cost,
            'created_at' => $this->created_at,
            'product' => new ProductResource($this->whenLoaded('product')),
            'from_location' => new LocationResource($this->whenLoaded('fromLocation')),
            'to_location' => new LocationResource($this->whenLoaded('toLocation')),
        ];
    }
}
