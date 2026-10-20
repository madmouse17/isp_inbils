<?php

namespace Modules\NetworkAsset\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\NetworkAsset\Models\NetworkAccount;

/**
 * Never carries the subscriber secret. Session and usage are read live from RADIUS by the page, not here.
 *
 * @mixin NetworkAccount
 */
class NetworkAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'service_type' => $this->service_type,
            'username' => $this->username,
            'sync_status' => $this->sync_status,
            'last_error' => $this->last_error,
            'provisioned_group' => $this->provisioned_group,
            'provisioned_at' => $this->provisioned_at?->toIso8601String(),
            'disconnect_pending' => $this->pending_disconnect_at !== null,
            'pending_disconnect_at' => $this->pending_disconnect_at?->toIso8601String(),
        ];
    }
}
