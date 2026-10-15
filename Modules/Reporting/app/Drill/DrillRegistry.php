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
                fn (ReportFilters $f) => BusinessSources::subscriptionsInStatus($f->companyId, 'active', $f->packageCategoryId),
                Measure::Sum, sum: ['mrc_amount']),
            new DrillSource($business, 'active_subscriptions', 'Langganan aktif', DrillKind::Subscription,
                fn (ReportFilters $f) => BusinessSources::subscriptionsInStatus($f->companyId, 'active', $f->packageCategoryId)),
            new DrillSource($business, 'suspended_subscriptions', 'Langganan ditangguhkan', DrillKind::Subscription,
                fn (ReportFilters $f) => BusinessSources::subscriptionsInStatus($f->companyId, 'suspended', $f->packageCategoryId)),
            new DrillSource($business, 'terminated_subscriptions', 'Langganan berhenti', DrillKind::Subscription,
                fn (ReportFilters $f) => BusinessSources::subscriptionsInStatus($f->companyId, 'terminated', $f->packageCategoryId)),
            new DrillSource($business, 'new_subscriptions', 'Langganan baru', DrillKind::Subscription,
                fn (ReportFilters $f) => BusinessSources::newSubscriptions($f->companyId, $f->dateFrom, $f->dateTo, packageCategoryId: $f->packageCategoryId)),
            new DrillSource($business, 'churn', 'Churn', DrillKind::Subscription,
                fn (ReportFilters $f) => BusinessSources::churn($f->companyId, $f->dateFrom, $f->dateTo, packageCategoryId: $f->packageCategoryId)),
            new DrillSource($business, 'revenue_paid', 'Pendapatan terbayar', DrillKind::Invoice,
                fn (ReportFilters $f) => BusinessSources::paidInvoices($f->companyId, $f->dateFrom, $f->dateTo, packageCategoryId: $f->packageCategoryId),
                Measure::Sum, sum: ['paid_amount']),
            new DrillSource($business, 'recurring_revenue', 'Pendapatan berulang', DrillKind::Invoice,
                fn (ReportFilters $f) => BusinessSources::paidInvoices($f->companyId, $f->dateFrom, $f->dateTo, 'recurring', $f->packageCategoryId),
                Measure::Sum, sum: ['paid_amount']),
            new DrillSource($business, 'one_time_revenue', 'Pendapatan sekali bayar', DrillKind::Invoice,
                fn (ReportFilters $f) => BusinessSources::paidInvoices($f->companyId, $f->dateFrom, $f->dateTo, 'one_time', $f->packageCategoryId),
                Measure::Sum, sum: ['paid_amount']),
            new DrillSource($business, 'outstanding', 'Piutang', DrillKind::Invoice,
                fn (ReportFilters $f) => BusinessSources::outstandingInvoices($f->companyId, $f->packageCategoryId),
                Measure::Sum, sum: ['total', 'paid_amount'], reconcile: self::receivables(...)),
            new DrillSource($business, 'asset_distribution', 'Sebaran aset', DrillKind::Asset,
                fn (ReportFilters $f) => AssetSources::assets($f->companyId)->where('status', (string) $f->group),
                grouped: true),
            new DrillSource($business, 'sla_compliance_pct', 'Kepatuhan SLA', DrillKind::Ticket,
                fn (ReportFilters $f) => SlaSources::resolved($f->companyId),
                Measure::Share, onTime: SlaSources::onTime(...)),
            new DrillSource($business, 'installation_count', 'Pemasangan selesai', DrillKind::WorkOrder,
                fn (ReportFilters $f) => BusinessSources::completedInstallations($f->companyId, $f->dateFrom, $f->dateTo, packageCategoryId: $f->packageCategoryId)),

            // SLA
            new DrillSource($sla, 'total_resolved', 'Tiket selesai', DrillKind::Ticket,
                fn (ReportFilters $f) => SlaSources::resolved($f->companyId, $f->dateFrom, $f->dateTo, $f->categoryId)),
            new DrillSource($sla, 'sla_compliant', 'Tiket sesuai SLA', DrillKind::Ticket,
                fn (ReportFilters $f) => SlaSources::onTime(SlaSources::resolved($f->companyId, $f->dateFrom, $f->dateTo, $f->categoryId))),
            new DrillSource($sla, 'sla_rate', 'Tingkat kepatuhan SLA', DrillKind::Ticket,
                fn (ReportFilters $f) => SlaSources::resolved($f->companyId, $f->dateFrom, $f->dateTo, $f->categoryId),
                Measure::Share, onTime: SlaSources::onTime(...)),
            new DrillSource($sla, 'breach_count', 'Tiket melanggar SLA', DrillKind::Ticket,
                fn (ReportFilters $f) => SlaSources::breached(SlaSources::resolved($f->companyId, $f->dateFrom, $f->dateTo, $f->categoryId))),
            new DrillSource($sla, 'response_on_time', 'Tiket direspons tepat waktu', DrillKind::Ticket,
                fn (ReportFilters $f) => SlaSources::respondedOnTime(SlaSources::resolved($f->companyId, $f->dateFrom, $f->dateTo, $f->categoryId))),
            new DrillSource($sla, 'response_breached', 'Tiket direspons terlambat', DrillKind::Ticket,
                fn (ReportFilters $f) => SlaSources::responseBreached(SlaSources::resolved($f->companyId, $f->dateFrom, $f->dateTo, $f->categoryId))),
            new DrillSource($sla, 'response_without_target', 'Tiket tanpa target respons', DrillKind::Ticket,
                fn (ReportFilters $f) => SlaSources::withoutResponseTarget(SlaSources::resolved($f->companyId, $f->dateFrom, $f->dateTo, $f->categoryId))),
            new DrillSource($sla, 'response_rate', 'Tingkat respons', DrillKind::Ticket,
                fn (ReportFilters $f) => SlaSources::responseJudged(SlaSources::resolved($f->companyId, $f->dateFrom, $f->dateTo, $f->categoryId)),
                Measure::Share, onTime: SlaSources::respondedOnTime(...)),
            new DrillSource($sla, 'avg_resolution_minutes', 'Tiket selesai (waktu penyelesaian)', DrillKind::Ticket,
                fn (ReportFilters $f) => SlaSources::resolved($f->companyId, $f->dateFrom, $f->dateTo, $f->categoryId),
                Measure::Average),
            new DrillSource($sla, 'avg_frt_minutes', 'Tiket dengan respons pertama', DrillKind::Ticket,
                fn (ReportFilters $f) => SlaSources::withFirstResponse(SlaSources::resolved($f->companyId, $f->dateFrom, $f->dateTo, $f->categoryId)),
                Measure::Average),
            new DrillSource($sla, 'by_category', 'Tiket selesai per kategori', DrillKind::Ticket,
                fn (ReportFilters $f) => SlaSources::resolvedByCategory($f->companyId)
                    ->where('ticket_categories.name', (string) $f->group)->select('tickets.*'),
                grouped: true),

            // Asset
            new DrillSource($asset, 'status_distribution', 'Aset per status', DrillKind::Asset,
                fn (ReportFilters $f) => AssetSources::assets($f->companyId, $f->locationId, $f->assetType)->where('status', (string) $f->group),
                grouped: true),
            new DrillSource($asset, 'by_location', 'Aset per lokasi', DrillKind::Asset,
                fn (ReportFilters $f) => AssetSources::withLocation($f->companyId)
                    ->where('locations.name', (string) $f->group)->select('network_assets.*'),
                grouped: true),
            new DrillSource($asset, 'installation_count', 'Pemasangan aset', DrillKind::Installation,
                fn (ReportFilters $f) => AssetSources::installations($f->companyId, $f->dateFrom, $f->dateTo)),
            new DrillSource($asset, 'customer_linked_count', 'Aset terhubung ke pelanggan', DrillKind::Asset,
                fn (ReportFilters $f) => AssetSources::customerLinked($f->companyId)),
            new DrillSource($asset, 'retired_count', 'Aset dipensiunkan', DrillKind::Asset,
                fn (ReportFilters $f) => AssetSources::retired($f->companyId)),

            // Technician
            new DrillSource($tech, 'spk_completed', 'SPK selesai', DrillKind::WorkOrder,
                fn (ReportFilters $f) => TechnicianSources::completedWorkOrders($f->companyId, (int) $f->technicianId, $f->dateFrom, $f->dateTo)),
            new DrillSource($tech, 'tickets_resolved', 'Tiket diselesaikan', DrillKind::Ticket,
                fn (ReportFilters $f) => TechnicianSources::resolvedTickets($f->companyId, (int) $f->technicianId, $f->dateFrom, $f->dateTo)),
            new DrillSource($tech, 'active_workload_spk', 'Beban kerja aktif: SPK', DrillKind::WorkOrder,
                fn (ReportFilters $f) => TechnicianSources::activeWorkOrders($f->companyId, (int) $f->technicianId),
                metric: 'active_workload', companion: 'active_workload_tickets'),
            new DrillSource($tech, 'active_workload_tickets', 'Beban kerja aktif: tiket', DrillKind::Ticket,
                fn (ReportFilters $f) => TechnicianSources::activeTickets($f->companyId, (int) $f->technicianId),
                metric: 'active_workload', companion: 'active_workload_spk'),
            new DrillSource($tech, 'spk_by_type', 'SPK selesai per jenis', DrillKind::WorkOrder,
                fn (ReportFilters $f) => TechnicianSources::completedWorkOrders($f->companyId, (int) $f->technicianId, $f->dateFrom, $f->dateTo)
                    ->where('type', (string) $f->group),
                grouped: true),
            new DrillSource($tech, 'tickets_by_category', 'Tiket diselesaikan per kategori', DrillKind::Ticket,
                fn (ReportFilters $f) => TechnicianSources::resolvedTicketsByCategory($f->companyId, (int) $f->technicianId, $f->dateFrom, $f->dateTo)
                    ->where('ticket_categories.name', (string) $f->group)->select('tickets.*'),
                grouped: true),
            new DrillSource($tech, 'avg_spk_minutes', 'SPK yang dimulai (durasi)', DrillKind::WorkOrder,
                fn (ReportFilters $f) => TechnicianSources::completedWorkOrders($f->companyId, (int) $f->technicianId, $f->dateFrom, $f->dateTo)
                    ->whereNotNull('started_at'),
                Measure::Average),
            new DrillSource($tech, 'avg_ticket_minutes', 'Tiket diselesaikan (waktu penyelesaian)', DrillKind::Ticket,
                fn (ReportFilters $f) => TechnicianSources::resolvedTickets($f->companyId, (int) $f->technicianId, $f->dateFrom, $f->dateTo)
                    ->whereNotNull('resolved_at'),
                Measure::Average),
            new DrillSource($tech, 'sla_compliance_pct', 'Kepatuhan SLA', DrillKind::Ticket,
                fn (ReportFilters $f) => TechnicianSources::resolvedTickets($f->companyId, (int) $f->technicianId, $f->dateFrom, $f->dateTo),
                Measure::Share, onTime: SlaSources::onTime(...)),
            new DrillSource($tech, 'avg_frt_minutes', 'Tiket dengan respons pertama', DrillKind::Ticket,
                fn (ReportFilters $f) => SlaSources::withFirstResponse(
                    TechnicianSources::resolvedTickets($f->companyId, (int) $f->technicianId, $f->dateFrom, $f->dateTo)
                ),
                Measure::Average),
            new DrillSource($tech, 'avg_score', 'Evaluasi (skor)', DrillKind::Evaluation,
                fn (ReportFilters $f) => TechnicianSources::evaluations($f->companyId, (int) $f->technicianId, $f->dateFrom, $f->dateTo),
                Measure::Average),
            new DrillSource($tech, 'avg_customer_rating', 'Evaluasi (rating pelanggan)', DrillKind::Evaluation,
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
        // Billing's Receivables page has no category filter, so a filtered figure has nothing to reconcile with.
        if ($filters->packageCategoryId !== null || ! $actor->can('viewAny', Invoice::class)) {
            return null;
        }

        $total = collect(BillingService::receivables())
            ->reduce(fn (string $carry, array $row): string => Money::add($carry, (string) $row['total']), '0.00');

        return [
            'label' => 'Tunggakan',
            'href' => route('admin.billing.receivables', [], false),
            'amount' => Money::round($total),
        ];
    }
}
