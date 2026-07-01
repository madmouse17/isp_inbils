<?php

namespace Modules\Reporting\Queries;

use App\Models\Core\EmployeeEvaluation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;

class TechnicianPerformanceQuery
{
    public static function execute(int $technicianId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $spkQuery = WorkOrder::where('assigned_to', $technicianId)->where('status', 'completed');
        $ticketQuery = Ticket::where('assigned_to', $technicianId)->whereIn('status', ['resolved', 'closed']);

        if ($dateFrom && $dateTo) {
            $spkQuery->whereBetween('completed_at', [$dateFrom, $dateTo]);
            $ticketQuery->whereBetween('resolved_at', [$dateFrom, $dateTo]);
        }

        $spkCount = $spkQuery->count();
        $ticketCount = $ticketQuery->count();

        $avgSpkMinutes = $spkQuery->whereNotNull('started_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, started_at, completed_at)) as avg')->value('avg');
        $avgTicketMinutes = $ticketQuery->whereNotNull('resolved_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) as avg')->value('avg');

        $slaCompliant = Ticket::where('assigned_to', $technicianId)
            ->whereNotNull('resolved_at')->whereColumn('resolved_at', '<=', 'sla_deadline')->count();
        $slaTotal = Ticket::where('assigned_to', $technicianId)->whereNotNull('resolved_at')->count();
        $slaRate = $slaTotal > 0 ? round(($slaCompliant / $slaTotal) * 100, 1) : 0;

        $avgFrt = Ticket::where('assigned_to', $technicianId)->whereNotNull('first_response_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at)) as avg')->value('avg');

        $avgScore = EmployeeEvaluation::where('employee_id', $technicianId)->avg('score');
        $avgCustomerRating = EmployeeEvaluation::where('employee_id', $technicianId)
            ->whereNotNull('customer_rating')->avg('customer_rating');

        $activeWorkload = WorkOrder::where('assigned_to', $technicianId)
            ->whereIn('status', ['assigned', 'in_progress'])->count()
            + Ticket::where('assigned_to', $technicianId)
            ->whereIn('status', ['open', 'assigned', 'on_progress'])->count();

        $spkByType = WorkOrder::where('assigned_to', $technicianId)->where('status', 'completed')
            ->select('type', DB::raw('count(*) as count'))
            ->groupBy('type')->pluck('count', 'type')->toArray();

        $ticketsByCategory = Ticket::where('assigned_to', $technicianId)
            ->whereIn('status', ['resolved', 'closed'])
            ->join('ticket_categories', 'tickets.category_id', '=', 'ticket_categories.id')
            ->select('ticket_categories.name', DB::raw('count(*) as count'))
            ->groupBy('ticket_categories.name')->pluck('count', 'name')->toArray();

        return [
            'technician_id' => $technicianId,
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
}
