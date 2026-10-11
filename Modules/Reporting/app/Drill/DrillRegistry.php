<?php

namespace Modules\Reporting\Drill;

use App\Models\User;
use App\Support\Money;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\BillingService;
use Modules\Reporting\Queries\ReportFilters;
use Modules\Reporting\Queries\Sources\AssetSources;
use Modules\Reporting\Queries\Sources\BusinessSources;
use Modules\Reporting\Queries\Sources\SlaSources;
use Modules\Reporting\Queries\Sources\TechnicianSources;
use Modules\Reporting\Registry\ReportType;

/**
 * Catalog of the drill-down lists: one per aggregate figure of the Business, SLA, Asset and
 * Technician reports, each built from the Sources the aggregate itself reads. Stock Card and Audit
 * Log are row-level already and have none.
 */
final class DrillRegistry
{
    /** @var list<DrillSource>|null */
    private static ?array $sources = null;

    /** @return list<DrillSource> */
    public static function all(): array
    {
        return self::$sources ??= self::definitions();
    }

    /** @return list<DrillSource> */
    public static function forReport(ReportType $report): array
    {
        return array_values(array_filter(
            self::all(),
            fn (DrillSource $source): bool => $source->report === $report,
        ));
    }

    public static function find(ReportType $report, string $key): ?DrillSource
    {
        foreach (self::forReport($report) as $source) {
            if ($source->key === $key) {
                return $source;
            }
        }

        return null;
    }

    /**
     * Drill URLs of a report page, keyed by list, for the lists the actor may open. The page adds
     * `group` itself for breakdown rows.
     *
     * @param  array<string, string|int>  $query  The page's applied filters.
     * @return array<string, string>
     */
    public static function links(ReportType $report, User $actor, array $query): array
    {
        $links = [];

        foreach (self::forReport($report) as $source) {
            if ($source->kind->authorizes($actor)) {
                $links[$source->key] = route($report->drillRouteName(), ['metric' => $source->key, ...$query], false);
            }
        }

        return $links;
    }

