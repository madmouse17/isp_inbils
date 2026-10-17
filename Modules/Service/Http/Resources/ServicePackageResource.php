<?php

namespace Modules\Service\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServicePackageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'code' => $this->code,
            'name' => $this->name,
            'bandwidth_profile_id' => $this->bandwidthProfile?->public_id,
            'speed_profile_id' => $this->speedProfile?->public_id,
            'sla_tier_id' => $this->slaTier?->public_id,
            'service_package_category_id' => $this->category?->public_id,
            'price_mrc' => $this->price_mrc,
            'price_otc' => $this->price_otc,
            'contract_min_months' => $this->contract_min_months,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'bandwidth_profile' => new BandwidthProfileResource($this->whenLoaded('bandwidthProfile')),
            'speed_profile' => new SpeedProfileResource($this->whenLoaded('speedProfile')),
            'sla_tier' => new SLATierResource($this->whenLoaded('slaTier')),
            'category' => $this->whenLoaded('category', fn () => $this->category === null ? null : ['id' => $this->category->public_id, 'code' => $this->category->code, 'name' => $this->category->name]),
        ];
    }
}
