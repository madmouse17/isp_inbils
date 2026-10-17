<?php

namespace Modules\Ticketing\Notifications;

use App\Notifications\Channels\FcmChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;

/**
 * In the bell and on the phone of the technician a ticket is assigned to: its first-response target is 30 minutes away or
 * already missed and nobody has started the ticket. One per ticket (CheckTicketSlaBreachesJob claims it), ids and the
 * code only.
 */
class TicketFirstResponseDueNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        public readonly int $ticketId,
        public readonly string $ticketCode,
        public readonly bool $breached,
        public readonly ?int $companyId = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', ...FcmChannel::for($notifiable)];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ticket_first_response_due',
            'ticket_id' => $this->ticketId,
            'ticket_code' => $this->ticketCode,
            'state' => $this->breached ? 'breached' : 'due_soon',
            'company_id' => $this->companyId,
        ];
    }
}
