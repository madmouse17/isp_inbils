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
        $address = $subscription->installationAddress;

        return [
            'id' => $subscription->id,
            'code' => $subscription->code,
            'status' => $subscription->status,
            /**
             * Why a suspended subscription is on hold; null for any other status. `overdue` lifts by itself once the unpaid
             * bills are paid, `manual` waits for the ISP. A suspension recorded before the reason existed is null too.
             *
             * @var 'overdue'|'manual'|null
             */
            'suspension_reason' => $subscription->status === 'suspended' ? $subscription->suspension_source : null,
            'package' => $package === null ? null : [
                'id' => $package->id,
                'name' => $package->name,
                'category' => [
                    'id' => $package->category->id,
                    'name' => $package->category->name,
                ],
            ],
            'mrc_amount' => $subscription->mrc_amount,
            'installation_address' => $address instanceof CustomerAddress ? [
                'address' => $address->address,
                'city' => $address->city,
            ] : null,
            'activation_date' => $subscription->activation_date?->toDateString(),
            'billing_day' => $subscription->billing_day,
            'next_invoice_date' => $subscription->next_invoice_date?->toDateString(),
            'contract_months' => $subscription->contract_months,
            'expiration_date' => $subscription->expiration_date?->toDateString(),
        ];
    }
}
