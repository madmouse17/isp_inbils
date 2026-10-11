<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One signed-in install of an app: a token as the user sees it. The token itself is never part of it.
 *
 * @property PersonalAccessToken $resource
 */
class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'app' => $this->resource->abilities[0] ?? null,
            'last_used_at' => $this->resource->last_used_at,
            'created_at' => $this->resource->created_at,
            'expires_at' => $this->resource->expires_at,
            'current' => $this->resource->is($request->user()->currentAccessToken()),
        ];
    }
}
