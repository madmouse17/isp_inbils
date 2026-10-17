<?php

namespace Modules\Billing\Notifications;

use App\Notifications\WhatsAppNotification;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;

/**
 * "Pembayaran diterima": one message per payments row, whether staff booked it or Midtrans settled it.
 * Not a batch message: the customer is waiting for it, so quiet hours do not hold it back.
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

    protected function event(): string
    {
        return 'payment_received';
    }

    protected function pushesToApp(): bool
    {
        return true;
    }
}
