<?php

namespace Modules\Billing\Notifications;

use App\Notifications\WhatsAppNotification;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * "Pengingat jatuh tempo": three days before, one day before and one day after the due date, while a balance
 * remains. The stage is part of the dedupe key together with the due date, so a re-run of the command sends
 * nothing twice and a rescheduled due date earns fresh reminders. A batch message.
 *
 * Staff can also send one by hand (stage `manual`, "Kirim pengingat"): it is not one of STAGES, so the daily command
 * never sends it, and its key holds the day instead of the due date, so each day's reminder is its own message.
 *
 * @phpstan-import-type Payload from WhatsAppNotification
 */
class InvoiceDueReminderNotification extends InvoiceWhatsAppNotification
{
    /** Stage => days from today (company time) to the due date. */
    public const STAGES = ['H-3' => 3, 'H-1' => 1, 'H+1' => -1];

    /** The stage of a reminder staff sent by hand. */
    public const MANUAL = 'manual';

    public function __construct(int $companyId, int $invoiceId, public readonly string $stage)
    {
        if (! array_key_exists($stage, self::STAGES) && $stage !== self::MANUAL) {
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
        ], 'invoice', $invoice, 'invoice_reminder:'.$invoice->id.':'.$this->stage.':'.($this->stage === self::MANUAL ? CarbonImmutable::now($this->companyTimezone())->toDateString() : $invoice->due_date->toDateString()));
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
     * @param  Payload  $payload
     * @return array<string, mixed>
     */
    protected function bell(array $payload): array
    {
        $bell = parent::bell($payload);
        // A manual reminder is overdue when the due date is behind the company's today; the scheduled ones know their stage.
        $overdue = $this->stage === 'H+1' || ($this->stage === self::MANUAL && isset($bell['due_date']) && $bell['due_date'] < CarbonImmutable::now($this->companyTimezone())->toDateString());

        return [
            ...$bell,
            'type' => $overdue ? 'invoice_overdue' : 'invoice_due_soon',
            'stage' => $this->stage,
        ];
    }
}
