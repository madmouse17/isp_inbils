<?php

namespace Modules\Billing\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;

/** @mixin Payment */
class CustomerPaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->invoice;

        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'method' => $this->method,
            'method_label' => $this->methodLabel(),
            /** The gateway order id for an online payment; null for a manual one, whose staff-typed reference stays internal. */
            'reference' => $this->customerReference(),
            'paid_at' => $this->paid_at->toIso8601String(),
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
            ],
        ];
    }
}
