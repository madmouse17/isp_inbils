<?php

namespace Modules\Ticketing\Notifications;

use App\Notifications\Channels\FcmChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Ticketing\Models\Ticket;

class TicketSlaBreachedNotification extends Notification implements ShouldQueueAfterCommit
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
        return ['mail', 'database', ...FcmChannel::for($notifiable)];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('SLA terlampaui: '.$this->ticketCode)
            ->line('Tiket '.$this->ticketCode.' melewati batas SLA '.$this->breachLabel().'.');
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
            'ticket_id' => Ticket::withoutCompany()->whereKey($this->ticketId)->value('public_id'),
            'ticket_code' => $this->ticketCode,
            'breach_type' => $this->breachType,
            'company_id' => $this->companyId,
        ];
    }
}
