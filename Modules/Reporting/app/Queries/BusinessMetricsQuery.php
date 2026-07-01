<?php

namespace Modules\Reporting\Queries;

use App\Models\Core\ServiceSubscription;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Invoice;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;

class BusinessMetricsQuery
{
    public static function execute(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $subQuery = ServiceSubscription::query();
        $mrc = (clone $subQuery)->where('status', 'active')->sum('mrc_amount');
        $activeCount = (clone $subQuery)->where('status', 'active')->count();
        $suspendedCount = (clone $subQuery)->where('status', 'suspended')->count();
        $terminatedCount = (clone $subQuery)->where('status', 'terminated')->count();

        $newSubs = 0;
        $churn = 0;
        if ($dateFrom && $dateTo) {
            $newSubs = ServiceSubscription::whereBetween('created_at', [$dateFrom, $dateTo])->count();
            $churn = ServiceSubscription::where('status', 'terminated')
                ->whereBetween('terminated_at', [$dateFrom, $dateTo])->count();
        }

        $revenueQuery = Invoice::query()->where('status', 'paid');
        if ($dateFrom && $dateTo) {
            $revenueQuery->whereBetween('issue_date', [$dateFrom, $dateTo]);
        }
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
        if ($dateFrom && $dateTo) {
            $installSpk->whereBetween('completed_at', [$dateFrom, $dateTo]);
        }
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
