<?php

namespace Modules\Ticketing\Jobs;

use App\Models\Core\Company;
use App\Support\Notifications\StaffRecipients;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Notifications\TicketFirstResponseDueNotification;
use Modules\Ticketing\Notifications\TicketSlaBreachedNotification;
use Modules\Ticketing\Services\TicketService;
use Throwable;

/**
 * The SLA of every ticket, every 15 minutes. Whoever must act hears of it in the bell and on the phone, once per notice:
 * the handler of the ticket and dispatch (the holders of ticket.assign), never the customer and never by mail. Each notice
 * has its own marker on the ticket, set under the row lock, so a rerun or a retry sends nothing twice.
 */
class CheckTicketSlaBreachesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** A ticket still waiting for its first response is announced to whoever must answer it this long before the target. */
    private const RESPONSE_DUE_WINDOW_MINUTES = 30;

    /** The statuses in which a ticket can still be answered for the first time. */
    private const UNANSWERED_STATUSES = ['open', 'assigned'];

    public int $uniqueFor = 300;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(private readonly ?int $companyId = null) {}

    public function uniqueId(): string
    {
        return 'check-ticket-sla-breaches:'.($this->companyId ?? 'all');
    }

    public function handle(): void
    {
        $companyIds = $this->companyId === null
            ? Company::query()->where('is_active', true)->pluck('id')
            : collect([$this->companyId]);

        $this->notifyResolutionBreaches($companyIds);
        $this->notifyResponseBreaches($companyIds);
        $this->notifyResponseDue($companyIds);
    }

    /** The queue listener reports the exception itself; this line adds which company the check was for. */
    public function failed(Throwable $e): void
    {
        Log::warning('tickets.sla_check_failed', ['company_id' => $this->companyId]);
    }

    /**
     * A ticket still open past its resolution target.
     *
     * @param  Collection<int, int>  $companyIds
     */
    private function notifyResolutionBreaches(Collection $companyIds): void
    {
        $tickets = Ticket::withoutCompany()
            ->whereIn('company_id', $companyIds)
            ->whereNotNull('sla_deadline')
            ->where('sla_deadline', '<=', now())
            ->whereNotIn('status', ['resolved', 'closed'])
            ->whereNull('sla_breached_notified_at')
            ->get();

        foreach ($tickets as $ticket) {
            // Atomic claim: lock row inside transaction to prevent double notify under concurrent job runs/retries.
            DB::transaction(function () use ($ticket) {
                $claimed = Ticket::withoutCompany()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

                // Resolved or already announced since the selection above.
                if ($claimed->sla_breached_notified_at !== null || in_array($claimed->status, ['resolved', 'closed'], true)) {
                    return;
                }

                $claimed->sla_breached_notified_at = now();
                $claimed->save();

                Cache::put("ticket:sla-breach:{$claimed->id}:resolution", true, now()->addDay());

                Notification::send(
                    TicketService::staffWatching($claimed),
                    new TicketSlaBreachedNotification($claimed->id, $claimed->code, 'resolution', $claimed->company_id),
                );
            });
        }
    }

    /**
     * A ticket nobody has answered past its first-response target.
     *
     * @param  Collection<int, int>  $companyIds
     */
    private function notifyResponseBreaches(Collection $companyIds): void
    {
        $tickets = Ticket::withoutCompany()
            ->whereIn('company_id', $companyIds)
            ->whereIn('status', self::UNANSWERED_STATUSES)
            ->whereNull('first_response_at')
            ->whereNull('response_breached_notified_at')
            ->where('response_deadline', '<=', now())
            ->get();

        foreach ($tickets as $ticket) {
            DB::transaction(function () use ($ticket) {
                $claimed = Ticket::withoutCompany()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

                // Answered, taken out of the open statuses or already announced since the selection above.
                if ($claimed->response_breached_notified_at !== null || $claimed->first_response_at !== null || ! in_array($claimed->status, self::UNANSWERED_STATUSES, true)) {
                    return;
                }

                $claimed->response_breached_notified_at = now();
                $claimed->save();

                Notification::send(
                    TicketService::staffWatching($claimed),
                    new TicketSlaBreachedNotification($claimed->id, $claimed->code, 'response', $claimed->company_id),
                );
            });
        }
    }

    /**
     * The handler of an assigned ticket, or dispatch for one nobody has been given, hears once that the first-response
     * target is near. A target already missed is the breach above, not this.
     *
     * @param  Collection<int, int>  $companyIds
     */
    private function notifyResponseDue(Collection $companyIds): void
    {
        $now = now();

        $tickets = Ticket::withoutCompany()
            ->whereIn('company_id', $companyIds)
            ->whereIn('status', self::UNANSWERED_STATUSES)
            ->whereNull('first_response_at')
            ->whereNull('response_due_notified_at')
            ->where('response_deadline', '>', $now)
            ->where('response_deadline', '<=', $now->copy()->addMinutes(self::RESPONSE_DUE_WINDOW_MINUTES))
            // An assigned ticket warns its handler, so one whose handler is inactive stays unannounced.
            ->where(fn ($ticket) => $ticket->where('status', 'open')
                ->orWhereHas('assignee', fn ($handler) => $handler->where('is_active', true)))
            ->get();

        foreach ($tickets as $ticket) {
            DB::transaction(function () use ($ticket) {
                $claimed = Ticket::withoutCompany()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

                // Answered, taken out of the open statuses, announced or already late since the selection above.
                if ($claimed->response_due_notified_at !== null
                    || $claimed->first_response_at !== null
                    || ! in_array($claimed->status, self::UNANSWERED_STATUSES, true)
                    || $claimed->response_deadline === null
                    || $claimed->response_deadline->isPast()) {
                    return;
                }

                $claimed->response_due_notified_at = now();
                $claimed->save();

                Notification::send(
                    $claimed->status === 'assigned'
                        ? collect([$claimed->assignee])->filter()
                        : StaffRecipients::holding((int) $claimed->company_id, 'ticket.assign'),
                    new TicketFirstResponseDueNotification($claimed->id, $claimed->code, $claimed->company_id),
                );
            });
        }
    }
}
