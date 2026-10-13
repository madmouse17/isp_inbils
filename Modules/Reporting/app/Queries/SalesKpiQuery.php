<?php

namespace Modules\Reporting\Queries;

use App\Models\User;
use App\Queries\Admin\CustomerAcquirerQuery;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\Reporting\Models\SalesCustomer;
use Modules\Reporting\Queries\Concerns\AppliesDateRange;
use Modules\Reporting\Queries\Concerns\ScopesToPackageCategory;
use Modules\Reporting\Support\TimeBuckets;

/**
 * Sales KPI (formula sales.v1): customers, subscriptions, invoiced, collected and outstanding money per acquirer,
 * where an acquirer is whoever `customers.acquired_by_user_id` credits today. Formulas live in MetricRegistry.
 *
 * Eloquent only. Every figure is a relation aggregate of one customer pass (customers with their subscription,
 * invoice and payment sums computed by the database), then summed per acquirer in PHP with bcmath, so the number
 * of queries does not grow with the number of acquirers. Every sub-query carries the explicit company id.
 */
final class SalesKpiQuery
{
    use AppliesDateRange;
    use ScopesToPackageCategory;

    /** Sort keys accepted by the report: the acquirer name and every metric. */
    public const SORTABLE = [
        'name', 'customers_acquired', 'subscriptions_activated', 'active_mrr',
        'invoiced', 'collected', 'outstanding', 'collection_rate',
    ];

    private const INVOICED_STATUSES = ['sent', 'partial', 'paid', 'overdue'];

    private const OPEN_STATUSES = ['sent', 'partial', 'overdue'];

    private const CHUNK = 1000;

    /**
     * @return array{
     *     acquirers: list<array{id: int, name: string, is_active: bool}>,
     *     rows: list<array<string, mixed>>,
     *     unassigned: array<string, mixed>|null,
     *     totals: array<string, mixed>
     * }
     */
    public static function execute(
        int $companyId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $acquirerId = null,
        string $sortBy = 'name',
        string $sortDir = 'asc',
        ?int $packageCategoryId = null,
    ): array {
        $acquirers = self::acquirers($companyId);
        abort_if($acquirerId !== null && ! array_key_exists($acquirerId, $acquirers), 403);

        $range = self::dateRange($dateFrom, $dateTo);
        $buckets = [];
        $unassigned = self::blank();

        foreach (self::customers($companyId, $dateFrom, $dateTo, $acquirerId, $packageCategoryId)->lazyById(self::CHUNK, 'customers.id', 'id') as $customer) {
            $credited = $customer->acquired_by_user_id;

            if ($credited !== null && array_key_exists($credited, $acquirers)) {
                $buckets[$credited] ??= self::blank();
                self::accumulate($buckets[$credited], $customer, $range);
            } else {
                self::accumulate($unassigned, $customer, $range);
            }
        }

        $rows = [];
        $totals = self::blank();
        foreach ($acquirers as $id => $acquirer) {
            if ($acquirerId !== null && $acquirerId !== $id) {
                continue;
            }

            $bucket = $buckets[$id] ?? self::blank();
            $totals = self::merge($totals, $bucket);
            $rows[] = [...$acquirer, ...self::figures($bucket)];
        }

        if ($acquirerId !== null) {
            $unassigned = null;
        } else {
            $totals = self::merge($totals, $unassigned);
        }

        return [
            'acquirers' => array_values($acquirers),
            'rows' => self::sort($rows, $sortBy, $sortDir),
            'unassigned' => $unassigned === null ? null : self::figures($unassigned),
            'totals' => self::figures($totals),
        ];
    }

    /**
     * What the Sales page draws besides the figures of execute(): customers acquired per time bucket (days of the
     * company timezone; the figures of execute() read days of the application timezone, so a row near midnight can
     * sit in another day) and every acquirer of $report, not only the page of rows on screen. The unassigned
     * customers are not an acquirer row; they stay in the report's own `unassigned` figures.
     *
     * @param  array{rows: list<array<string, mixed>>}  $report  the result of execute()
     * @return array{
     *     series: array{granularity: string, points: list<array<string, int|float|string>>},
     *     acquirers: list<array{name: string, customers_acquired: int, collected: float}>
     * }
     */
    public static function charts(array $report, int $companyId, ?string $dateFrom, ?string $dateTo, ?int $acquirerId = null): array
    {
        $timezone = TimeBuckets::timezoneOf($companyId);

        $acquired = SalesCustomer::query()
            ->forCompany($companyId)
            ->when($acquirerId !== null, fn (Builder $query) => $query->where('customers.acquired_by_user_id', $acquirerId));
        self::applyDateRange($acquired, 'customers.created_at', $dateFrom, $dateTo, $timezone);

        $oldest = $dateFrom === null ? (clone $acquired)->min('customers.created_at') : null;
        $buckets = TimeBuckets::forPeriod($dateFrom, $dateTo, $timezone, $oldest === null ? null : CarbonImmutable::parse($oldest, (string) config('app.timezone')));
        $counts = $acquired->selectRaw($buckets->sqlExpression('customers.created_at').' as bucket, count(*) as total')
            ->groupBy('bucket')->pluck('total', 'bucket')
            ->map(fn ($total): int => (int) $total)->all();

        return [
            'series' => $buckets->series(['new_customers' => $counts]),
            'acquirers' => array_map(static fn (array $row): array => [
                'name' => $row['name'],
                'customers_acquired' => $row['customers_acquired'],
                'collected' => (float) $row['collected'],
            ], $report['rows']),
        ];
    }

