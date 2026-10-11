<?php

namespace Modules\NetworkAsset\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\NetworkAsset\Models\NetworkAccount;

/**
 * Never carries the subscriber password. `online` needs the `nasDevice` relation (its last_seen_at); the
 * router label needs `nasDevice.networkAsset`; `subscription` appears only when that relation is loaded.
 *
 * @mixin NetworkAccount
 */
class NetworkAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $nas = $this->relationLoaded('nasDevice') ? $this->nasDevice : null;
        $asset = $nas?->relationLoaded('networkAsset') ? $nas->networkAsset : null;

        return [
            'id' => $this->id,
            'nas_device_id' => $this->nas_device_id,
            'nas_label' => $asset === null ? '' : $asset->code.' - '.$asset->name,
            'service_type' => $this->service_type,
            'username' => $this->username,
            'sync_status' => $this->sync_status,
            'last_error' => $this->last_error,
            'provisioned_profile' => $this->provisioned_profile,
            'provisioned_at' => $this->provisioned_at?->toIso8601String(),
            'online' => $this->isOnline(),
            'last_online_at' => $this->last_online_at?->toIso8601String(),
            'current_address' => $this->current_address,
            'subscription' => $this->when($this->relationLoaded('subscription'), fn (): array => [
                'id' => $this->subscription->id,
                'code' => $this->subscription->code,
                'customer_name' => $this->subscription->relationLoaded('customer') ? $this->subscription->customer?->name : null,
            ]),
        ];
    }

    /** Seen online at the router's last successful check, give or take a minute. */
    private function isOnline(): bool
    {
        $seen = $this->relationLoaded('nasDevice') ? $this->nasDevice?->last_seen_at : null;

        return $this->last_online_at !== null && $seen !== null && $this->last_online_at->gte($seen->copy()->subMinute());
    }
}
