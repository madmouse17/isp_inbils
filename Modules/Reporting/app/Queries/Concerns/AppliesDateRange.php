<?php

namespace Modules\Reporting\Queries\Concerns;

use App\Services\Core\CompanyService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

trait AppliesDateRange
{
    /** @param Builder<*> $query */
    protected static function applyDateRange(Builder $query, string $column, ?string $dateFrom, ?string $dateTo, ?string $timezone = null): void
    {
        $range = self::dateRange($dateFrom, $dateTo, $timezone);

        if ($range === null) {
            return;
        }

        [$start, $end] = $range;

        if ($start !== null) {
            $query->where($column, '>=', $start);
        }

        if ($end !== null) {
            $query->where($column, '<=', $end);
        }
    }

    /**
     * Same half-range semantics for a DATE column (invoice issue date, subscription activation date): calendar
     * days compare as they are, no zone applies.
     *
     * @param  Builder<*>  $query
     */
    protected static function applyDateOnlyRange(Builder $query, string $column, ?string $dateFrom, ?string $dateTo): void
    {
        if ($dateFrom) {
            $query->where($column, '>=', Carbon::parse($dateFrom)->toDateString());
        }

        if ($dateTo) {
            $query->where($column, '<=', Carbon::parse($dateTo)->toDateString());
        }
    }

    /**
     * Start of From and end of To as datetime strings; null when neither date is set. A half range keeps
     * the other bound null: From alone means from that day on, To alone means everything up to that day.
     * The dates are calendar days of $timezone (the current company's timezone when null, the application
     * one when it has none) and the bounds come back in the application timezone, which is how timestamps
     * are stored.
     *
     * @return array{0: string|null, 1: string|null}|null
     */
    protected static function dateRange(?string $dateFrom, ?string $dateTo, ?string $timezone = null): ?array
    {
        if (! $dateFrom && ! $dateTo) {
            return null;
        }

        $storage = config('app.timezone');
        $timezone ??= CompanyService::current()?->timezone ?: $storage;

        return [
            $dateFrom ? Carbon::parse($dateFrom, $timezone)->startOfDay()->setTimezone($storage)->toDateTimeString() : null,
            $dateTo ? Carbon::parse($dateTo, $timezone)->endOfDay()->setTimezone($storage)->toDateTimeString() : null,
        ];
    }
}
