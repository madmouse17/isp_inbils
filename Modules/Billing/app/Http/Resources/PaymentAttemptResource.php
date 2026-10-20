<?php

namespace Modules\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Billing\Models\PaymentAttempt;

/**
 * Staff view of an online payment attempt. The Snap token, its URL and the stored notification are
 * left out: whoever holds the URL can pay the invoice.
 *
 * @mixin PaymentAttempt
 */
class PaymentAttemptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'order_id' => $this->order_id,
            'status' => $this->status,
            'status_reason' => $this->status_reason,
            'amount' => $this->amount,
            'transaction_id' => $this->transaction_id,
            'payment_type' => $this->payment_type,
            'payment_id' => $this->payment?->public_id,
            'expires_at' => $this->expires_at->toIso8601String(),
            'settled_at' => $this->settled_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            /** Who closed an exception as refunded, when, and the reference and note they gave; null for any other status. */
            'refund' => $this->status === PaymentAttempt::STATUS_REFUNDED ? [
                'at' => $this->resolved_at?->toIso8601String(),
                'by' => $this->resolver?->name,
                'reference' => $this->resolution_reference,
                'note' => $this->resolution_note,
            ] : null,
        ];
    }
}
