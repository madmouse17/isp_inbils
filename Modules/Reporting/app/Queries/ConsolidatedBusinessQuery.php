<?php

namespace Modules\Reporting\Queries;

use App\Models\Core\Company;
use App\Support\Money;
use Illuminate\Support\Collection;
use LogicException;
use Modules\Reporting\Exceptions\ConsolidationRefusedException;
use Modules\Reporting\Queries\Sources\SlaSources;

/**
 * The Business metrics of a group of companies: one row per company, each exactly that company's
 * own Business report (v1 formulas, through BusinessMetricsQuery), and a total that is the sum of the
 * rows. The SLA percentage is a ratio, so the total pools the ticket counts instead of adding
 * percentages. Periods are calendar days of each company's own timezone.
 */
final class ConsolidatedBusinessQuery
{
    /** Decimal amounts, added with bcmath. */
    private const AMOUNTS = ['mrr', 'revenue_paid', 'recurring_revenue', 'one_time_revenue', 'outstanding'];

    /** Plain counts. */
    private const COUNTS = [
        'active_subscriptions', 'suspended_subscriptions', 'terminated_subscriptions',
        'new_subscriptions', 'churn', 'installation_count',
    ];

    /**
     * @param  Collection<int, Company>  $companies  the consolidation set; the caller owns who may see it
     * @return array{
     *     currency: string,
     *     rows: list<array{company_id: string, metrics: array<string, mixed>, sla: array{resolved: int, on_time: int}}>,
     *     total: array<string, mixed>,
     *     sla: array{resolved: int, on_time: int}
     * }
     *
     * @throws ConsolidationRefusedException when the companies do not share one currency
     */
    public static function execute(Collection $companies, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $currencies = $companies->pluck('currency')->unique()->values()->all();

        if (count($currencies) !== 1) {
            throw new ConsolidationRefusedException($currencies);
        }

        $rows = [];
        foreach ($companies as $company) {
            $rows[] = [
                'company_id' => $company->public_id,
                'metrics' => BusinessMetricsQuery::execute($dateFrom, $dateTo, (int) $company->id, $company->timezone),
                'sla' => [
                    'resolved' => SlaSources::resolved((int) $company->id)->count(),
                    'on_time' => SlaSources::onTime(SlaSources::resolved((int) $company->id))->count(),
                ],
            ];
        }

        $sla = [
            'resolved' => array_sum(array_column(array_column($rows, 'sla'), 'resolved')),
            'on_time' => array_sum(array_column(array_column($rows, 'sla'), 'on_time')),
        ];

        return [
            'currency' => $currencies[0],
            'rows' => $rows,
            'total' => self::total(array_column($rows, 'metrics'), $sla),
            'sla' => $sla,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $metrics  the per-company payloads of BusinessMetricsQuery
     * @param  array{resolved: int, on_time: int}  $sla
     * @return array<string, mixed>
     */
    private static function total(array $metrics, array $sla): array
    {
        $total = [];

        foreach (array_keys($metrics[0] ?? []) as $key) {
            $total[$key] = match (true) {
                in_array($key, self::AMOUNTS, true) => Money::round(array_reduce(
                    array_column($metrics, $key),
                    fn (string $sum, mixed $amount): string => Money::add($sum, $amount ?? 0),
                    '0',
                )),
                in_array($key, self::COUNTS, true) => array_sum(array_column($metrics, $key)),
                $key === 'asset_distribution' => self::mergeCounts(array_column($metrics, $key)),
                $key === 'sla_compliance_pct' => $sla['resolved'] > 0 ? round($sla['on_time'] / $sla['resolved'] * 100, 1) : 0,
                // A new Business metric must say how it consolidates before it can be reported.
                default => throw new LogicException("Business metric [{$key}] has no consolidation rule."),
            };
        }

        return $total;
    }

    /**
     * @param  list<array<string, int>>  $maps
     * @return array<string, int>
     */
    private static function mergeCounts(array $maps): array
    {
        $merged = [];
        foreach ($maps as $map) {
            foreach ($map as $label => $count) {
                $merged[$label] = ($merged[$label] ?? 0) + $count;
            }
        }
        ksort($merged);

        return $merged;
    }
}
