<?php

namespace Modules\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceItem;
use Modules\Billing\Models\Payment;
use Modules\Billing\Support\InvoiceParty;

/**
 * What a customer may see of their own invoice. Deliberately not InvoiceResource: no internal notes,
 * actors, work order link, cancel reasons, staff-typed payment references or gateway data. `subscription_id` is there so the
 * app can show each subscription its own bill; it is null for an invoice that belongs to no single subscription.
 *
 * @mixin Invoice
 */
class PortalInvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $party = InvoiceParty::of($this->resource);

        return [
            'id' => $this->public_id,
            'number' => $this->number,
            /** The subscription this invoice bills; null when it belongs to no single subscription. */
            'subscription_id' => $this->subscription?->public_id,
            /** The customer's name as printed when the invoice was issued; a later rename does not change it. */
            'customer_name' => $party['customer_name'],
            /** @var string|null The customer's address as printed when the invoice was issued; null when the customer had none. */
            'customer_address' => $party['customer_address'],
            /** @var string|null The package name as printed when the invoice was issued; null for an invoice without a subscription. */
            'package_name' => $party['package_name'],
            'issue_date' => $this->issue_date->toDateString(),
            'due_date' => $this->due_date->toDateString(),
            /** The service period the invoice bills; null for an invoice without one. */
            'billing_period' => $this->billing_period_start === null || $this->billing_period_end === null ? null : [
                'start' => $this->billing_period_start->toDateString(),
                'end' => $this->billing_period_end->toDateString(),
            ],
            'status' => $this->status,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'discount_amount' => $this->discount_amount,
            'total' => $this->total,
            'paid_amount' => $this->paid_amount,
            'sisa' => $this->sisa,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (InvoiceItem $item): array => [
                'id' => $item->public_id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'discount_amount' => $item->discount_amount,
                'tax_rate' => $item->tax_rate,
                'line_total' => $item->line_total,
            ])->all()),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn (Payment $payment): array => [
                'id' => $payment->public_id,
                'amount' => $payment->amount,
                'method' => $payment->method,
                'method_label' => $payment->methodLabel(),
                /** The gateway order id for an online payment; null for a manual one, whose staff-typed reference stays internal. */
                'reference' => $payment->customerReference(),
                'paid_at' => $payment->paid_at->toIso8601String(),
            ])->all()),
        ];
    }
}
