<?php

namespace Modules\Reporting\Queries\Sources;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Reporting\Queries\Concerns\AppliesDateRange;
use Modules\Reporting\Queries\Concerns\ScopesToCompany;
use Modules\Ticketing\Models\Ticket;

/**
 * The tickets behind the SLA figures (also used by the Business SLA percentage). Shared by the
 * aggregates and the drill-down lists; v1 quirks stay: a ticket without a deadline is resolved but
 * neither on time nor breached.
 */
final class SlaSources
{
    use AppliesDateRange;
    use ScopesToCompany;

    /**
     * Resolved tickets (resolved_at set, whatever their status), optionally inside a period and category.
     *
     * @return Builder<Ticket>
     */
    public static function resolved(?int $companyId, ?string $dateFrom = null, ?string $dateTo = null, ?int $categoryId = null): Builder
    {
        $query = self::scoped(Ticket::class, $companyId)->whereNotNull('resolved_at');
        self::applyDateRange($query, 'resolved_at', $dateFrom, $dateTo);

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        return $query;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function onTime(Builder $query): Builder
    {
        return $query->whereColumn('resolved_at', '<=', 'sla_deadline');
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function breached(Builder $query): Builder
    {
        return $query->whereColumn('resolved_at', '>', 'sla_deadline');
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function withFirstResponse(Builder $query): Builder
    {
        return $query->whereNotNull('first_response_at');
    }

    /**
     * Resolved tickets joined to their category for the per-category breakdown. v1: ignores the
     * period and category filters of the report.
     *
     * @return Builder<Ticket>
     */
    public static function resolvedByCategory(?int $companyId): Builder
    {
        return self::scoped(Ticket::class, $companyId)
            ->whereNotNull('resolved_at')
            ->join('ticket_categories', 'tickets.category_id', '=', 'ticket_categories.id');
    }
}
