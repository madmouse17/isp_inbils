<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property ServiceSubscription $resource */
class CustomerSubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subscription = $this->resource;
        $package = $subscription->servicePackage;
        $tier = $package?->slaTier;
        $address = $subscription->installationAddress;

        return [
            'id' => $subscription->public_id,
            'code' => $subscription->code,
            'status' => $subscription->status,
            /**
             * Why a suspended subscription is on hold; null for any other status. `overdue` lifts by itself once the unpaid
             * bills are paid, `manual` waits for the ISP. A suspension recorded before the reason existed is null too.
             *
             * @var 'overdue'|'manual'|null
             */
            'suspension_reason' => $subscription->status === 'suspended' ? $subscription->suspension_source : null,
            'suspended_at' => $subscription->suspended_at?->toIso8601String(),
            'package' => $package === null ? null : [
                'id' => $package->public_id,
                'name' => $package->name,
                'category' => [
                    'id' => $package->category->public_id,
                    'name' => $package->category->name,
                ],
                'download_mbps' => $package->bandwidthProfile?->download_mbps,
                'upload_mbps' => $package->bandwidthProfile?->upload_mbps,
                /** Marketing speed, e.g. "Hingga 50 Mbps": speed profile max download, else bandwidth download; null when neither profile exists. */
                'speed_label' => ($mbps = $package->speedProfile?->download_max_mbps ?? $package->bandwidthProfile?->download_mbps) === null ? null : "Hingga {$mbps} Mbps",
            ],
            /** The package's service level; null when the package has none. */
            'sla_tier' => $tier === null ? null : [
                'name' => $tier->name,
                'response_time_hours' => $tier->response_time_hours,
                'resolution_time_hours' => $tier->resolution_time_hours,
            ],
            /** The PPPoE login the customer types into the router. Never the password. */
            'pppoe_username' => $subscription->pppoeAccount?->username,
            'mrc_amount' => $subscription->mrc_amount,
            'installation_address' => $address instanceof CustomerAddress ? [
                'address' => $address->address,
                'city' => $address->city,
                'label' => $address->label,
                'lat' => $address->lat,
                'lng' => $address->lng,
            ] : null,
            'activation_date' => $subscription->activation_date?->toDateString(),
            'billing_day' => $subscription->billing_day,
            'next_invoice_date' => $subscription->next_invoice_date?->toDateString(),
            'contract_months' => $subscription->contract_months,
            'expiration_date' => $subscription->expiration_date?->toDateString(),
        ];
    }
}
