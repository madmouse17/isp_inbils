<?php

namespace Modules\NetworkAsset\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NetworkAssetInstallationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_id' => $this->location_id,
            'customer_id' => $this->customer_id,
            'subscription_id' => $this->subscription_id,
            'spk_id' => $this->spk_id,
            'installed_by' => $this->installed_by,
            'installed_at' => $this->installed_at,
            'removed_at' => $this->removed_at,
            'removal_reason' => $this->removal_reason,
            'location' => new \App\Http\Resources\LocationResource($this->whenLoaded('location')),
        ];
    }
}
