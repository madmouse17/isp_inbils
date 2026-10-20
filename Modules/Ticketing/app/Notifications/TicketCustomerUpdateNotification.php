<?php

namespace Modules\Ticketing\Notifications;

use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\WhatsAppNotification;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Ticketing\Models\Ticket;

/**
 * What the customer of a ticket hears as it moves: registered, assigned, started, answered, resolved, closed. Every stage is
 * a bell row and, for a customer with the app, a push. Only "tiket dibuat", "sedang ditangani" (the assignment) and "selesai"
 * are also WhatsApp templates; no template is approved for the other stages, so they send no WhatsApp. Carries ids only.
 * Event-driven, not a batch: a person is waiting for it, so quiet hours do not hold it back.
 */
class TicketCustomerUpdateNotification extends WhatsAppNotification
{
    public const STAGES = ['created', 'assigned', 'started', 'replied', 'resolved', 'closed'];

    /** The stages that have a template in config('whatsapp.events'). */
    private const WHATSAPP_STAGES = ['created', 'assigned', 'resolved'];

    public function __construct(int $companyId, public readonly int $ticketId, public readonly string $stage)
    {
        if (! in_array($stage, self::STAGES, true)) {
            throw new InvalidArgumentException('Unknown ticket stage '.$stage.'.');
        }

        parent::__construct($companyId);
    }

    /** @return list<class-string|string> */
    public function via(object $notifiable): array
    {
        $channels = parent::via($notifiable);

        return $this->hasTemplate() ? $channels : array_values(array_diff($channels, [WhatsAppChannel::class]));
    }

    /** The bell row and the push exist while the ticket does; a stage with no template has no WhatsApp payload to wait for. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $this->hasTemplate()
            ? parent::shouldSend($notifiable, $channel)
            : Ticket::withoutCompany()->where('company_id', $this->companyId)->whereKey($this->ticketId)->exists();
    }

    public function toWhatsApp(object $notifiable): ?array
    {
        if (! $this->hasTemplate()) {
            return null;
        }

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

        return $this->payload($this->event(), $parameters, 'ticket', $ticket, $this->event().':'.$ticket->id);
    }

    /**
     * The bell row and the push: the stage, the ticket's public id and its code, which the words of the bell carry.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $ticket = Ticket::withoutCompany()->where('company_id', $this->companyId)->find($this->ticketId, ['id', 'public_id', 'code']);

        return ['type' => $this->event()]
            + ($ticket === null ? [] : ['ticket_id' => $ticket->public_id, 'ticket_code' => $ticket->code]);
    }

    protected function event(): string
    {
        return 'ticket_'.$this->stage;
    }

    protected function pushesToApp(): bool
    {
        return true;
    }

    private function hasTemplate(): bool
    {
        return in_array($this->stage, self::WHATSAPP_STAGES, true);
    }
}
