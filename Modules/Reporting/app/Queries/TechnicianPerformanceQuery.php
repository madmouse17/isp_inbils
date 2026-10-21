<?php

namespace Modules\Reporting\Queries;

use App\Models\Core\EmployeeEvaluation;
use App\Models\Core\EmployeeProfile;
use App\Models\User;
use App\Services\Core\CompanyService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Reporting\Queries\Concerns\AppliesDateRange;
use Modules\Reporting\Queries\Sources\SlaSources;
use Modules\Reporting\Queries\Sources\TechnicianSources;
use Modules\Reporting\Support\TimeBuckets;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;

class TechnicianPerformanceQuery
{
    use AppliesDateRange;

    public static function eligibleTechnicians(User $actor): Builder
    {
        $companyId = self::companyId($actor);
        $query = self::activeTechnicianQuery($companyId);

        if ($actor->can('report.manage')) {
            return $query;
        }

        if ($actor->can('report.technician.team') && $actor->can('report.technician')) {
            $organizationPath = self::actorOrganizationPath($actor, $companyId);

            if ($organizationPath === null) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereHas('employeeProfile.organization', function (Builder $organizationQuery) use ($companyId, $organizationPath): void {
                $organizationQuery->where('organization_units.company_id', $companyId)
                    ->where('organization_units.is_active', true)
                    ->where(function (Builder $pathQuery) use ($organizationPath): void {
                        $pathQuery->where('organization_units.path', $organizationPath)
                            ->orWhere('organization_units.path', 'like', self::escapeLikeWildcards($organizationPath).' > %');
                    });
            });
        }

        if ($actor->can('app.technician') && $actor->can('report.technician')) {
            return $query->whereKey($actor->id);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function canSelect(User $actor, int $technicianId): bool
    {
        return self::eligibleTechnicians($actor)->whereKey($technicianId)->exists();
    }

    public static function execute(int $technicianId, ?string $dateFrom = null, ?string $dateTo = null, ?int $companyId = null): array
    {
        $companyId ??= self::companyId(auth()->user());
        $target = self::activeTechnicianQuery($companyId)->whereKey($technicianId)->first();
        abort_unless($target !== null, 403);

        $spkQuery = TechnicianSources::completedWorkOrders($companyId, $technicianId, $dateFrom, $dateTo);
        $ticketQuery = TechnicianSources::resolvedTickets($companyId, $technicianId, $dateFrom, $dateTo);

        $spkCount = (clone $spkQuery)->count();
        $ticketCount = (clone $ticketQuery)->count();

        $avgSpkMinutes = (clone $spkQuery)->whereNotNull('started_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, started_at, completed_at)) as avg')->value('avg');
        $avgTicketMinutes = (clone $ticketQuery)->whereNotNull('resolved_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) as avg')->value('avg');

        $slaCompliant = SlaSources::onTime(clone $ticketQuery)->count();
        $slaTotal = (clone $ticketQuery)->count();
        $slaRate = $slaTotal > 0 ? round(($slaCompliant / $slaTotal) * 100, 1) : 0;

        $avgFrt = SlaSources::withFirstResponse(clone $ticketQuery)
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at)) as avg')->value('avg');

        $evaluationQuery = TechnicianSources::evaluations($companyId, $technicianId, $dateFrom, $dateTo);
        $avgScore = (clone $evaluationQuery)->avg('score');
        $avgCustomerRating = (clone $evaluationQuery)->whereNotNull('customer_rating')->avg('customer_rating');

        $activeWorkload = TechnicianSources::activeWorkOrders($companyId, $technicianId)->count()
            + TechnicianSources::activeTickets($companyId, $technicianId)->count();

        $spkByType = (clone $spkQuery)
            ->select('type', DB::raw('count(*) as count'))
            ->groupBy('type')->pluck('count', 'type')->toArray();

        $ticketsByCategory = TechnicianSources::resolvedTicketsByCategory($companyId, $technicianId, $dateFrom, $dateTo)
            ->select(DB::raw(SlaSources::CATEGORY_LABEL.' as name'), DB::raw('count(*) as count'))
            ->groupByRaw(SlaSources::CATEGORY_LABEL)->pluck('count', 'name')->toArray();

        return [
            'technician_id' => $target->public_id,
            'spk_completed' => $spkCount,
            'tickets_resolved' => $ticketCount,
            'avg_spk_minutes' => $avgSpkMinutes ? round($avgSpkMinutes) : null,
            'avg_ticket_minutes' => $avgTicketMinutes ? round($avgTicketMinutes) : null,
            'sla_compliance_pct' => $slaRate,
            'avg_frt_minutes' => $avgFrt ? round($avgFrt) : null,
            'avg_score' => $avgScore ? round((float) $avgScore, 1) : null,
            'avg_customer_rating' => $avgCustomerRating ? round((float) $avgCustomerRating, 1) : null,
            'active_workload' => $activeWorkload,
            'spk_by_type' => $spkByType,
            'tickets_by_category' => $ticketsByCategory,
        ];
    }

