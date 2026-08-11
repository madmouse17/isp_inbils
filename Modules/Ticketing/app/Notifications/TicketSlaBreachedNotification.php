<?php

namespace Modules\Ticketing\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketSlaBreachedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $ticketId,
        public readonly string $ticketCode,
        public readonly string $breachType,
        public readonly ?int $companyId = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('SLA breached: '.$this->ticketCode)
            ->line('Ticket '.$this->ticketCode.' has breached SLA ('.$this->breachType.').')
            ->line('Ticket ID: '.$this->ticketId)
            ->line('Company ID: '.($this->companyId ?? 'n/a'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ticket_sla_breached',
            'ticket_id' => $this->ticketId,
            'ticket_code' => $this->ticketCode,
            'breach_type' => $this->breachType,
            'company_id' => $this->companyId,
        ];
    }
}
