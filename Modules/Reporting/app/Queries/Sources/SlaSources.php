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
 * neither on time nor breached. The response figures follow the same rule: a ticket without a
 * response target (every ticket created before targets existed) is in neither of them.
 */
final class SlaSources
{
    use AppliesDateRange;
    use ScopesToCompany;

    /**
     * The category of a ticket in a breakdown, over tickets joined to ticket_categories: the name it closed under, the
     * live name until it closes. The by-category figures and their drill lists group and match on this one expression,
     * so renaming a category never regroups a closed ticket.
     */
    public const CATEGORY_LABEL = 'COALESCE(tickets.category_name_snapshot, ticket_categories.name)';

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
     * Tickets whose first response came within their response target.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function respondedOnTime(Builder $query): Builder
    {
        return $query->whereColumn('first_response_at', '<=', 'response_deadline');
    }

    /**
     * Tickets whose first response came after their response target.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function responseBreached(Builder $query): Builder
    {
        return $query->whereColumn('first_response_at', '>', 'response_deadline');
    }

    /**
     * Tickets that were responded to and carry a response target: on time or breached, nothing else.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function responseJudged(Builder $query): Builder
    {
        return $query->whereNotNull('first_response_at')->whereNotNull('response_deadline');
    }

    /**
     * Tickets created without a response target.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function withoutResponseTarget(Builder $query): Builder
    {
        return $query->whereNull('response_deadline');
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
     * Resolved tickets joined to their category for the per-category breakdown, which names each by CATEGORY_LABEL.
     * v1: ignores the period and category filters of the report.
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
