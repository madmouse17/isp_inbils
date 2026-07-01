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
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'amount' => $this->amount,
            'method' => $this->method,
            'reference' => $this->reference,
            'paid_at' => $this->paid_at,
            'received_by' => $this->received_by,
            'notes' => $this->notes,
            'cancelled_at' => $this->cancelled_at,
            'cancel_reason' => $this->cancel_reason,
        ];
    }
}
