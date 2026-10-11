<?php

namespace Modules\Reporting\Queries\Sources;

use App\Models\Core\ServiceSubscription;
use Illuminate\Database\Eloquent\Builder;
use Modules\Billing\Models\Invoice;
use Modules\Reporting\Queries\Concerns\AppliesDateRange;
use Modules\Reporting\Queries\Concerns\ScopesToCompany;
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

    /** @return Builder<ServiceSubscription> */
    public static function subscriptions(?int $companyId): Builder
    {
        return self::scoped(ServiceSubscription::class, $companyId);
    }

    /** @return Builder<ServiceSubscription> */
    public static function subscriptionsInStatus(?int $companyId, string $status): Builder
    {
        return self::subscriptions($companyId)->where('status', $status);
    }

    /**
     * Created inside the period. v1: the period applies only when both dates are set, otherwise
     * the figure is 0, so the source is empty too. A timezone makes the dates calendar days of
     * that zone (consolidation); without one they are days of the application timezone, as in v1.
     *
     * @return Builder<ServiceSubscription>
     */
    public static function newSubscriptions(?int $companyId, ?string $dateFrom, ?string $dateTo, ?string $timezone = null): Builder
    {
        $query = self::subscriptions($companyId);

        if (self::dateRange($dateFrom, $dateTo, $timezone) === null) {
            return $query->whereKey([]);
        }

        self::applyDateRange($query, 'created_at', $dateFrom, $dateTo, $timezone);

        return $query;
    }

    /**
     * Terminated inside the period; empty unless both dates are set (v1).
     *
     * @return Builder<ServiceSubscription>
     */
    public static function churn(?int $companyId, ?string $dateFrom, ?string $dateTo, ?string $timezone = null): Builder
    {
        $query = self::subscriptionsInStatus($companyId, 'terminated');

        if (self::dateRange($dateFrom, $dateTo, $timezone) === null) {
            return $query->whereKey([]);
        }

        self::applyDateRange($query, 'terminated_at', $dateFrom, $dateTo, $timezone);

        return $query;
    }

    /**
     * Fully paid invoices issued inside the period (every invoice when no complete range is set).
     * issue_date is a calendar date, so no timezone applies to it.
     *
     * @return Builder<Invoice>
     */
    public static function paidInvoices(?int $companyId, ?string $dateFrom, ?string $dateTo, ?string $type = null): Builder
    {
        $query = self::scoped(Invoice::class, $companyId)->where('status', 'paid');
        self::applyDateRange($query, 'issue_date', $dateFrom, $dateTo);

        return $type === null ? $query : $query->where('type', $type);
    }

    /** @return Builder<Invoice> */
    public static function outstandingInvoices(?int $companyId): Builder
    {
        return self::scoped(Invoice::class, $companyId)->whereIn('status', ['sent', 'partial', 'overdue']);
    }

    /** @return Builder<WorkOrder> */
    public static function completedInstallations(?int $companyId, ?string $dateFrom, ?string $dateTo, ?string $timezone = null): Builder
    {
        $query = self::scoped(WorkOrder::class, $companyId)
            ->where('type', 'installation')
            ->where('status', 'completed');
        self::applyDateRange($query, 'completed_at', $dateFrom, $dateTo, $timezone);

        return $query;
    }
}
