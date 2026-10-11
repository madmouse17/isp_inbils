<?php

namespace Modules\Reporting\Queries\Concerns;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

trait AppliesDateRange
{
    /** @param Builder<*> $query */
    protected static function applyDateRange(Builder $query, string $column, ?string $dateFrom, ?string $dateTo, ?string $timezone = null): void
    {
        $range = self::dateRange($dateFrom, $dateTo, $timezone);

        if ($range !== null) {
            $query->whereBetween($column, $range);
        }
    }

    /**
     * Start of From and end of To as datetime strings; null unless both dates are set. The dates are
     * calendar days of $timezone (the application timezone when null) and the bounds come back in the
     * application timezone, which is how timestamps are stored.
     *
     * @return array{0: string, 1: string}|null
     */
    protected static function dateRange(?string $dateFrom, ?string $dateTo, ?string $timezone = null): ?array
    {
        if (! $dateFrom || ! $dateTo) {
            return null;
        }

        $storage = config('app.timezone');

        return [
            Carbon::parse($dateFrom, $timezone)->startOfDay()->setTimezone($storage)->toDateTimeString(),
            Carbon::parse($dateTo, $timezone)->endOfDay()->setTimezone($storage)->toDateTimeString(),
        ];
    }
}
