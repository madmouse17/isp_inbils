<?php

namespace Modules\NetworkAsset\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NetworkAssetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'asset_type' => $this->asset_type,
            'serial_number' => $this->serial_number,
            'mac_address' => $this->mac_address,
            'ip_address' => $this->ip_address,
            'management_ip' => $this->management_ip,
            'location_id' => $this->location_id,
            'customer_id' => $this->customer_id,
            'subscription_id' => $this->subscription_id,
            'status' => $this->status,
            'ownership' => $this->ownership,
            'vendor' => $this->vendor,
            'model' => $this->model,
            'purchase_date' => $this->purchase_date,
            'purchase_price' => $this->purchase_price,
            'warranty_expiry' => $this->warranty_expiry,
            'notes' => $this->notes,
            'installed_at' => $this->installed_at,
            'retired_at' => $this->retired_at,
            'location' => new \App\Http\Resources\LocationResource($this->whenLoaded('location')),
            'customer' => new \App\Http\Resources\CustomerResource($this->whenLoaded('customer')),
            'subscription' => new \App\Http\Resources\SubscriptionResource($this->whenLoaded('subscription')),
            'installations' => NetworkAssetInstallationResource::collection($this->whenLoaded('installations')),
        ];
    }
}
