<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'service_package_id' => $this->service_package_id,
            'installation_address_id' => $this->installation_address_id,
            'code' => $this->code,
            'status' => $this->status,
            'activation_date' => $this->activation_date,
            'expiration_date' => $this->expiration_date,
            'billing_day' => $this->billing_day,
            'next_invoice_date' => $this->next_invoice_date,
            'ont_asset_id' => $this->ont_asset_id,
            'serving_pop_id' => $this->serving_pop_id,
            'mrc_amount' => $this->mrc_amount,
            'otc_installation_fee' => $this->otc_installation_fee,
            'contract_months' => $this->contract_months,
            'notes' => $this->notes,
            'terminated_at' => $this->terminated_at,
            'terminated_reason' => $this->terminated_reason,
            'package' => new \Modules\Service\Http\Resources\ServicePackageResource($this->whenLoaded('servicePackage')),
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'installation_address' => new CustomerAddressResource($this->whenLoaded('installationAddress')),
            'serving_pop' => new \App\Http\Resources\LocationResource($this->whenLoaded('servingPop')),
            'created_at' => $this->created_at,
        ];
    }
}