    /**
     * Every technician the actor may select, ranked by the work they finished in the period (F9-U02): one grouped query
     * per measure over all of them, never one per technician. The figures are those of execute() for the same period,
     * except that a technician without a resolved ticket has no SLA rate (null, where execute() says 0) and a missing
     * average is null. Ties share a rank; the order within a rank is by name.
     *
     * @return list<array{id: string, name: string, rank: int, spk_completed: int, tickets_resolved: int, sla_compliance_pct: float|null, avg_frt_minutes: int|null, avg_score: float|null, active_workload: int}>
     */
    public static function ranking(User $actor, ?string $dateFrom = null, ?string $dateTo = null, ?int $companyId = null): array
    {
        $companyId ??= self::companyId($actor);
        $technicians = self::eligibleTechnicians($actor)->orderBy('users.name')->orderBy('users.id')->get(['users.id', 'users.public_id', 'users.name']);

        if ($technicians->isEmpty()) {
            return [];
        }

        $ids = $technicians->pluck('id')->all();
        $perTechnician = fn (Builder $query, string $column = 'assigned_to') => $query->whereIn($column, $ids)->groupBy($column);

        $completed = WorkOrder::forCompany($companyId)->where('status', 'completed');
        self::applyDateRange($completed, 'completed_at', $dateFrom, $dateTo);
        $completed = $perTechnician($completed)->selectRaw('assigned_to, count(*) as total')->pluck('total', 'assigned_to');

        $resolved = Ticket::forCompany($companyId)->whereIn('status', ['resolved', 'closed']);
        self::applyDateRange($resolved, 'tickets.resolved_at', $dateFrom, $dateTo);
        $resolved = $perTechnician($resolved)
            ->selectRaw('assigned_to, count(*) as resolved')
            ->selectRaw('SUM(CASE WHEN resolved_at <= sla_deadline THEN 1 ELSE 0 END) as compliant')
            ->selectRaw('AVG(CASE WHEN first_response_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, first_response_at) END) as avg_frt')
            ->get()->keyBy('assigned_to');

        $evaluated = EmployeeEvaluation::forCompany($companyId);
        self::applyDateRange($evaluated, 'evaluated_at', $dateFrom, $dateTo);
        $scores = $perTechnician($evaluated, 'employee_id')->selectRaw('employee_id, AVG(score) as average')->pluck('average', 'employee_id');

        $activeSpk = $perTechnician(WorkOrder::forCompany($companyId)->whereIn('status', ['assigned', 'in_progress']))
            ->selectRaw('assigned_to, count(*) as total')->pluck('total', 'assigned_to');
        $activeTickets = $perTechnician(Ticket::forCompany($companyId)->whereIn('status', ['open', 'assigned', 'on_progress']))
            ->selectRaw('assigned_to, count(*) as total')->pluck('total', 'assigned_to');

        $rows = $technicians->map(function (User $technician) use ($completed, $resolved, $scores, $activeSpk, $activeTickets): array {
            $tickets = $resolved->get($technician->id);
            $finished = (int) ($tickets?->resolved ?? 0);
            $average = $tickets?->avg_frt;
            $score = $scores->get($technician->id);

            return [
                'id' => $technician->public_id,
                'name' => $technician->name,
                'rank' => 0,
                'spk_completed' => (int) $completed->get($technician->id, 0),
                'tickets_resolved' => $finished,
                'sla_compliance_pct' => $finished > 0 ? round(((int) $tickets->compliant / $finished) * 100, 1) : null,
                'avg_frt_minutes' => $average ? (int) round((float) $average) : null,
                'avg_score' => $score ? round((float) $score, 1) : null,
                'active_workload' => (int) $activeSpk->get($technician->id, 0) + (int) $activeTickets->get($technician->id, 0),
            ];
        })->all();

        // Most finished work first; usort is stable, so equal totals keep the order by name.
        usort($rows, fn (array $a, array $b): int => $b['spk_completed'] + $b['tickets_resolved'] <=> $a['spk_completed'] + $a['tickets_resolved']);

        $rank = 0;
        $previousTotal = null;

        foreach ($rows as $index => $row) {
            $total = $row['spk_completed'] + $row['tickets_resolved'];
            $rank = $total === $previousTotal ? $rank : $index + 1;
            $previousTotal = $total;
            $rows[$index]['rank'] = $rank;
        }

        return $rows;
    }

