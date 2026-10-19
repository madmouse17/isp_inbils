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
        // The terms the subscription was sold under, not the package's current ones (spec .scratch/master-lifecycle D3).
        $version = $subscription->packageVersion;
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
            'package' => [
                'id' => $package->public_id,
                'name' => $package->name,
                'category' => [
                    'id' => $version->category->public_id,
                    'name' => $version->category->name,
                ],
                'download_mbps' => $version->bandwidthProfile->download_mbps,
                'upload_mbps' => $version->bandwidthProfile->upload_mbps,
                /** Marketing speed, e.g. "Hingga 50 Mbps": the maximum download of the speed profile. */
                'speed_label' => "Hingga {$version->speedProfile->download_max_mbps} Mbps",
            ],
            /** The service level the package was sold with. */
            'sla_tier' => [
                'name' => $version->slaTier->name,
                'response_time_hours' => $version->slaTier->response_time_hours,
                'resolution_time_hours' => $version->slaTier->resolution_time_hours,
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