    /** @return list<DrillSource> */
    private static function definitions(): array
    {
        $business = ReportType::Business;
        $sla = ReportType::Sla;
        $asset = ReportType::Asset;
        $tech = ReportType::Technician;

        return [
            // Business
            new DrillSource($business, 'mrr', 'MRR', DrillKind::Subscription,
                fn (ReportFilters $f) => BusinessSources::subscriptionsInStatus($f->companyId, 'active'),
                Measure::Sum, sum: ['mrc_amount']),
            new DrillSource($business, 'active_subscriptions', 'Active subscriptions', DrillKind::Subscription,
                fn (ReportFilters $f) => BusinessSources::subscriptionsInStatus($f->companyId, 'active')),
            new DrillSource($business, 'suspended_subscriptions', 'Suspended subscriptions', DrillKind::Subscription,
                fn (ReportFilters $f) => BusinessSources::subscriptionsInStatus($f->companyId, 'suspended')),
            new DrillSource($business, 'terminated_subscriptions', 'Terminated subscriptions', DrillKind::Subscription,
                fn (ReportFilters $f) => BusinessSources::subscriptionsInStatus($f->companyId, 'terminated')),
            new DrillSource($business, 'new_subscriptions', 'New subscriptions', DrillKind::Subscription,
                fn (ReportFilters $f) => BusinessSources::newSubscriptions($f->companyId, $f->dateFrom, $f->dateTo)),
            new DrillSource($business, 'churn', 'Churn', DrillKind::Subscription,
                fn (ReportFilters $f) => BusinessSources::churn($f->companyId, $f->dateFrom, $f->dateTo)),
            new DrillSource($business, 'revenue_paid', 'Revenue (Paid)', DrillKind::Invoice,
                fn (ReportFilters $f) => BusinessSources::paidInvoices($f->companyId, $f->dateFrom, $f->dateTo),
                Measure::Sum, sum: ['paid_amount']),
            new DrillSource($business, 'recurring_revenue', 'Recurring revenue', DrillKind::Invoice,
                fn (ReportFilters $f) => BusinessSources::paidInvoices($f->companyId, $f->dateFrom, $f->dateTo, 'recurring'),
                Measure::Sum, sum: ['paid_amount']),
            new DrillSource($business, 'one_time_revenue', 'One-time revenue', DrillKind::Invoice,
                fn (ReportFilters $f) => BusinessSources::paidInvoices($f->companyId, $f->dateFrom, $f->dateTo, 'one_time'),
                Measure::Sum, sum: ['paid_amount']),
            new DrillSource($business, 'outstanding', 'Outstanding', DrillKind::Invoice,
                fn (ReportFilters $f) => BusinessSources::outstandingInvoices($f->companyId),
                Measure::Sum, sum: ['total', 'paid_amount'], reconcile: self::receivables(...)),
            new DrillSource($business, 'asset_distribution', 'Asset distribution', DrillKind::Asset,
                fn (ReportFilters $f) => AssetSources::assets($f->companyId)->where('status', (string) $f->group),
                grouped: true),
            new DrillSource($business, 'sla_compliance_pct', 'SLA compliance', DrillKind::Ticket,
                fn (ReportFilters $f) => SlaSources::resolved($f->companyId),
                Measure::Share, onTime: SlaSources::onTime(...)),
            new DrillSource($business, 'installation_count', 'Installations completed', DrillKind::WorkOrder,
                fn (ReportFilters $f) => BusinessSources::completedInstallations($f->companyId, $f->dateFrom, $f->dateTo)),

            // SLA
            new DrillSource($sla, 'total_resolved', 'Resolved tickets', DrillKind::Ticket,
                fn (ReportFilters $f) => SlaSources::resolved($f->companyId, $f->dateFrom, $f->dateTo, $f->categoryId)),
            new DrillSource($sla, 'sla_compliant', 'Compliant tickets', DrillKind::Ticket,
                fn (ReportFilters $f) => SlaSources::onTime(SlaSources::resolved($f->companyId, $f->dateFrom, $f->dateTo, $f->categoryId))),
            new DrillSource($sla, 'sla_rate', 'SLA compliance rate', DrillKind::Ticket,
                fn (ReportFilters $f) => SlaSources::resolved($f->companyId, $f->dateFrom, $f->dateTo, $f->categoryId),
                Measure::Share, onTime: SlaSources::onTime(...)),
            new DrillSource($sla, 'breach_count', 'Breached tickets', DrillKind::Ticket,
                fn (ReportFilters $f) => SlaSources::breached(SlaSources::resolved($f->companyId, $f->dateFrom, $f->dateTo, $f->categoryId))),
            new DrillSource($sla, 'avg_resolution_minutes', 'Resolved tickets (resolution time)', DrillKind::Ticket,
                fn (ReportFilters $f) => SlaSources::resolved($f->companyId, $f->dateFrom, $f->dateTo, $f->categoryId),
                Measure::Average),
            new DrillSource($sla, 'avg_frt_minutes', 'Tickets with a first response', DrillKind::Ticket,
                fn (ReportFilters $f) => SlaSources::withFirstResponse(SlaSources::resolved($f->companyId, $f->dateFrom, $f->dateTo, $f->categoryId)),
                Measure::Average),
            new DrillSource($sla, 'by_category', 'Resolved tickets by category', DrillKind::Ticket,
                fn (ReportFilters $f) => SlaSources::resolvedByCategory($f->companyId)
                    ->where('ticket_categories.name', (string) $f->group)->select('tickets.*'),
                grouped: true),

            // Asset
            new DrillSource($asset, 'status_distribution', 'Assets by status', DrillKind::Asset,
                fn (ReportFilters $f) => AssetSources::assets($f->companyId, $f->locationId, $f->assetType)->where('status', (string) $f->group),
                grouped: true),
            new DrillSource($asset, 'by_location', 'Assets by location', DrillKind::Asset,
                fn (ReportFilters $f) => AssetSources::withLocation($f->companyId)
                    ->where('locations.name', (string) $f->group)->select('network_assets.*'),
                grouped: true),
            new DrillSource($asset, 'installation_count', 'Asset installations', DrillKind::Installation,
                fn (ReportFilters $f) => AssetSources::installations($f->companyId, $f->dateFrom, $f->dateTo)),
            new DrillSource($asset, 'customer_linked_count', 'Customer-linked assets', DrillKind::Asset,
                fn (ReportFilters $f) => AssetSources::customerLinked($f->companyId)),
            new DrillSource($asset, 'retired_count', 'Retired assets', DrillKind::Asset,
                fn (ReportFilters $f) => AssetSources::retired($f->companyId)),

            // Technician
            new DrillSource($tech, 'spk_completed', 'Completed SPK', DrillKind::WorkOrder,
                fn (ReportFilters $f) => TechnicianSources::completedWorkOrders($f->companyId, (int) $f->technicianId, $f->dateFrom, $f->dateTo)),
            new DrillSource($tech, 'tickets_resolved', 'Resolved tickets', DrillKind::Ticket,
                fn (ReportFilters $f) => TechnicianSources::resolvedTickets($f->companyId, (int) $f->technicianId, $f->dateFrom, $f->dateTo)),
            new DrillSource($tech, 'active_workload_spk', 'Active workload: SPK', DrillKind::WorkOrder,
                fn (ReportFilters $f) => TechnicianSources::activeWorkOrders($f->companyId, (int) $f->technicianId),
                metric: 'active_workload', companion: 'active_workload_tickets'),
            new DrillSource($tech, 'active_workload_tickets', 'Active workload: tickets', DrillKind::Ticket,
                fn (ReportFilters $f) => TechnicianSources::activeTickets($f->companyId, (int) $f->technicianId),
                metric: 'active_workload', companion: 'active_workload_spk'),
            new DrillSource($tech, 'spk_by_type', 'Completed SPK by type', DrillKind::WorkOrder,
                fn (ReportFilters $f) => TechnicianSources::completedWorkOrders($f->companyId, (int) $f->technicianId, $f->dateFrom, $f->dateTo)
                    ->where('type', (string) $f->group),
                grouped: true),
            new DrillSource($tech, 'tickets_by_category', 'Resolved tickets by category', DrillKind::Ticket,
                fn (ReportFilters $f) => TechnicianSources::resolvedTicketsByCategory($f->companyId, (int) $f->technicianId, $f->dateFrom, $f->dateTo)
                    ->where('ticket_categories.name', (string) $f->group)->select('tickets.*'),
                grouped: true),
            new DrillSource($tech, 'avg_spk_minutes', 'Started SPK (duration)', DrillKind::WorkOrder,
                fn (ReportFilters $f) => TechnicianSources::completedWorkOrders($f->companyId, (int) $f->technicianId, $f->dateFrom, $f->dateTo)
                    ->whereNotNull('started_at'),
                Measure::Average),
            new DrillSource($tech, 'avg_ticket_minutes', 'Resolved tickets (resolution time)', DrillKind::Ticket,
                fn (ReportFilters $f) => TechnicianSources::resolvedTickets($f->companyId, (int) $f->technicianId, $f->dateFrom, $f->dateTo)
                    ->whereNotNull('resolved_at'),
                Measure::Average),
            new DrillSource($tech, 'sla_compliance_pct', 'SLA compliance', DrillKind::Ticket,
                fn (ReportFilters $f) => TechnicianSources::resolvedTickets($f->companyId, (int) $f->technicianId, $f->dateFrom, $f->dateTo),
                Measure::Share, onTime: SlaSources::onTime(...)),
            new DrillSource($tech, 'avg_frt_minutes', 'Tickets with a first response', DrillKind::Ticket,
                fn (ReportFilters $f) => SlaSources::withFirstResponse(
                    TechnicianSources::resolvedTickets($f->companyId, (int) $f->technicianId, $f->dateFrom, $f->dateTo)
                ),
                Measure::Average),
            new DrillSource($tech, 'avg_score', 'Evaluations (score)', DrillKind::Evaluation,
                fn (ReportFilters $f) => TechnicianSources::evaluations($f->companyId, (int) $f->technicianId, $f->dateFrom, $f->dateTo),
                Measure::Average),
            new DrillSource($tech, 'avg_customer_rating', 'Evaluations (customer rating)', DrillKind::Evaluation,
                fn (ReportFilters $f) => TechnicianSources::evaluations($f->companyId, (int) $f->technicianId, $f->dateFrom, $f->dateTo)
                    ->whereNotNull('customer_rating'),
                Measure::Average),
        ];
    }

    /**
     * Outstanding should equal the Receivables total; the two use different inclusion rules (the
     * report counts every sent/partial/overdue invoice, Receivables only those still unpaid), so a
     * difference is shown, not hidden.
     *
     * @return array{label: string, href: string, amount: string}|null
     */
    private static function receivables(ReportFilters $filters, User $actor): ?array
    {
        if (! $actor->can('viewAny', Invoice::class)) {
            return null;
        }

        $total = collect(BillingService::receivables())
            ->reduce(fn (string $carry, array $row): string => Money::add($carry, (string) $row['total']), '0.00');

        return [
            'label' => 'Receivables',
            'href' => route('admin.billing.receivables', [], false),
            'amount' => Money::round($total),
        ];
    }
}
