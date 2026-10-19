<?php

namespace Modules\Ticketing\Jobs;

use App\Models\Core\Company;
use App\Models\User;
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
use Throwable;

class CheckTicketSlaBreachesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** A ticket still waiting for its first response is announced to its handler this long before the target, or after it. */
    private const RESPONSE_DUE_WINDOW_MINUTES = 30;

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
                $claimed = Ticket::withoutCompany()
                    ->whereKey($ticket->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($claimed->sla_breached_notified_at !== null) {
                    return;
                }

                $claimed->sla_breached_notified_at = now();
                $claimed->save();

                Cache::put("ticket:sla-breach:{$claimed->id}:resolution", true, now()->addDay());

                if ($claimed->created_by !== null) {
                    Notification::send(
                        User::query()->find($claimed->created_by),
                        new TicketSlaBreachedNotification($claimed->id, $claimed->code, 'resolution', $claimed->company_id)
                    );
                }
            });
        }

        $this->notifyResponseDue($companyIds);
    }

    /** The queue listener reports the exception itself; this line adds which company the check was for. */
    public function failed(Throwable $e): void
    {
        Log::warning('tickets.sla_check_failed', ['company_id' => $this->companyId]);
    }

    /**
     * The handler of an assigned ticket nobody has started hears once that the first-response target is near or missed.
     * The same claim as the breach above: the marker is set under the row lock, so a rerun or a retry sends nothing.
     *
     * @param  Collection<int, int>  $companyIds
     */
    private function notifyResponseDue(Collection $companyIds): void
    {
        $tickets = Ticket::withoutCompany()
            ->whereIn('company_id', $companyIds)
            ->where('status', 'assigned')
            ->whereNull('first_response_at')
            ->whereNull('response_due_notified_at')
            ->where('response_deadline', '<=', now()->addMinutes(self::RESPONSE_DUE_WINDOW_MINUTES))
            ->whereHas('assignee', fn ($handler) => $handler->where('is_active', true))
            ->get();

        foreach ($tickets as $ticket) {
            DB::transaction(function () use ($ticket) {
                $claimed = Ticket::withoutCompany()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

                // Started, resolved or already announced since the selection above.
                if ($claimed->response_due_notified_at !== null || $claimed->first_response_at !== null || $claimed->status !== 'assigned') {
                    return;
                }

                $claimed->response_due_notified_at = now();
                $claimed->save();

                Notification::send(
                    $claimed->assignee,
                    new TicketFirstResponseDueNotification($claimed->id, $claimed->code, $claimed->response_deadline->isPast(), $claimed->company_id)
                );
            });
        }
    }
}
