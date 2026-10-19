<?php

namespace Modules\Billing\Listeners;

use App\Notifications\WhatsAppNotification;
use App\Services\Core\CompanyService;
use Illuminate\Support\Facades\Notification;
use Modules\Billing\Events\InvoiceIssued;
use Modules\Billing\Events\PaymentRecorded;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Notifications\InvoiceIssuedNotification;
use Modules\Billing\Notifications\PaymentReceivedNotification;
use Throwable;

/**
 * Turns the billing events into WhatsApp messages to the invoice's customer. Both events fire after the
 * transaction committed, so the invoice and the payment are there.
 */
class SendInvoiceWhatsAppMessages
{
    public function invoiceIssued(InvoiceIssued $event): void
    {
        $this->notifyCustomer($event->companyId, $event->invoiceId, new InvoiceIssuedNotification($event->companyId, $event->invoiceId));
    }

    public function paymentRecorded(PaymentRecorded $event): void
    {
        $this->notifyCustomer($event->companyId, $event->invoiceId, new PaymentReceivedNotification($event->companyId, $event->paymentId));
    }

    /**
     * A message is a side effect: failing to queue one is reported, never allowed to fail the invoice batch or
     * the payment that was just committed.
     */
    private function notifyCustomer(int $companyId, int $invoiceId, WhatsAppNotification $notification): void
    {
        try {
            CompanyService::runAs($companyId, function () use ($invoiceId, $notification): void {
                $customer = Invoice::query()->with('customer')->find($invoiceId)?->customer;

                // The relation still returns a deleted customer, for the history pages: nobody is messaged once deleted.
                if ($customer !== null && ! $customer->trashed()) {
                    Notification::send($customer, $notification);
                }
            });
        } catch (Throwable $e) {
            report($e);
        }
    }
}
