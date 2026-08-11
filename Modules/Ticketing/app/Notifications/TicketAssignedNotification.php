<?php

namespace Modules\Ticketing\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $ticketId,
        public readonly string $ticketCode,
        public readonly ?int $companyId = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Ticket assigned: '.$this->ticketCode)
            ->line('You have been assigned ticket '.$this->ticketCode.'.')
            ->line('Ticket ID: '.$this->ticketId);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ticket_assigned',
            'ticket_id' => $this->ticketId,
            'ticket_code' => $this->ticketCode,
            'company_id' => $this->companyId,
        ];
    }
}
