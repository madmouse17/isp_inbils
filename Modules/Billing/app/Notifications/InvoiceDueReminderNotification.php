<?php

namespace Modules\Billing\Notifications;

use InvalidArgumentException;

/**
 * "Pengingat jatuh tempo": three days before, one day before and one day after the due date, while a balance
 * remains. The stage is part of the dedupe key together with the due date, so a re-run of the command sends
 * nothing twice and a rescheduled due date earns fresh reminders. A batch message.
 */
class InvoiceDueReminderNotification extends InvoiceWhatsAppNotification
{
    /** Stage => days from today (company time) to the due date. */
    public const STAGES = ['H-3' => 3, 'H-1' => 1, 'H+1' => -1];

    public function __construct(int $companyId, int $invoiceId, public readonly string $stage)
    {
        if (! array_key_exists($stage, self::STAGES)) {
            throw new InvalidArgumentException('Unknown reminder stage '.$stage.'.');
        }

        parent::__construct($companyId, $invoiceId);
    }

    public function toWhatsApp(object $notifiable): ?array
    {
        $invoice = $this->openInvoice();

        if ($invoice === null) {
            return null;
        }

        return $this->payload('invoice_reminder', [
            $this->companyName(),
            $invoice->number,
            $this->formatMoney($invoice->sisa),
            $this->formatDate($invoice->due_date),
            $this->appUrl(),
        ], 'invoice', $invoice->id, 'invoice_reminder:'.$invoice->id.':'.$this->stage.':'.$invoice->due_date->toDateString());
    }

    protected function event(): string
    {
        return 'invoice_reminder';
    }

    protected function pushesToApp(): bool
    {
        return true;
    }

    /**
     * The bell row and the push tell "due soon" (H-3, H-1) from "overdue" (H+1). The WhatsApp event remains
     * `invoice_reminder`, because that is the name of the approved Meta template.
     *
     * @param  array{event: string, template: string, language: string, parameters: list<string>, subject_type: ?string, subject_id: ?int, dedupe_key: string, batch: bool}  $payload
     * @return array<string, mixed>
     */
    protected function bell(array $payload): array
    {
        return [
            ...parent::bell($payload),
            'type' => $this->stage === 'H+1' ? 'invoice_overdue' : 'invoice_due_soon',
            'stage' => $this->stage,
        ];
    }
}
