<?php

namespace Modules\Ticketing\Notifications;

use App\Notifications\WhatsAppNotification;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Ticketing\Models\Ticket;

/**
 * WhatsApp to the customer of a ticket: registered ("tiket dibuat"), taken by a handler for the first time
 * ("sedang ditangani") and resolved ("selesai"). Carries ids only. Event-driven, not a batch: a person is
 * waiting for it, so quiet hours do not hold it back.
 */
class TicketCustomerUpdateNotification extends WhatsAppNotification
{
    public const STAGES = ['created', 'assigned', 'resolved'];

    public function __construct(int $companyId, public readonly int $ticketId, public readonly string $stage)
    {
        if (! in_array($stage, self::STAGES, true)) {
            throw new InvalidArgumentException('Unknown ticket stage '.$stage.'.');
        }

        parent::__construct($companyId);
    }

    public function toWhatsApp(object $notifiable): ?array
    {
        $ticket = Ticket::query()->with('assignee')->find($this->ticketId);

        if ($ticket === null) {
            return null;
        }

        $parameters = [$this->companyName(), $ticket->code, Str::limit($ticket->title, 60)];

        $parameters = match ($this->stage) {
            'created' => $parameters,
            'assigned' => [...$parameters, Str::of($ticket->assignee->name ?? 'petugas kami')->trim()->before(' ')->toString()],
            default => [...$parameters, $this->formatDate(($ticket->resolved_at ?? now())->copy()->setTimezone($this->companyTimezone()))],
        };

        return $this->payload($this->event(), $parameters, 'ticket', $ticket->id, $this->event().':'.$ticket->id);
    }

    protected function event(): string
    {
        return 'ticket_'.$this->stage;
    }
}
