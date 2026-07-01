<?php

namespace Modules\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'type' => $this->type,
            'source' => $this->source,
            'customer_id' => $this->customer_id,
            'subscription_id' => $this->subscription_id,
            'work_order_id' => $this->work_order_id,
            'issue_date' => $this->issue_date,
            'due_date' => $this->due_date,
            'billing_period_start' => $this->billing_period_start,
            'billing_period_end' => $this->billing_period_end,
            'status' => $this->status,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'discount_amount' => $this->discount_amount,
            'total' => $this->total,
            'paid_amount' => $this->paid_amount,
            'sisa' => $this->sisa,
            'notes' => $this->notes,
            'sent_at' => $this->sent_at,
            'cancelled_at' => $this->cancelled_at,
            'cancel_reason' => $this->cancel_reason,
            'created_at' => $this->created_at,
            'customer' => new \App\Http\Resources\CustomerResource($this->whenLoaded('customer')),
            'subscription' => new \App\Http\Resources\SubscriptionResource($this->whenLoaded('subscription')),
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}
