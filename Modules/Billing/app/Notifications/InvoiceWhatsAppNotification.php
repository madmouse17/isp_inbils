<?php

namespace Modules\Billing\Notifications;

use App\Notifications\WhatsAppNotification;
use App\Services\Core\SettingService;
use Modules\Billing\Models\Invoice;

/**
 * A WhatsApp message about one invoice that is still to be paid: carries its id only.
 *
 * @phpstan-import-type Payload from WhatsAppNotification
 */
abstract class InvoiceWhatsAppNotification extends WhatsAppNotification
{
    /** Statuses of an invoice the customer still has to pay (the balance is checked as well). */
    public const AWAITING_PAYMENT = ['sent', 'partial', 'overdue'];

    public function __construct(int $companyId, public readonly int $invoiceId)
    {
        parent::__construct($companyId);
    }

    /** Where the template's link points now that invoices live in the customer app: its Play Store listing, else the sign-in page. */
    protected function appUrl(): string
    {
        return SettingService::playStoreUrl('customer') ?: route('login');
    }

    /**
     * The bell and the push say which invoice, how much and by when (a row written before these keys keeps the generic
     * text, NotificationResource).
     *
     * @param  Payload  $payload
     * @return array<string, mixed>
     */
    protected function bell(array $payload): array
    {
        $invoice = Invoice::forCompany($this->companyId)->find($this->invoiceId);

        return parent::bell($payload) + ($invoice === null ? [] : [
            'invoice_number' => $invoice->number,
            'total' => $invoice->total,
            'outstanding' => $invoice->sisa,
            'due_date' => $invoice->due_date->toDateString(),
        ]);
    }

    /**
     * The invoice, but only while it still owes money. Paid, cancelled or back in draft by the time the job
     * runs (a delayed message, a reminder queued the night before) means there is nothing left to say.
     */
    protected function openInvoice(): ?Invoice
    {
        return Invoice::query()
            ->whereKey($this->invoiceId)
            ->whereIn('status', self::AWAITING_PAYMENT)
            ->whereColumn('paid_amount', '<', 'total')
            ->first();
    }
}
