<?php

namespace Modules\Ticketing\Notifications;

use App\Notifications\Channels\FcmChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Ticketing\Models\Ticket;

class TicketAssignedNotification extends Notification implements ShouldQueueAfterCommit
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
        return ['mail', 'database', ...FcmChannel::for($notifiable)];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Tiket ditugaskan: '.$this->ticketCode)
            ->line('Tiket '.$this->ticketCode.' ditugaskan kepada Anda.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ticket_assigned',
            'ticket_id' => Ticket::withoutCompany()->whereKey($this->ticketId)->value('public_id'),
            'ticket_code' => $this->ticketCode,
            'company_id' => $this->companyId,
        ];
    }
}
