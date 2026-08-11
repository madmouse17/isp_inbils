<?php

namespace Modules\Inventory\Http\Resources;

use App\Http\Resources\LocationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'location_id' => $this->location_id,
            'quantity' => $this->quantity,
            'reserved_quantity' => $this->reserved_quantity,
            'available' => $this->available,
            'location' => new LocationResource($this->whenLoaded('location')),
        ];
    }
}
