<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerContactResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'customer_id' => $this->customer?->public_id,
            'name' => $this->name,
            'position' => $this->role,
            'phone' => $this->phone,
            'email' => $this->email,
            'is_primary' => $this->is_primary,
            'notes' => $this->notes,
        ];
    }
}
