<?php

namespace Modules\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceItem;
use Modules\Billing\Models\Payment;

/**
 * What a customer may see of their own invoice. Deliberately not InvoiceResource: no internal notes,
 * actors, work order or subscription links, cancel reasons, payment references or gateway data.
 *
 * @mixin Invoice
 */
class PortalInvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'issue_date' => $this->issue_date->toDateString(),
            'due_date' => $this->due_date->toDateString(),
            'status' => $this->status,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'discount_amount' => $this->discount_amount,
            'total' => $this->total,
            'paid_amount' => $this->paid_amount,
            'sisa' => $this->sisa,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (InvoiceItem $item): array => [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'discount_amount' => $item->discount_amount,
                'tax_rate' => $item->tax_rate,
                'line_total' => $item->line_total,
            ])->all()),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn (Payment $payment): array => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'method' => $payment->method,
                'paid_at' => $payment->paid_at->toIso8601String(),
            ])->all()),
        ];
    }
}