    /**
     * Eligible acquirers of the company plus every user credited on a customer of the company, soft-deleted
     * customers included (their cash still counts), by name. Keyed by user id.
     *
     * @return array<int, array{id: int, name: string, is_active: bool}>
     */
    private static function acquirers(int $companyId): array
    {
        $credited = SalesCustomer::query()
            ->forCompany($companyId)
            ->withTrashed()
            ->whereNotNull('customers.acquired_by_user_id')
            ->select('customers.acquired_by_user_id');

        return User::query()
            ->where('users.company_id', $companyId)
            ->where(fn (Builder $query) => $query
                ->whereIn('users.id', CustomerAcquirerQuery::eligible($companyId)->select('users.id'))
                ->orWhereIn('users.id', $credited))
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->get(['users.id', 'users.name', 'users.is_active'])
            ->mapWithKeys(fn (User $user): array => [
                $user->id => ['id' => $user->id, 'name' => $user->name, 'is_active' => (bool) $user->is_active],
            ])
            ->all();
    }

    /**
     * Every customer of the company, soft-deleted ones included, with the sums the report needs.
     *
     * @return Builder<SalesCustomer>
     */
    private static function customers(int $companyId, ?string $dateFrom, ?string $dateTo, ?int $acquirerId, ?int $packageCategoryId): Builder
    {
        // With a category, money and subscriptions count only through subscriptions on packages of it.
        $packages = static fn (Builder $query): Builder => $packageCategoryId === null
            ? $query
            : $query->whereIn('service_subscriptions.service_package_id', self::packageIdsInCategory($companyId, $packageCategoryId));
        $subscriptions = static fn (Builder $query): Builder => $packageCategoryId === null
            ? $query
            : $query->whereIn('invoices.subscription_id', self::subscriptionIdsInCategory($companyId, $packageCategoryId));
        $open = static fn (Builder $query): Builder => $subscriptions($query
            ->forCompany($companyId)
            ->whereIn('invoices.status', self::OPEN_STATUSES));

        return SalesCustomer::query()
            ->forCompany($companyId)
            ->withTrashed()
            ->when($acquirerId !== null, fn (Builder $query) => $query->where('customers.acquired_by_user_id', $acquirerId))
            ->select(['customers.id', 'customers.acquired_by_user_id', 'customers.created_at', 'customers.deleted_at'])
            ->withCount(['subscriptions as activated' => static function (Builder $query) use ($companyId, $dateFrom, $dateTo, $packages): void {
                $packages($query->forCompany($companyId)->whereNotNull('service_subscriptions.activation_date'));
                self::applyDateOnlyRange($query, 'service_subscriptions.activation_date', $dateFrom, $dateTo);
            }])
            ->withSum(['subscriptions as mrr' => static fn (Builder $query): Builder => $packages($query
                ->forCompany($companyId)
                ->where('service_subscriptions.status', 'active'))], 'mrc_amount')
            ->withSum(['invoices as invoiced' => static function (Builder $query) use ($companyId, $dateFrom, $dateTo, $subscriptions): void {
                $subscriptions($query->forCompany($companyId)->whereIn('invoices.status', self::INVOICED_STATUSES));
                self::applyDateOnlyRange($query, 'invoices.issue_date', $dateFrom, $dateTo);
            }], 'total')
            ->withSum(['payments as collected' => static function (Builder $query) use ($companyId, $dateFrom, $dateTo, $subscriptions): void {
                $subscriptions($query->forCompany($companyId)->whereNull('payments.cancelled_at'));
                self::applyDateRange($query, 'payments.paid_at', $dateFrom, $dateTo);
            }], 'amount')
            ->withSum(['invoices as open_total' => $open], 'total')
            ->withSum(['invoices as open_paid' => $open], 'paid_amount');
    }

