<?php

namespace Modules\Reporting\Queries;

use Illuminate\Support\Facades\DB;
use Modules\Ticketing\Models\Ticket;

class SlaComplianceQuery
{
    public static function execute(?string $dateFrom = null, ?string $dateTo = null, ?int $categoryId = null): array
    {
        $query = Ticket::query()->whereNotNull('resolved_at');
        if ($dateFrom && $dateTo) $query->whereBetween('resolved_at', [$dateFrom, $dateTo]);
        if ($categoryId) $query->where('category_id', $categoryId);

        $total = $query->count();
        $compliant = (clone $query)->whereColumn('resolved_at', '<=', 'sla_deadline')->count();
        $rate = $total > 0 ? round(($compliant / $total) * 100, 1) : 0;

        $avgResolution = (clone $query)
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) as avg')->value('avg');
        $avgFrt = (clone $query)->whereNotNull('first_response_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at)) as avg')->value('avg');

        $breachCount = (clone $query)->whereColumn('resolved_at', '>', 'sla_deadline')->count();

        $byCategory = Ticket::query()->whereNotNull('resolved_at')
            ->join('ticket_categories', 'tickets.category_id', '=', 'ticket_categories.id')
            ->select('ticket_categories.name',
                DB::raw('count(*) as total'),
                DB::raw('SUM(CASE WHEN tickets.resolved_at <= tickets.sla_deadline THEN 1 ELSE 0 END) as compliant'))
            ->groupBy('ticket_categories.name')->get()
            ->map(fn ($r) => ['name' => $r->name, 'total' => $r->total, 'compliant' => $r->compliant,
                'rate' => $r->total > 0 ? round(($r->compliant / $r->total) * 100, 1) : 0])->toArray();

        return [
            'total_resolved' => $total,
            'sla_compliant' => $compliant,
            'sla_rate' => $rate,
            'breach_count' => $breachCount,
            'avg_resolution_minutes' => $avgResolution ? round($avgResolution) : null,
            'avg_frt_minutes' => $avgFrt ? round($avgFrt) : null,
            'by_category' => $byCategory,
        ];
    }
}
