<?php

namespace Modules\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'invoice_id' => $this->invoice?->public_id,
            'amount' => $this->amount,
            'method' => $this->method,
            'reference' => $this->reference,
            'paid_at' => $this->paid_at,
            'received_by' => $this->receiver?->public_id,
            'notes' => $this->notes,
            'cancelled_at' => $this->cancelled_at,
            'cancel_reason' => $this->cancel_reason,
            'cancelled_by' => $this->canceller?->public_id,
        ];
    }
}
