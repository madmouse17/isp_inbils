<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plate_number' => $this->plate_number,
            'type' => $this->type,
            'brand' => $this->brand,
            'model' => $this->model,
            'purchase_date' => $this->purchase_date,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
        ];
    }
}
