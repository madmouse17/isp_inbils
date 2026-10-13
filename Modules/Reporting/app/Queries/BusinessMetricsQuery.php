<?php

namespace Modules\Reporting\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Reporting\Queries\Concerns\AppliesDateRange;
use Modules\Reporting\Queries\Sources\AssetSources;
use Modules\Reporting\Queries\Sources\BusinessSources;
use Modules\Reporting\Queries\Sources\SlaSources;
use Modules\Reporting\Support\TimeBuckets;

class BusinessMetricsQuery
{
    use AppliesDateRange;

    /** Order of the invoice status chart; a status not listed comes last. */
    private const INVOICE_STATUSES = ['draft', 'sent', 'partial', 'paid', 'overdue', 'cancelled'];

    /**
     * Every figure reads the builders of BusinessSources, the same ones the drill-down lists use.
     * Without a company id the ambient tenant scope of the authenticated user applies. A timezone
     * (consolidation) reads the period as calendar days of that zone; null keeps v1: application timezone.
     */
    public static function execute(?string $dateFrom = null, ?string $dateTo = null, ?int $companyId = null, ?string $timezone = null, ?int $packageCategoryId = null): array
    {
        $subStatusCounts = BusinessSources::subscriptions($companyId, $packageCategoryId)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')->pluck('count', 'status')
            ->map(fn ($count) => (int) $count)->toArray();
        $mrc = BusinessSources::subscriptionsInStatus($companyId, 'active', $packageCategoryId)->sum('mrc_amount');
        $activeCount = (int) ($subStatusCounts['active'] ?? 0);
        $suspendedCount = (int) ($subStatusCounts['suspended'] ?? 0);
        $terminatedCount = (int) ($subStatusCounts['terminated'] ?? 0);

        $newSubs = BusinessSources::newSubscriptions($companyId, $dateFrom, $dateTo, $timezone, $packageCategoryId)->count();
        $churn = BusinessSources::churn($companyId, $dateFrom, $dateTo, $timezone, $packageCategoryId)->count();

        $revenue = BusinessSources::paidInvoices($companyId, $dateFrom, $dateTo, packageCategoryId: $packageCategoryId)->sum('paid_amount');
        $recurringRevenue = BusinessSources::paidInvoices($companyId, $dateFrom, $dateTo, 'recurring', $packageCategoryId)->sum('paid_amount');
        $oneTimeRevenue = BusinessSources::paidInvoices($companyId, $dateFrom, $dateTo, 'one_time', $packageCategoryId)->sum('paid_amount');

        $outstanding = BusinessSources::outstandingInvoices($companyId, $packageCategoryId)
            ->selectRaw('SUM(total - paid_amount) as outstanding')->value('outstanding') ?? 0;

        $assetDist = AssetSources::assets($companyId)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')->pluck('count', 'status')->toArray();

        $slaCompliant = SlaSources::onTime(SlaSources::resolved($companyId))->count();
        $slaTotal = SlaSources::resolved($companyId)->count();
        $slaRate = $slaTotal > 0 ? round(($slaCompliant / $slaTotal) * 100, 1) : 0;

        $installCount = BusinessSources::completedInstallations($companyId, $dateFrom, $dateTo, $timezone, $packageCategoryId)->count();

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

    /**
     * What the Business page draws besides the figures of execute(): paid revenue and MRR per time bucket, and
     * the invoices of the period by status. A bucket is a calendar day of the company timezone; the figures of
     * execute() read days of the application timezone, so a row near midnight can sit in another day.
     *
     * @return array{
     *     series: array{granularity: string, points: list<array<string, int|float|string>>},
     *     invoice_status: list<array{status: string, count: int}>
     * }
     */
    public static function charts(?string $dateFrom = null, ?string $dateTo = null, ?int $companyId = null, ?int $packageCategoryId = null): array
    {
        $timezone = TimeBuckets::timezoneOf($companyId);
        $paid = BusinessSources::paidInvoices($companyId, $dateFrom, $dateTo, packageCategoryId: $packageCategoryId);

        $oldest = null;
        if ($dateFrom === null) {
            $days = array_filter([(clone $paid)->min('issue_date'), BusinessSources::subscriptions($companyId, $packageCategoryId)->min('activation_date')]);
            $oldest = $days === [] ? null : CarbonImmutable::parse(min($days), $timezone);
        }

        $buckets = TimeBuckets::forPeriod($dateFrom, $dateTo, $timezone, $oldest);
        $revenue = $paid->selectRaw($buckets->sqlDateExpression('issue_date').' as bucket, SUM(paid_amount) as amount')
            ->groupBy('bucket')->pluck('amount', 'bucket')
            ->map(fn ($amount): float => (float) $amount)->all();

        return [
            'series' => $buckets->series(['revenue_paid' => $revenue, 'mrr' => self::mrrAtBucketEnds($buckets, $companyId, $timezone, $packageCategoryId)]),
            'invoice_status' => self::invoiceStatus($companyId, $dateFrom, $dateTo, $packageCategoryId),
        ];
    }

    /**
     * MRR at the end of each bucket: the fee of every subscription activated by then and not terminated by
     * then, at today's fee. Suspensions leave no history, so a subscription suspended today still counts in the
     * past (the MRR figure counts active ones only). The last bucket ends with the period. One pass over the
     * subscriptions, one sum per bucket.
     *
     * @return array<string, float> bucket start => MRR
     */
    private static function mrrAtBucketEnds(TimeBuckets $buckets, ?int $companyId, string $timezone, ?int $packageCategoryId): array
    {
        $sums = [];
        $bindings = [];
        foreach ($buckets->keys() as $index => $key) {
            $day = $buckets->endDate($key);
            $sums[] = "COALESCE(SUM(CASE WHEN activation_date <= ? AND (terminated_at IS NULL OR terminated_at > ?) THEN mrc_amount END), 0) AS m{$index}";
            array_push($bindings, $day, self::dateRange(null, $day, $timezone)[1]);
        }

        $row = (array) BusinessSources::subscriptions($companyId, $packageCategoryId)->selectRaw(implode(', ', $sums), $bindings)->toBase()->first();

        return array_combine($buckets->keys(), array_map(
            static fn (int $index): float => (float) $row["m{$index}"],
            array_keys($buckets->keys()),
        ));
    }

    /** @return list<array{status: string, count: int}> */
    private static function invoiceStatus(?int $companyId, ?string $dateFrom, ?string $dateTo, ?int $packageCategoryId): array
    {
        $query = BusinessSources::invoices($companyId, $packageCategoryId);
        self::applyDateOnlyRange($query, 'issue_date', $dateFrom, $dateTo);
        $order = array_flip(self::INVOICE_STATUSES);

        return $query->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')->pluck('count', 'status')
            ->map(fn ($count, string $status): array => ['status' => $status, 'count' => (int) $count])
            ->sortBy(fn (array $row): int => $order[$row['status']] ?? count($order))
            ->values()->all();
    }
}
