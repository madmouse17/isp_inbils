<?php

namespace Modules\Billing\Http\Resources;

use App\Http\Resources\CustomerResource;
use App\Http\Resources\SubscriptionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Billing\Models\Invoice;

/** @mixin Invoice */
class InvoiceResource extends JsonResource
{
    /** @return array<int|string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'number' => $this->number,
            'type' => $this->type,
            'source' => $this->source,
            'customer_id' => $this->customer?->public_id,
            'subscription_id' => $this->subscription?->public_id,
            'work_order_id' => $this->workOrder?->public_id,
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
            'sent_at' => $this->sent_at,
            'cancelled_at' => $this->cancelled_at,
            'cancel_reason' => $this->cancel_reason,
            'cancelled_by' => $this->canceller?->public_id,
            'created_at' => $this->created_at,
            // The party as printed when the invoice left draft; null for a draft, whose page shows the live customer.
            'customer_name_snapshot' => $this->customer_name_snapshot,
            // Only the invoice's own pages print the party's address and tax id and the internal note: the list reads none of
            // them, and a page that embeds invoices later gets none of them until it asks.
            $this->mergeWhen($request->routeIs('admin.invoices.show', 'admin.invoices.edit'), fn (): array => [
                'notes' => $this->notes,
                'customer_address_snapshot' => $this->customer_address_snapshot,
                'customer_tax_id_snapshot' => $this->customer_tax_id_snapshot,
            ]),
            'package_name_snapshot' => $this->package_name_snapshot,
            ...$this->lifecycleAttributes(),
            'customer' => $this->whenLoaded('customer', fn (): ?array => CustomerResource::summary($this->customer)),
            'subscription' => $this->whenLoaded('subscription', fn (): ?array => SubscriptionResource::summary($this->subscription)),
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}
