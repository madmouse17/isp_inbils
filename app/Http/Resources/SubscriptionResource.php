<?php

namespace App\Http\Resources;

use App\Models\Core\ServiceSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Service\Http\Resources\ServicePackageResource;
use Modules\Service\Http\Resources\ServicePackageVersionResource;

/** @mixin ServiceSubscription */
class SubscriptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'customer_id' => $this->customer?->public_id,
            'service_package_id' => $this->servicePackage?->public_id,
            'installation_address_id' => $this->installationAddress?->public_id,
            'code' => $this->code,
            'status' => $this->status,
            'activation_date' => $this->activation_date,
            'expiration_date' => $this->expiration_date,
            'billing_day' => $this->billing_day,
            'next_invoice_date' => $this->next_invoice_date,
            'ont_asset_id' => $this->ontAsset?->public_id,
            'serving_pop_id' => $this->servingPop?->public_id,
            'mrc_amount' => $this->mrc_amount,
            'otc_installation_fee' => $this->otc_installation_fee,
            'contract_months' => $this->contract_months,
            'notes' => $this->notes,
            'terminated_at' => $this->terminated_at,
            'terminated_reason' => $this->terminated_reason,
            'blocked_at' => $this->blocked_at,
            'block_reason' => $this->block_reason,
            'blocked_by' => $this->whenLoaded('blockedBy', fn () => $this->blockedBy ? ['id' => $this->blockedBy->public_id, 'name' => $this->blockedBy->name] : null),
            'package' => new ServicePackageResource($this->whenLoaded('servicePackage')),
            'package_version' => new ServicePackageVersionResource($this->whenLoaded('packageVersion')),
            // Whether the version the subscription was sold under is the newest one of its package: only where both are loaded.
            'package_version_is_latest' => $this->when(
                $this->relationLoaded('servicePackage') && $this->servicePackage?->relationLoaded('latestVersion'),
                fn (): bool => (int) $this->servicePackage->latestVersion?->id === (int) $this->service_package_version_id,
            ),
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'installation_address' => new CustomerAddressResource($this->whenLoaded('installationAddress')),
            'serving_pop' => new LocationResource($this->whenLoaded('servingPop')),
            'created_at' => $this->created_at,
        ];
    }
}
