<?php

namespace Modules\Reporting\Queries;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Reporting\Drill\DrillKind;
use Modules\Reporting\Queries\Sources\SlaSources;
use Modules\Ticketing\Models\Ticket;

class SlaComplianceQuery
{
    /** How many of the worst late tickets the page lists. */
    private const VIOLATORS = 10;

    /** Priorities from the most urgent down, the order a breakdown by priority reads in. */
    private const PRIORITY_ORDER = ['urgent', 'high', 'medium', 'low'];

    /** What the handlers outside the viewer's technician scope are called, all together. */
    private const OTHER_HANDLERS = 'Penangan lain';

    /**
     * Every figure reads the builders of SlaSources, the same ones the drill-down lists use.
     * Without a company id the ambient tenant scope of the authenticated user applies.
     *
     * Besides the figures of the cards and by_category (which ignores the period), the payload holds how the tickets
     * resolved in the period split by handler, priority and the source of their target (F6-U16), and what customers
     * rated in it: by_technician, by_priority, by_source, csat_average, csat_count. Each split reads the same resolved
     * set as the cards, so its rows add up to total_resolved.
     *
     * The split by handler names only the technicians $actor may select in the Technician report, unless they hold
     * `report.manage`; see byTechnician(). Without an actor (a test, a console command) nobody is hidden.
     */
    public static function execute(?string $dateFrom = null, ?string $dateTo = null, ?int $categoryId = null, ?int $companyId = null, ?User $actor = null): array
    {
        $query = SlaSources::resolved($companyId, $dateFrom, $dateTo, $categoryId);

        $total = $query->count();
        $compliant = SlaSources::onTime(clone $query)->count();
        $rate = $total > 0 ? round(($compliant / $total) * 100, 1) : 0;

        $avgResolution = (clone $query)
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) as avg')->value('avg');
        $avgFrt = SlaSources::withFirstResponse(clone $query)
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at)) as avg')->value('avg');

        $breachCount = SlaSources::breached(clone $query)->count();

        $responseOnTime = SlaSources::respondedOnTime(clone $query)->count();
        $responseBreached = SlaSources::responseBreached(clone $query)->count();
        $responseWithoutTarget = SlaSources::withoutResponseTarget(clone $query)->count();
        $judged = $responseOnTime + $responseBreached;

        $byCategory = SlaSources::resolvedByCategory($companyId)
            ->select(DB::raw(SlaSources::CATEGORY_LABEL.' as name'),
                DB::raw('count(*) as total'),
                DB::raw('SUM(CASE WHEN tickets.resolved_at <= tickets.sla_deadline THEN 1 ELSE 0 END) as compliant'))
            ->groupByRaw(SlaSources::CATEGORY_LABEL)->get()
            ->map(fn ($r) => ['name' => $r->name, 'total' => $r->total, 'compliant' => $r->compliant,
                'rate' => $r->total > 0 ? round(($r->compliant / $r->total) * 100, 1) : 0])->toArray();

        $resolved = fn (): Builder => SlaSources::resolved($companyId, $dateFrom, $dateTo, $categoryId);

        $byPriority = self::split($resolved(), 'tickets.priority', 'tickets.priority');
        usort($byPriority, fn (array $a, array $b): int => array_search($a['name'], self::PRIORITY_ORDER, true) <=> array_search($b['name'], self::PRIORITY_ORDER, true));

        $rated = SlaSources::rated($companyId, $dateFrom, $dateTo, $categoryId)
            ->selectRaw('count(*) as total, AVG(tickets.customer_rating) as average')
            ->first();

        return [
            'total_resolved' => $total,
            'sla_compliant' => $compliant,
            'sla_rate' => $rate,
            'breach_count' => $breachCount,
            'response_on_time' => $responseOnTime,
            'response_breached' => $responseBreached,
            'response_without_target' => $responseWithoutTarget,
            'response_rate' => $judged > 0 ? round(($responseOnTime / $judged) * 100, 1) : null,
            'avg_resolution_minutes' => $avgResolution ? round($avgResolution) : null,
            'avg_frt_minutes' => $avgFrt ? round($avgFrt) : null,
            'by_category' => $byCategory,
            'by_technician' => self::byTechnician($resolved, $actor),
            'by_priority' => $byPriority,
            // A subscription's package version keeps its SLA tier, which is what TicketService::slaTarget() holds a ticket to.
            'by_source' => self::split(
                $resolved()
                    ->leftJoin('service_subscriptions as subscription', 'subscription.id', '=', 'tickets.subscription_id')
                    ->leftJoin('service_package_versions as package_version', 'package_version.id', '=', 'subscription.service_package_version_id')
                    ->leftJoin('sla_tiers as tier', 'tier.id', '=', 'package_version.sla_tier_id'),
                'tier.id',
                'tier.name',
            ),
            'csat_average' => $rated?->average === null ? null : round((float) $rated->average, 1),
            'csat_count' => (int) ($rated?->total ?? 0),
        ];
    }

    /**
     * What the SLA page draws besides the figures of execute(): resolved tickets split by outcome. Both numbers are
     * figures of $payload, so the chart cannot disagree with the cards. A ticket without a deadline is in neither.
     *
     * @param  array<string, mixed>  $payload  the result of execute()
     * @return array{compliance: array{compliant: int, breached: int}}
     */
    public static function charts(array $payload): array
    {
        return ['compliance' => ['compliant' => $payload['sla_compliant'], 'breached' => $payload['breach_count']]];
    }

    /**
     * The resolved tickets of the period that missed their deadline by the most. They are rows of the ticket module, so
     * only an actor who may list tickets gets them (a handler, only their own); without an actor (a file) there are none.
     * Not a figure, so not part of execute().
     *
     * @return list<array<string, mixed>>|null null for an actor who may not list tickets
     */
    public static function violators(?string $dateFrom = null, ?string $dateTo = null, ?int $categoryId = null, ?int $companyId = null, ?User $actor = null): ?array
    {
        if ($actor === null || ! DrillKind::Ticket->authorizes($actor)) {
            return null;
        }

        return DrillKind::Ticket->scopeToActor(SlaSources::breached(SlaSources::resolved($companyId, $dateFrom, $dateTo, $categoryId)), $actor)
            ->with(['category:id,name', 'assignee:id,name'])
            ->orderByRaw('TIMESTAMPDIFF(SECOND, tickets.sla_deadline, tickets.resolved_at) desc')
            ->orderByDesc('tickets.id')
            ->limit(self::VIOLATORS)
            ->get()
            ->map(fn (Ticket $ticket): array => [
                'id' => $ticket->public_id,
                'code' => $ticket->code,
                'title' => $ticket->title,
                'category' => $ticket->category_name_snapshot ?? $ticket->category?->name,
                'assignee' => $ticket->assignee_name_snapshot ?? $ticket->assignee?->name,
                'priority' => $ticket->priority,
                'late_minutes' => intdiv($ticket->resolved_at->getTimestamp() - $ticket->sla_deadline->getTimestamp(), 60),
                'url' => route('admin.tickets.show', $ticket, false),
            ])
            ->all();
    }

    /**
     * The split by handler. Whoever may not see every technician (`report.manage`) reads the names of the technicians of the
     * scope of the Technician report, and every other handler as one row, so the rows still add up to the tickets resolved.
     * A ticket nobody handled has no name to hide: it stays a row of its own.
     *
     * @param  Closure(): Builder<Ticket>  $resolved
     * @return list<array{name: ?string, total: int, compliant: int, breached: int, rate: float|int, avg_frt_minutes: ?int}>
     */
    private static function byTechnician(Closure $resolved, ?User $actor): array
    {
        $query = $resolved()->leftJoin('users as handler', 'handler.id', '=', 'tickets.assigned_to');
        $name = 'COALESCE(tickets.assignee_name_snapshot, handler.name)';
        $group = 'tickets.assigned_to';

        if ($actor !== null && ! $actor->can('report.manage')) {
            $query->leftJoinSub(TechnicianPerformanceQuery::eligibleTechnicians($actor)->select('users.id'), 'visible', 'visible.id', '=', 'tickets.assigned_to');
            // No handler id is 0, so the folded handlers share a group no real handler has.
            $hidden = 'tickets.assigned_to IS NOT NULL AND visible.id IS NULL';
            $group = "CASE WHEN {$hidden} THEN 0 ELSE tickets.assigned_to END";
            $name = "CASE WHEN {$hidden} THEN '".self::OTHER_HANDLERS."' ELSE {$name} END";
        }

        return self::split($query, $group, $name);
    }

    /**
     * One row per group of the resolved tickets: how many, on time, late, the rate and the average first response. The
     * group is named by the first value the expression gives (a handler renamed since may carry two names on closed
     * tickets); a group with no name (no handler, no tier) has a null one.
     *
     * @param  Builder<Ticket>  $resolved
     * @return list<array{name: ?string, total: int, compliant: int, breached: int, rate: float|int, avg_frt_minutes: ?int}>
     */
    private static function split(Builder $resolved, string $group, string $name): array
    {
        return $resolved
            ->selectRaw("MAX({$name}) as label")
            ->selectRaw('count(*) as total')
            ->selectRaw('SUM(CASE WHEN tickets.resolved_at <= tickets.sla_deadline THEN 1 ELSE 0 END) as compliant')
            ->selectRaw('SUM(CASE WHEN tickets.resolved_at > tickets.sla_deadline THEN 1 ELSE 0 END) as breached')
            ->selectRaw('AVG(CASE WHEN tickets.first_response_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, tickets.created_at, tickets.first_response_at) END) as avg_frt_minutes')
            ->groupByRaw($group)
            ->orderByDesc('total')
            ->orderBy('label')
            ->get()
            ->map(fn ($row): array => [
                'name' => $row->label,
                'total' => (int) $row->total,
                'compliant' => (int) $row->compliant,
                'breached' => (int) $row->breached,
                'rate' => $row->total > 0 ? round($row->compliant / $row->total * 100, 1) : 0,
                'avg_frt_minutes' => $row->avg_frt_minutes === null ? null : (int) round((float) $row->avg_frt_minutes),
            ])
            ->all();
    }
}
