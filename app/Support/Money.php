<?php

namespace App\Support;

/**
 * Decimal-safe money math backed by bcmath. All inputs/outputs are numeric
 * strings (or int/float, normalized without float arithmetic) to avoid
 * float rounding drift (the classic 0.1 + 0.2 class of bugs).
 */
final class Money
{
    /** Internal working scale (headroom before rounding to cents). */
    private const SCALE = 4;

    public static function add(string|int|float $a, string|int|float $b): string
    {
        return bcadd(self::norm($a), self::norm($b), self::SCALE);
    }

    public static function sub(string|int|float $a, string|int|float $b): string
    {
        return bcsub(self::norm($a), self::norm($b), self::SCALE);
    }

    public static function mul(string|int|float $a, string|int|float $b): string
    {
        return bcmul(self::norm($a), self::norm($b), self::SCALE);
    }

    public static function div(string|int|float $a, string|int|float $b): string
    {
        return bcdiv(self::norm($a), self::norm($b), self::SCALE);
    }

    /** Round to 2 decimals (money) as string, for storage/comparison. */
    public static function round(string|int|float $a): string
    {
        $value = bcadd(self::norm($a), '0', 3);
        $negative = str_starts_with($value, '-');
        $abs = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $abs, 2), 2, '0');
        $fraction = str_pad($fraction, 3, '0');
        $rounded = $whole.'.'.substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            $rounded = bcadd($rounded, '0.01', 2);
        }

        return $negative ? bcsub('0', $rounded, 2) : bcadd($rounded, '0', 2);
    }

    /** bccomp semantics at cent precision: -1, 0, or 1. */
    public static function compare(string|int|float $a, string|int|float $b): int
    {
        return bccomp(self::round($a), self::round($b), 2);
    }

    public static function gt(string|int|float $a, string|int|float $b): bool
    {
        return self::compare($a, $b) > 0;
    }

    private static function norm(string|int|float $v): string
    {
        // sprintf avoids feeding float arithmetic artifacts into bcmath.
        return is_float($v) ? sprintf('%.10F', $v) : (string) $v;
    }
}
