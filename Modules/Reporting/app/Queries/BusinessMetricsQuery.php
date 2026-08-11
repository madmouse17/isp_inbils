<?php

namespace Modules\Reporting\Queries;

use App\Models\Core\ServiceSubscription;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Invoice;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\Reporting\Queries\Concerns\AppliesDateRange;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;

class BusinessMetricsQuery
{
    use AppliesDateRange;

    public static function execute(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $subStatusCounts = ServiceSubscription::query()
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')->pluck('count', 'status')
            ->map(fn ($count) => (int) $count)->toArray();
        $mrc = ServiceSubscription::where('status', 'active')->sum('mrc_amount');
        $activeCount = (int) ($subStatusCounts['active'] ?? 0);
        $suspendedCount = (int) ($subStatusCounts['suspended'] ?? 0);
        $terminatedCount = (int) ($subStatusCounts['terminated'] ?? 0);

        $newSubs = 0;
        if ($dateFrom && $dateTo) {
            $newSubsQuery = ServiceSubscription::query();
            self::applyDateRange($newSubsQuery, 'created_at', $dateFrom, $dateTo);
            $newSubs = $newSubsQuery->count();
        }

        $churn = 0;
        if ($dateFrom && $dateTo) {
            $churnQuery = ServiceSubscription::query()->where('status', 'terminated');
            self::applyDateRange($churnQuery, 'terminated_at', $dateFrom, $dateTo);
            $churn = $churnQuery->count();
        }

        $revenueQuery = Invoice::query()->where('status', 'paid');
        self::applyDateRange($revenueQuery, 'issue_date', $dateFrom, $dateTo);
        $revenue = (clone $revenueQuery)->sum('paid_amount');
        $recurringRevenue = (clone $revenueQuery)->where('type', 'recurring')->sum('paid_amount');
        $oneTimeRevenue = (clone $revenueQuery)->where('type', 'one_time')->sum('paid_amount');

        $outstanding = Invoice::whereIn('status', ['sent', 'partial', 'overdue'])
            ->selectRaw('SUM(total - paid_amount) as outstanding')->value('outstanding') ?? 0;

        $assetDist = NetworkAsset::query()
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')->pluck('count', 'status')->toArray();

        $slaCompliant = Ticket::whereNotNull('resolved_at')
            ->whereColumn('resolved_at', '<=', 'sla_deadline')->count();
        $slaTotal = Ticket::whereNotNull('resolved_at')->count();
        $slaRate = $slaTotal > 0 ? round(($slaCompliant / $slaTotal) * 100, 1) : 0;

        $installSpk = WorkOrder::where('type', 'installation')->where('status', 'completed');
        self::applyDateRange($installSpk, 'completed_at', $dateFrom, $dateTo);
        $installCount = $installSpk->count();

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
