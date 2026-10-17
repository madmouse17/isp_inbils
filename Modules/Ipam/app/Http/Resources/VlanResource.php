<?php

namespace Modules\Ipam\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Ipam\Models\Vlan;

/**
 * Expects the location relation and the prefixes_count aggregate to be loaded.
 *
 * @mixin Vlan
 */
class VlanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'vid' => $this->vid,
            'name' => $this->name,
            'location' => $this->location === null ? null : ['id' => $this->location->public_id, 'code' => $this->location->code],
            'description' => $this->description,
            'prefixes_count' => (int) $this->getAttribute('prefixes_count'),
        ];
    }
}
