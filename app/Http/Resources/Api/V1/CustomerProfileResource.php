<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property Customer $resource */
class CustomerProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $customer = $this->resource;

        return [
            'code' => $customer->code,
            'name' => $customer->name,
            'type' => $customer->type,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'contact_person' => $customer->contact_person,
            'status' => $customer->customer_status,
            'addresses' => $this->whenLoaded('addresses', fn () => $customer->addresses->map(fn (CustomerAddress $address): array => [
                'id' => $address->public_id,
                'address' => $address->address,
                'city' => $address->city,
                'postal_code' => $address->postal_code,
                'is_primary' => $address->is_primary,
                'is_installation_point' => $address->is_installation_point,
            ])->all()),
        ];
    }
}
