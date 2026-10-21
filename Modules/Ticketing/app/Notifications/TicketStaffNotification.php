<?php

namespace Modules\Ticketing\Notifications;

use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Services\Core\CompanyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Ticketing\Models\Ticket;
use Spatie\Activitylog\Models\Activity;

/**
 * In the bell, and on the phone of staff who use the app, of whoever has to act when something reaches a ticket: a new
 * ticket (dispatch), a customer's reply (the handler and dispatch), a ticket that came back (a customer's reply on a resolved
 * ticket, or staff reopening a finished one). It carries ids; the ticket is read when the notice is sent, inside its
 * company. A reopening by staff also says who did it and why, which the bell and the push word: those are read when the
 * notice is sent, from the audit row it names, because a queued notification is stored in the job payload and a free-text
 * reason holds customer details.
 */
class TicketStaffNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    /** The longest customer name a lock screen gets to show. */
    private const NAME_LENGTH = 60;

    /** The longest reason a lock screen gets to show; the whole reason stays in the audit row. */
    private const REASON_LENGTH = 140;

    /** Stage => the `type` its bell row carries. */
    private const TYPES = [
        'created' => 'ticket_created',
        'customer_replied' => 'ticket_customer_replied',
        'reopened' => 'ticket_reopened',
    ];

    /**
     * @param  ?string  $by  stage `reopened` only: 'staff' when staff reopened the ticket; none (read as 'customer') when the customer's reply did
     * @param  ?int  $activityId  stage `reopened` by staff: the `ticket`/`reopened` audit row that holds who reopened it and why
     */
    public function __construct(
        public readonly int $companyId,
        public readonly int $ticketId,
        public readonly string $stage,
        public readonly ?string $by = null,
        public readonly ?int $activityId = null,
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

        if ($this->stage === 'reopened') {
            $data['by'] = $this->by === 'staff' ? 'staff' : 'customer';

            if ($data['by'] === 'staff') {
                $reopening = $this->activityId === null ? null : Activity::query()->with('causer')->where('company_id', $this->companyId)->find($this->activityId);
                $actor = $reopening?->causer instanceof User ? $reopening->causer->name : null;
                $reason = $reopening?->getExtraProperty('reason');

                $data['actor_name'] = $actor === null ? null : Str::limit($actor, self::NAME_LENGTH, '');
                $data['reason'] = is_string($reason) ? Str::limit($reason, self::REASON_LENGTH, '…') : null;
            }
        }

        return $data;
    }
}