    /**
     * What the Technician page draws besides the figures of execute(): SPK completed per time bucket. A bucket is
     * a calendar day of the company timezone; the figures of execute() read days of the application timezone, so
     * a row near midnight can sit in another day. The caller has already authorized the technician.
     *
     * @return array{series: array{granularity: string, points: list<array<string, int|float|string>>}}
     */
    public static function charts(int $technicianId, ?string $dateFrom = null, ?string $dateTo = null, ?int $companyId = null): array
    {
        $companyId ??= self::companyId(auth()->user());
        $timezone = TimeBuckets::timezoneOf($companyId);

        $completed = TechnicianSources::completedWorkOrders($companyId, $technicianId, null, null);
        self::applyDateRange($completed, 'completed_at', $dateFrom, $dateTo, $timezone);

        $oldest = $dateFrom === null ? (clone $completed)->min('completed_at') : null;
        $buckets = TimeBuckets::forPeriod($dateFrom, $dateTo, $timezone, $oldest === null ? null : CarbonImmutable::parse($oldest, (string) config('app.timezone')));
        $counts = $completed->selectRaw($buckets->sqlExpression('work_orders.completed_at').' as bucket, count(*) as total')
            ->groupBy('bucket')->pluck('total', 'bucket')
            ->map(fn ($total): int => (int) $total)->all();

        return ['series' => $buckets->series(['spk_completed' => $counts])];
    }

    private static function activeTechnicianQuery(?int $companyId): Builder
    {
        abort_unless($companyId !== null, 403);

        return User::query()
            ->where('users.company_id', $companyId)
            ->where('users.is_active', true)
            ->withPermissionIn('app.technician', $companyId)
            ->whereHas('employeeProfile', fn (Builder $query) => $query
                ->where('employee_profiles.company_id', $companyId)
                ->where('employee_profiles.status', 'active'));
    }

    private static function actorOrganizationPath(User $actor, int $companyId): ?string
    {
        return EmployeeProfile::query()
            ->where('employee_profiles.company_id', $companyId)
            ->where('employee_profiles.user_id', $actor->id)
            ->where('employee_profiles.status', 'active')
            ->whereHas('organization', fn (Builder $query) => $query
                ->where('organization_units.company_id', $companyId)
                ->where('organization_units.is_active', true)
                ->whereNotNull('organization_units.path'))
            ->join('organization_units', 'employee_profiles.organization_id', '=', 'organization_units.id')
            ->value('organization_units.path');
    }

    /**
     * Organization unit codes are free-form strings, so a stored path may legitimately
     * contain LIKE metacharacters. Bind them as literals; otherwise `_` would match any
     * single character and could widen the descendant scope beyond the actor's unit.
     */
    private static function escapeLikeWildcards(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    private static function companyId(?User $actor): int
    {
        $companyId = $actor === null ? null : CompanyService::idFor($actor);
        abort_unless($companyId !== null, 403);

        return $companyId;
    }
}