    /**
     * @return array{customers_acquired: int, subscriptions_activated: int, active_mrr: string, invoiced: string, collected: string, outstanding: string}
     */
    private static function blank(): array
    {
        return [
            'customers_acquired' => 0,
            'subscriptions_activated' => 0,
            'active_mrr' => '0',
            'invoiced' => '0',
            'collected' => '0',
            'outstanding' => '0',
        ];
    }

    /**
     * @param  array<string, int|string>  $bucket
     * @param  array{0: string|null, 1: string|null}|null  $range
     */
    private static function accumulate(array &$bucket, SalesCustomer $customer, ?array $range): void
    {
        if ($customer->deleted_at === null && ($range === null || self::within($customer->created_at, $range))) {
            $bucket['customers_acquired']++;
        }

        $bucket['subscriptions_activated'] += (int) $customer->getAttribute('activated');
        $bucket['active_mrr'] = Money::add($bucket['active_mrr'], $customer->getAttribute('mrr') ?? 0);
        $bucket['invoiced'] = Money::add($bucket['invoiced'], $customer->getAttribute('invoiced') ?? 0);
        $bucket['collected'] = Money::add($bucket['collected'], $customer->getAttribute('collected') ?? 0);
        $bucket['outstanding'] = Money::add(
            $bucket['outstanding'],
            Money::sub($customer->getAttribute('open_total') ?? 0, $customer->getAttribute('open_paid') ?? 0),
        );
    }

    /** @param array{0: string|null, 1: string|null} $range */
    private static function within(?CarbonInterface $createdAt, array $range): bool
    {
        if ($createdAt === null) {
            return false;
        }

        $stamp = $createdAt->toDateTimeString();

        return ($range[0] === null || $stamp >= $range[0]) && ($range[1] === null || $stamp <= $range[1]);
    }

    /**
     * @param  array<string, int|string>  $total
     * @param  array<string, int|string>  $bucket
     * @return array<string, int|string>
     */
    private static function merge(array $total, array $bucket): array
    {
        foreach (['customers_acquired', 'subscriptions_activated'] as $count) {
            $total[$count] += $bucket[$count];
        }
        foreach (['active_mrr', 'invoiced', 'collected', 'outstanding'] as $money) {
            $total[$money] = Money::add($total[$money], $bucket[$money]);
        }

        return $total;
    }

    /**
     * Report figures of a bucket: money as 2-decimal strings, the rate in percent or null when nothing was invoiced.
     *
     * @param  array<string, int|string>  $bucket
     * @return array<string, int|string|null>
     */
    private static function figures(array $bucket): array
    {
        $invoiced = Money::round($bucket['invoiced']);
        $collected = Money::round($bucket['collected']);

        return [
            'customers_acquired' => $bucket['customers_acquired'],
            'subscriptions_activated' => $bucket['subscriptions_activated'],
            'active_mrr' => Money::round($bucket['active_mrr']),
            'invoiced' => $invoiced,
            'collected' => $collected,
            'outstanding' => Money::round($bucket['outstanding']),
            'collection_rate' => Money::compare($invoiced, '0') === 0
                ? null
                : Money::round(Money::div(Money::mul($collected, '100'), $invoiced)),
        ];
    }

    /**
     * Sorts by the requested key, then by name, then by id. An empty collection rate is never comparable, so it
     * stays last in both directions.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function sort(array $rows, string $sortBy, string $sortDir): array
    {
        $sortBy = in_array($sortBy, self::SORTABLE, true) ? $sortBy : 'name';
        $direction = strtolower($sortDir) === 'desc' ? -1 : 1;
        $byName = static fn (array $a, array $b): int => strcmp(mb_strtolower($a['name']), mb_strtolower($b['name']));

        usort($rows, static function (array $a, array $b) use ($sortBy, $direction, $byName): int {
            if ($sortBy === 'collection_rate' && ($a[$sortBy] === null) !== ($b[$sortBy] === null)) {
                return $a[$sortBy] === null ? 1 : -1;
            }

            $primary = match ($sortBy) {
                'name' => $byName($a, $b),
                'customers_acquired', 'subscriptions_activated' => $a[$sortBy] <=> $b[$sortBy],
                default => $a[$sortBy] === null ? 0 : Money::compare($a[$sortBy], $b[$sortBy]),
            };

            $result = $primary * $direction;

            return $result !== 0 ? $result : ($byName($a, $b) ?: $a['id'] <=> $b['id']);
        });

        return $rows;
    }
}
