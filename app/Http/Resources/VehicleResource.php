<?php

namespace App\Http\Resources;

use App\Models\Core\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Vehicle */
class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'plate_number' => $this->plate_number,
            'type' => $this->type,
            'brand' => $this->brand,
            'model' => $this->model,
            'purchase_date' => $this->purchase_date?->toDateString(),
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            ...$this->lifecycleAttributes(),
        ];
    }
}
