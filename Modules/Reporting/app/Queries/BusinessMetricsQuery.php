<?php

namespace Modules\Reporting\Queries;

use Illuminate\Support\Facades\DB;
use Modules\Reporting\Queries\Sources\AssetSources;
use Modules\Reporting\Queries\Sources\BusinessSources;
use Modules\Reporting\Queries\Sources\SlaSources;

class BusinessMetricsQuery
{
    /**
     * Every figure reads the builders of BusinessSources, the same ones the drill-down lists use.
     * Without a company id the ambient tenant scope of the authenticated user applies. A timezone
     * (consolidation) reads the period as calendar days of that zone; null keeps v1: application timezone.
     */
    public static function execute(?string $dateFrom = null, ?string $dateTo = null, ?int $companyId = null, ?string $timezone = null): array
    {
        $subStatusCounts = BusinessSources::subscriptions($companyId)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')->pluck('count', 'status')
            ->map(fn ($count) => (int) $count)->toArray();
        $mrc = BusinessSources::subscriptionsInStatus($companyId, 'active')->sum('mrc_amount');
        $activeCount = (int) ($subStatusCounts['active'] ?? 0);
        $suspendedCount = (int) ($subStatusCounts['suspended'] ?? 0);
        $terminatedCount = (int) ($subStatusCounts['terminated'] ?? 0);

        $newSubs = BusinessSources::newSubscriptions($companyId, $dateFrom, $dateTo, $timezone)->count();
        $churn = BusinessSources::churn($companyId, $dateFrom, $dateTo, $timezone)->count();

        $revenue = BusinessSources::paidInvoices($companyId, $dateFrom, $dateTo)->sum('paid_amount');
        $recurringRevenue = BusinessSources::paidInvoices($companyId, $dateFrom, $dateTo, 'recurring')->sum('paid_amount');
        $oneTimeRevenue = BusinessSources::paidInvoices($companyId, $dateFrom, $dateTo, 'one_time')->sum('paid_amount');

        $outstanding = BusinessSources::outstandingInvoices($companyId)
            ->selectRaw('SUM(total - paid_amount) as outstanding')->value('outstanding') ?? 0;

        $assetDist = AssetSources::assets($companyId)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')->pluck('count', 'status')->toArray();

        $slaCompliant = SlaSources::onTime(SlaSources::resolved($companyId))->count();
        $slaTotal = SlaSources::resolved($companyId)->count();
        $slaRate = $slaTotal > 0 ? round(($slaCompliant / $slaTotal) * 100, 1) : 0;

        $installCount = BusinessSources::completedInstallations($companyId, $dateFrom, $dateTo, $timezone)->count();

        return [
            'mrr' => $mrc,
            'active_subscriptions' => $activeCount,
            'suspended_subscriptions' => $suspendedCount,
            'terminated_subscriptions' => $terminatedCount,
            'new_subscriptions' => $newSubs,
            'churn' => $churn,
            'revenue_paid' => $revenue,
            'recurring_revenue' => $recurringRevenue,
            'one_time_revenue' => $oneTimeRevenue,
            'outstanding' => $outstanding,
            'asset_distribution' => $assetDist,
            'sla_compliance_pct' => $slaRate,
            'installation_count' => $installCount,
        ];
    }
}
