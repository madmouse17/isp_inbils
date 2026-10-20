<?php

namespace Modules\Ticketing\Notifications;

use App\Notifications\Channels\FcmChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;
use Modules\Ticketing\Models\Ticket;

/**
 * In the bell and on the phone of whoever has to answer a ticket nobody has answered yet: its first-response target is 30
 * minutes away. The handler hears it for an assigned ticket, dispatch for one nobody has been given. One per ticket
 * (CheckTicketSlaBreachesJob claims it); a target already missed is a ticket_sla_breached notice instead. Ids and the code only.
 */
class TicketFirstResponseDueNotification extends Notification implements ShouldQueueAfterCommit
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
        return ['database', ...FcmChannel::for($notifiable)];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ticket_first_response_due',
            'ticket_id' => Ticket::withoutCompany()->whereKey($this->ticketId)->value('public_id'),
            'ticket_code' => $this->ticketCode,
            'company_id' => $this->companyId,
        ];
    }
}
