<?php

namespace Modules\Service\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Service\Models\ServicePackageVersion;

/**
 * One row of a package's version history. No id: the version number names the version inside its package.
 *
 * @mixin ServicePackageVersion
 */
class ServicePackageVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'version' => $this->version,
            'valid_from' => $this->valid_from,
            'price_mrc' => $this->price_mrc,
            'price_otc' => $this->price_otc,
            'bandwidth_profile' => $this->whenLoaded('bandwidthProfile', fn ($profile) => [
                'name' => $profile->name,
                'download_mbps' => $profile->download_mbps,
                'upload_mbps' => $profile->upload_mbps,
            ]),
            'speed_profile' => $this->whenLoaded('speedProfile', fn ($profile) => [
                'name' => $profile->name,
                'download_max_mbps' => $profile->download_max_mbps,
                'upload_max_mbps' => $profile->upload_max_mbps,
            ]),
            'sla_tier' => $this->whenLoaded('slaTier', fn ($tier) => [
                'name' => $tier->name,
                'response_time_hours' => $tier->response_time_hours,
                'resolution_time_hours' => $tier->resolution_time_hours,
            ]),
            'category' => $this->whenLoaded('category', fn ($category) => ['name' => $category->name]),
            'subscriptions_count' => $this->whenCounted('subscriptions'),
        ];
    }
}
