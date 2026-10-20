<?php

namespace Modules\Ticketing\Notifications;

use App\Notifications\Channels\FcmChannel;
use App\Services\Core\CompanyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Ticketing\Models\Ticket;

/**
 * In the bell, and on the phone of staff who use the app, of whoever has to act when something reaches a ticket: a new
 * ticket (dispatch), a customer's reply (the handler and dispatch), a reply that brought a resolved ticket back. It
 * carries ids only; the ticket is read when the notice is sent, inside its company.
 */
class TicketStaffNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    /** The longest customer name a lock screen gets to show. */
    private const NAME_LENGTH = 60;

    /** Stage => the `type` its bell row carries. */
    private const TYPES = [
        'created' => 'ticket_created',
        'customer_replied' => 'ticket_customer_replied',
        'reopened' => 'ticket_reopened',
    ];

    public function __construct(
        public readonly int $companyId,
        public readonly int $ticketId,
        public readonly string $stage,
    ) {
        if (! array_key_exists($stage, self::TYPES)) {
            throw new InvalidArgumentException('Unknown ticket staff stage '.$stage.'.');
        }
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', ...FcmChannel::for($notifiable)];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $ticket = CompanyService::runAs(
            $this->companyId,
            fn (): ?Ticket => Ticket::query()->with('customer:id,name')->find($this->ticketId),
        );

        $data = [
            'type' => self::TYPES[$this->stage],
            'ticket_id' => $ticket?->public_id,
            'ticket_code' => $ticket?->code,
            'company_id' => $this->companyId,
        ];

        // Only a new ticket says who it comes from.
        if ($this->stage === 'created') {
            $data['customer_name'] = $ticket?->customer === null ? null : Str::limit($ticket->customer->name, self::NAME_LENGTH, '');
        }

        return $data;
    }
}
