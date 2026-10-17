<?php

namespace Modules\Billing\Notifications;

/**
 * "Tagihan terbit": the invoice number, total, due date and the link to the customer app. A batch message, so it waits
 * out the company's quiet hours.
 */
class InvoiceIssuedNotification extends InvoiceWhatsAppNotification
{
    public function toWhatsApp(object $notifiable): ?array
    {
        $invoice = $this->openInvoice();

        if ($invoice === null) {
            return null;
        }

        return $this->payload('invoice_issued', [
            $this->companyName(),
            $invoice->number,
            $this->formatMoney($invoice->total),
            $this->formatDate($invoice->due_date),
            $this->appUrl(),
        ], 'invoice', $invoice->id, 'invoice_issued:'.$invoice->id);
    }

    protected function event(): string
    {
        return 'invoice_issued';
    }

    protected function pushesToApp(): bool
    {
        return true;
    }
}
