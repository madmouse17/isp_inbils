<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Core\DeviceToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The registration of one FCM token. Only the id leaves the server: the app already holds the token.
 *
 * @property DeviceToken $resource
 */
class DeviceTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource->public_id];
    }
}
