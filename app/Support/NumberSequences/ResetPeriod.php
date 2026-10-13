<?php

namespace App\Support\NumberSequences;

use Carbon\CarbonInterface;

enum ResetPeriod: string
{
    case Never = 'never';
    case Yearly = 'yearly';
    case Monthly = 'monthly';
    case Daily = 'daily';

    /** How many leading date parts (year, month, day) tell one period of this reset from the next. */
    public function dateParts(): int
    {
        return match ($this) {
            self::Never => 0,
            self::Yearly => 1,
            self::Monthly => 2,
            self::Daily => 3,
        };
    }

    /** Label of the period that `$at` falls in; null while the counter never resets. */
    public function key(CarbonInterface $at): ?string
    {
        return match ($this) {
            self::Never => null,
            self::Yearly => $at->format('Y'),
            self::Monthly => $at->format('Y-m'),
            self::Daily => $at->format('Y-m-d'),
        };
    }
}
