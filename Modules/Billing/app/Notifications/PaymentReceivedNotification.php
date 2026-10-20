<?php

namespace Modules\Billing\Notifications;

use App\Notifications\WhatsAppNotification;
use App\Services\Core\CompanyService;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Services\OverdueSuspensionService;

/**
 * "Pembayaran diterima": one message per payments row, whether staff booked it or Midtrans settled it.
 * Not a batch message: the customer is waiting for it, so quiet hours do not hold it back.
 *
 * When the payment leaves other bills that still hold an isolated service, the bell and the push name them (K5).
 */
class PaymentReceivedNotification extends WhatsAppNotification
{
    public function __construct(int $companyId, public readonly int $paymentId)
    {
        parent::__construct($companyId);
    }

    public function toWhatsApp(object $notifiable): ?array
    {
        $payment = Payment::query()->find($this->paymentId);
        $invoice = $payment === null ? null : Invoice::query()->find($payment->invoice_id);

        // Reversed before the message went out: there is nothing to confirm any more.
        if ($payment === null || $payment->cancelled_at !== null || $invoice === null) {
            return null;
        }

        return $this->payload('payment_received', [
            $this->companyName(),
            $invoice->number,
            $this->formatMoney($payment->amount),
            $this->formatMoney($invoice->sisa),
            $this->formatDate($payment->paid_at->copy()->setTimezone($this->companyTimezone())),
        ], 'invoice', $invoice, 'payment_received:'.$payment->id);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function bell(array $payload): array
    {
        return parent::bell($payload) + CompanyService::runAs($this->companyId, fn (): array => $this->holds());
    }

    /**
     * Read when the row is written: the payment's invoice number and the numbers of the bills still holding its
     * subscription's reconnection, or nothing when none does.
     *
     * @return array{invoice_number?: string, holding_invoice_numbers?: list<string>}
     */
    private function holds(): array
    {
        $payment = Payment::query()->find($this->paymentId);
        $invoice = $payment === null ? null : Invoice::query()->with('subscription')->find($payment->invoice_id);

        if ($invoice?->subscription === null) {
            return [];
        }

        $holding = OverdueSuspensionService::holdingInvoices($invoice->subscription)->pluck('number')->all();

        return $holding === [] ? [] : ['invoice_number' => $invoice->number, 'holding_invoice_numbers' => $holding];
    }

    protected function event(): string
    {
        return 'payment_received';
    }

    protected function pushesToApp(): bool
    {
        return true;
    }
}
