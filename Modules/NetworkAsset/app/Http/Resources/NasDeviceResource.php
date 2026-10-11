<?php

namespace Modules\NetworkAsset\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\NetworkAsset\Models\NasDevice;

/**
 * Never carries the API password: only `has_api_password`. Counts come from withCount(): `network_accounts_count`
 * and `accounts_failed_count`.
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
            'id' => $this->id,
            'network_asset' => $asset === null ? null : [
                'id' => $asset->id,
                'code' => $asset->code,
                'name' => $asset->name,
                'management_ip' => $asset->getAttribute('management_ip'),
                'location' => $location === null ? null : ['id' => $location->getKey(), 'name' => $location->getAttribute('name'), 'type' => $location->getAttribute('type')],
            ],
            'api_port' => $this->api_port,
            'api_username' => $this->api_username,
            'has_api_password' => filled($this->resource->getRawOriginal('api_password')),
            'isolir_profile' => $this->isolir_profile,
            'provisioning_enabled' => $this->provisioning_enabled,
            'routeros_version' => $this->routeros_version,
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'unreachable_since' => $this->unreachable_since?->toIso8601String(),
            'last_error' => $this->last_error,
            'unmanaged_count' => $this->unmanaged_count,
            'accounts_count' => (int) ($this->network_accounts_count ?? 0),
            'accounts_failed_count' => (int) ($this->accounts_failed_count ?? 0),
        ];
    }
}
