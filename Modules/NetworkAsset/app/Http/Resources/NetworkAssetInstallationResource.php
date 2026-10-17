<?php

namespace Modules\NetworkAsset\Http\Resources;

use App\Http\Resources\LocationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NetworkAssetInstallationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'location_id' => $this->location?->public_id,
            'customer_id' => $this->customer?->public_id,
            'subscription_id' => $this->subscription?->public_id,
            'spk_id' => $this->workOrder?->public_id,
            'installed_by' => $this->installer?->public_id,
            'installed_at' => $this->installed_at,
            'removed_at' => $this->removed_at,
            'removal_reason' => $this->removal_reason,
            'location' => new LocationResource($this->whenLoaded('location')),
        ];
    }
}
