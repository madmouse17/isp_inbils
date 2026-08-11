<?php

namespace Modules\Reporting\Queries\Concerns;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

trait AppliesDateRange
{
    /** @param Builder<*> $query */
    protected static function applyDateRange(Builder $query, string $column, ?string $dateFrom, ?string $dateTo): void
    {
        if ($dateFrom && $dateTo) {
            $query->whereBetween($column, [
                Carbon::parse($dateFrom)->startOfDay()->toDateTimeString(),
                Carbon::parse($dateTo)->endOfDay()->toDateTimeString(),
            ]);
        }
    }
}
