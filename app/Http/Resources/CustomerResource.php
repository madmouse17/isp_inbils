<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'email' => $this->email,
            'phone' => $this->phone,
            'tax_id' => $this->tax_id,
            'contact_person' => $this->contact_person,
            'area_coverage_id' => $this->area_coverage_id,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'addresses_count' => $this->whenCounted('addresses'),
            'subscriptions_count' => $this->whenCounted('subscriptions'),
            'created_at' => $this->created_at,
        ];
    }
}
