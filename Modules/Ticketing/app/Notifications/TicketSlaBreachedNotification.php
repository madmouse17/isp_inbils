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
            ->subject('SLA terlampaui: '.$this->ticketCode)
            ->line('Tiket '.$this->ticketCode.' melewati batas SLA '.$this->breachLabel().'.')
            ->line('ID tiket: '.$this->ticketId)
            ->line('ID perusahaan: '.($this->companyId ?? '-'));
    }

    private function breachLabel(): string
    {
        return match ($this->breachType) {
            'response' => 'respons',
            'resolution' => 'penyelesaian',
            default => $this->breachType,
        };
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
