<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerAddressResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'label' => $this->label,
            'address' => $this->address,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'province_code' => $this->province_code,
            'province_name' => $this->whenLoaded('province', fn () => $this->province?->name),
            'city_code' => $this->city_code,
            'city_name' => $this->whenLoaded('regionCity', fn () => $this->regionCity?->name),
            'district_code' => $this->district_code,
            'district_name' => $this->whenLoaded('district', fn () => $this->district?->name),
            'village_code' => $this->village_code,
            'village_name' => $this->whenLoaded('village', fn () => $this->village?->name),
            'lat' => $this->lat,
            'lng' => $this->lng,
            'is_installation_point' => $this->is_installation_point,
            'is_primary' => $this->is_primary,
            'notes' => $this->notes,
        ];
    }
}
