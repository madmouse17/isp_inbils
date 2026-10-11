<?php

namespace Modules\Reporting\Queries\Sources;

use App\Models\Core\EmployeeEvaluation;
use Illuminate\Database\Eloquent\Builder;
use Modules\Reporting\Queries\Concerns\AppliesDateRange;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;

/**
 * The records behind the Technician Performance figures. Shared by TechnicianPerformanceQuery and
 * the drill-down lists. Always scoped explicitly to a company and a technician; who may pick that
 * technician is decided by TechnicianReportAccess, never here.
 */
final class TechnicianSources
{
    use AppliesDateRange;

    /** @return Builder<WorkOrder> */
    public static function completedWorkOrders(int $companyId, int $technicianId, ?string $dateFrom, ?string $dateTo): Builder
    {
        $query = WorkOrder::forCompany($companyId)
            ->where('assigned_to', $technicianId)->where('status', 'completed');
        self::applyDateRange($query, 'completed_at', $dateFrom, $dateTo);

        return $query;
    }

    /** @return Builder<Ticket> */
    public static function resolvedTickets(int $companyId, int $technicianId, ?string $dateFrom, ?string $dateTo): Builder
    {
        $query = Ticket::forCompany($companyId)
            ->where('assigned_to', $technicianId)->whereIn('status', ['resolved', 'closed']);
        self::applyDateRange($query, 'tickets.resolved_at', $dateFrom, $dateTo);

        return $query;
    }

    /** @return Builder<Ticket> */
    public static function resolvedTicketsByCategory(int $companyId, int $technicianId, ?string $dateFrom, ?string $dateTo): Builder
    {
        return self::resolvedTickets($companyId, $technicianId, $dateFrom, $dateTo)
            ->join('ticket_categories', 'tickets.category_id', '=', 'ticket_categories.id');
    }

    /** @return Builder<WorkOrder> */
    public static function activeWorkOrders(int $companyId, int $technicianId): Builder
    {
        return WorkOrder::forCompany($companyId)->where('assigned_to', $technicianId)
            ->whereIn('status', ['assigned', 'in_progress']);
    }

    /** @return Builder<Ticket> */
    public static function activeTickets(int $companyId, int $technicianId): Builder
    {
        return Ticket::forCompany($companyId)->where('assigned_to', $technicianId)
            ->whereIn('status', ['open', 'assigned', 'on_progress']);
    }

    /** @return Builder<EmployeeEvaluation> */
    public static function evaluations(int $companyId, int $technicianId, ?string $dateFrom, ?string $dateTo): Builder
    {
        $query = EmployeeEvaluation::forCompany($companyId)->where('employee_id', $technicianId);
        self::applyDateRange($query, 'evaluated_at', $dateFrom, $dateTo);

        return $query;
    }
}
