<?php

namespace Modules\Ticketing\Notifications;

use App\Notifications\Channels\FcmChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;
use Modules\Ticketing\Models\Ticket;

/**
 * In the bell and on the phone of the handler and of dispatch: a ticket missed its first-response target ('response') or
 * its resolution target ('resolution'). Never to the customer and never by mail. One per ticket and kind (the job claims it).
 */
class TicketSlaBreachedNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        public readonly int $ticketId,
        public readonly string $ticketCode,
        public readonly string $kind,
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
            'type' => 'ticket_sla_breached',
            'ticket_id' => Ticket::withoutCompany()->whereKey($this->ticketId)->value('public_id'),
            'ticket_code' => $this->ticketCode,
            'kind' => $this->kind,
            'company_id' => $this->companyId,
        ];
    }
}
