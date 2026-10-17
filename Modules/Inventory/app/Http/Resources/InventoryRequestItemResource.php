<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryRequestItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'inventory_request_id' => $this->inventoryRequest?->public_id,
            'product_id' => $this->product?->public_id,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->public_id,
                'name' => $this->product->name,
                'code' => $this->product->code,
                'type' => $this->product->type,
                'unit' => $this->product->unit?->symbol ?? $this->product->unit?->name,
            ]),
            'source_location_id' => $this->sourceLocation?->public_id,
            'source_location' => $this->whenLoaded('sourceLocation', fn () => [
                'id' => $this->sourceLocation->public_id,
                'name' => $this->sourceLocation->name,
            ]),
            'quantity_requested' => (float) $this->quantity_requested,
            'quantity_approved' => $this->quantity_approved !== null ? (float) $this->quantity_approved : null,
            'quantity_issued' => (float) $this->quantity_issued,
            'note' => $this->note,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
