<?php

namespace Modules\Reporting\Queries;

use Illuminate\Support\Facades\DB;
use Modules\Reporting\Queries\Sources\SlaSources;

class SlaComplianceQuery
{
    /**
     * Every figure reads the builders of SlaSources, the same ones the drill-down lists use.
     * Without a company id the ambient tenant scope of the authenticated user applies.
     */
    public static function execute(?string $dateFrom = null, ?string $dateTo = null, ?int $categoryId = null, ?int $companyId = null): array
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

        $byCategory = SlaSources::resolvedByCategory($companyId)
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
