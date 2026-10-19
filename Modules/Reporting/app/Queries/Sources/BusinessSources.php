<?php

namespace Modules\Reporting\Queries\Sources;

use App\Models\Core\ServiceSubscription;
use Illuminate\Database\Eloquent\Builder;
use Modules\Billing\Models\Invoice;
use Modules\Reporting\Queries\Concerns\AppliesDateRange;
use Modules\Reporting\Queries\Concerns\ScopesToCompany;
use Modules\Reporting\Queries\Concerns\ScopesToPackageCategory;
use Modules\SPK\Models\WorkOrder;

/**
 * The records behind the Business report figures. The aggregates in BusinessMetricsQuery and the
 * drill-down lists both read these builders, so a figure and its source rows cannot disagree.
 * Predicates are the v1 formulas as documented in MetricRegistry, quirks included.
 */
final class BusinessSources
{
    use AppliesDateRange;
    use ScopesToCompany;
    use ScopesToPackageCategory;

    /** @return Builder<ServiceSubscription> */
    public static function subscriptions(?int $companyId, ?int $packageCategoryId = null): Builder
    {
        $query = self::scoped(ServiceSubscription::class, $companyId);

        return $packageCategoryId === null
            ? $query
            : $query->whereIn('service_subscriptions.service_package_version_id', self::packageVersionIdsInCategory(self::companyOf($companyId), $packageCategoryId));
    }

    /** @return Builder<ServiceSubscription> */
    public static function subscriptionsInStatus(?int $companyId, string $status, ?int $packageCategoryId = null): Builder
    {
        return self::subscriptions($companyId, $packageCategoryId)->where('status', $status);
    }

    /** @return Builder<Invoice> */
    public static function invoices(?int $companyId, ?int $packageCategoryId = null): Builder
    {
        return self::inCategory(self::scoped(Invoice::class, $companyId), $companyId, $packageCategoryId);
    }

    /**
     * Invoices of subscriptions sold under a package version of the category; invoices without a subscription fall outside it.
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    private static function inCategory(Builder $query, ?int $companyId, ?int $packageCategoryId): Builder
    {
        return $packageCategoryId === null
            ? $query
            : $query->whereIn('invoices.subscription_id', self::subscriptionIdsInCategory(self::companyOf($companyId), $packageCategoryId));
    }

    /** A category filter needs the company explicit, like every sub-select of ScopesToPackageCategory. */
    private static function companyOf(?int $companyId): int
    {
        abort_if($companyId === null, 403);

        return $companyId;
    }

    /**
     * Created inside the period. v1: without any range the figure is 0, so the source is empty too. A half range
     * (From or To alone) applies as set. The dates are calendar days of the given timezone (consolidation) or,
     * without one, of the current company's timezone.
     *
     * @return Builder<ServiceSubscription>
     */
    public static function newSubscriptions(?int $companyId, ?string $dateFrom, ?string $dateTo, ?string $timezone = null, ?int $packageCategoryId = null): Builder
    {
        $query = self::subscriptions($companyId, $packageCategoryId);

        if (self::dateRange($dateFrom, $dateTo, $timezone) === null) {
            return $query->whereKey([]);
        }

        self::applyDateRange($query, 'created_at', $dateFrom, $dateTo, $timezone);

        return $query;
    }

    /**
     * Terminated inside the period; empty when no range is set (v1).
     *
     * @return Builder<ServiceSubscription>
     */
    public static function churn(?int $companyId, ?string $dateFrom, ?string $dateTo, ?string $timezone = null, ?int $packageCategoryId = null): Builder
    {
        $query = self::subscriptionsInStatus($companyId, 'terminated', $packageCategoryId);

        if (self::dateRange($dateFrom, $dateTo, $timezone) === null) {
            return $query->whereKey([]);
        }

        self::applyDateRange($query, 'terminated_at', $dateFrom, $dateTo, $timezone);

        return $query;
    }

    /**
     * Fully paid invoices issued inside the period (every invoice when no range is set).
     * issue_date is a calendar date, so no timezone applies to it.
     *
     * @return Builder<Invoice>
     */
    public static function paidInvoices(?int $companyId, ?string $dateFrom, ?string $dateTo, ?string $type = null, ?int $packageCategoryId = null): Builder
    {
        $query = self::inCategory(self::scoped(Invoice::class, $companyId)->where('status', 'paid'), $companyId, $packageCategoryId);
        self::applyDateOnlyRange($query, 'issue_date', $dateFrom, $dateTo);

        return $type === null ? $query : $query->where('type', $type);
    }

    /** @return Builder<Invoice> */
    public static function outstandingInvoices(?int $companyId, ?int $packageCategoryId = null): Builder
    {
        return self::inCategory(self::scoped(Invoice::class, $companyId)->whereIn('status', ['sent', 'partial', 'overdue']), $companyId, $packageCategoryId);
    }

    /** @return Builder<WorkOrder> */
    public static function completedInstallations(?int $companyId, ?string $dateFrom, ?string $dateTo, ?string $timezone = null, ?int $packageCategoryId = null): Builder
    {
        $query = self::scoped(WorkOrder::class, $companyId)
            ->where('type', 'installation')
            ->where('status', 'completed');
        self::applyDateRange($query, 'completed_at', $dateFrom, $dateTo, $timezone);

        return $packageCategoryId === null
            ? $query
            : $query->whereIn('work_orders.subscription_id', self::subscriptionIdsInCategory(self::companyOf($companyId), $packageCategoryId));
    }
}
