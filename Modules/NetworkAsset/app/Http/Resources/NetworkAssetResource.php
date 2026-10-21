<?php

namespace Modules\NetworkAsset\Http\Resources;

use App\Http\Resources\CustomerResource;
use App\Http\Resources\LocationResource;
use App\Http\Resources\SubscriptionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Http\Resources\ProductResource;
use Modules\NetworkAsset\Models\NetworkAsset;

/** @mixin NetworkAsset */
class NetworkAssetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'product_id' => $this->product?->public_id,
            'code' => $this->code,
            'name' => $this->name,
            'asset_type' => $this->asset_type,
            'serial_number' => $this->serial_number,
            'mac_address' => $this->mac_address,
            'ip_address' => $this->ip_address,
            'management_ip' => $this->management_ip,
            'location_id' => $this->location?->public_id,
            'customer_id' => $this->customer?->public_id,
            'subscription_id' => $this->subscription?->public_id,
            'status' => $this->status,
            'ownership' => $this->ownership,
            'vendor' => $this->vendor,
            'model' => $this->model,
            'purchase_date' => $this->purchase_date?->toDateString(),
            'purchase_price' => $this->purchase_price,
            'warranty_expiry' => $this->warranty_expiry?->toDateString(),
            'notes' => $this->notes,
            'installed_at' => $this->installed_at,
            'retired_at' => $this->retired_at,
            'product' => new ProductResource($this->whenLoaded('product')),
            'location' => new LocationResource($this->whenLoaded('location')),
            'customer' => $this->whenLoaded('customer', fn (): ?array => CustomerResource::summary($this->customer)),
            'subscription' => $this->whenLoaded('subscription', fn (): ?array => SubscriptionResource::summary($this->subscription)),
            'installations' => NetworkAssetInstallationResource::collection($this->whenLoaded('installations')),
            ...$this->lifecycleAttributes(),
        ];
    }
}
