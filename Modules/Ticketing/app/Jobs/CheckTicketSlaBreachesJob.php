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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Notifications\TicketSlaBreachedNotification;

class CheckTicketSlaBreachesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

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
    }
}
