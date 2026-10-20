<?php

namespace Modules\Billing\Notifications;

use App\Notifications\Channels\FcmChannel;
use App\Services\Core\CompanyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\Billing\Models\Invoice;

/**
 * In the bell and on the phone of the billing staff: the approval of an installation SPK left its invoice as a draft
 * (the company does not send installation invoices by itself), so somebody has to check it and send it. It carries ids
 * only; the row is read when it is sent, inside the invoice's company.
 */
class InstallationInvoiceDraftNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $companyId,
        public readonly int $invoiceId,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', ...FcmChannel::for($notifiable)];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $invoice = CompanyService::runAs(
            $this->companyId,
            fn (): Invoice => Invoice::query()->with('customer:id,name')->findOrFail($this->invoiceId),
        );

        return [
            'type' => 'invoice_draft_pending',
            'invoice_id' => $invoice->public_id,
            'invoice_number' => $invoice->number,
            'customer_name' => $invoice->customer?->name,
            'total' => $invoice->total,
            'company_id' => $this->companyId,
        ];
    }
}
