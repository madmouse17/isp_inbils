<?php

namespace Modules\Ipam\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Ipam\Models\IpAddress;

/**
 * Expects the subscription (with its customer) and network asset relations to be loaded.
 *
 * @mixin IpAddress
 */
class IpAddressResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'address' => $this->address,
            'status' => $this->status,
            'holder' => $this->holder(),
            'note' => $this->note,
            'assigned_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }

    /** @return array{type: string, id: string|null, label: string, href: string}|null */
    private function holder(): ?array
    {
        if ($this->subscription_id !== null) {
            return [
                'type' => 'subscription',
                'id' => $this->subscription?->public_id,
                'label' => trim(($this->subscription->code ?? '').' '.($this->subscription->customer->name ?? '')),
                'href' => route('admin.subscriptions.show', $this->subscription, false),
            ];
        }

        if ($this->network_asset_id !== null) {
            return [
                'type' => 'asset',
                'id' => $this->networkAsset?->public_id,
                'label' => trim(($this->networkAsset->code ?? '').' '.($this->networkAsset->name ?? '')),
                'href' => route('admin.network-assets.show', $this->networkAsset, false),
            ];
        }

        return null;
    }
}
