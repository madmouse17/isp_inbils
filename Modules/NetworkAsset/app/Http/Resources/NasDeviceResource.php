<?php

namespace Modules\NetworkAsset\Http\Resources;

use App\Services\Core\CompanyService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Services\Radius\Realm;

/**
 * Never carries the RADIUS shared secret: only `has_radius_secret`. The shortname is the realm of the current
 * company (every row of a page belongs to it), so a page costs no query per row.
 *
 * @mixin NasDevice
 */
class NasDeviceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $asset = $this->relationLoaded('networkAsset') ? $this->networkAsset : null;
        $location = $asset?->relationLoaded('location') ? $asset->location : null;

        return [
            'id' => $this->public_id,
            'network_asset' => $asset === null ? null : [
                'id' => $asset->public_id,
                'code' => $asset->code,
                'name' => $asset->name,
                'management_ip' => $asset->getAttribute('management_ip'),
                'location' => $location === null ? null : ['id' => $location->public_id, 'name' => $location->getAttribute('name'), 'type' => $location->getAttribute('type')],
            ],
            'nasname' => (string) $this->nasname,
            'shortname' => (string) Realm::fromCode(CompanyService::current()?->code),
            'coa_port' => $this->coa_port,
            'has_radius_secret' => filled($this->resource->getRawOriginal('radius_secret')),
            'radius_synced_at' => $this->radius_synced_at?->toIso8601String(),
            'last_error' => $this->last_error,
        ];
    }
}
