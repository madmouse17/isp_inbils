<?php

namespace App\Support;

/**
 * Decimal-safe money math backed by bcmath. All inputs/outputs are numeric
 * strings (or int/float, normalized without float arithmetic) to avoid
 * float rounding drift (the classic 0.1 + 0.2 class of bugs).
 */
final class Money
{
    /**
     * Internal working scale. Must be high enough that intermediate
     * division (e.g. proration 20/30) does not truncate before the final
     * mul + round-to-cents. 10 gives ample headroom for any money ratio.
     */
    private const SCALE = 10;

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

    /**
     * Round to whole rupiah, half up (half away from zero for a negative), as a 2-decimal string ("147702.00") so it
     * stores and compares like every other amount. One guard digit of the full-precision value decides, so 147701.495
     * stays 147701 instead of passing through cents (147701.50) and rounding up a second time.
     */
    public static function rupiah(string|int|float $a): string
    {
        $value = bcadd(self::norm($a), '0', 1);
        $negative = str_starts_with($value, '-');
        [$whole, $tenths] = explode('.', ltrim($value, '+-'), 2);
        $rounded = (int) $tenths >= 5 ? bcadd($whole, '1', 0) : $whole;

        return ($negative && $rounded !== '0' ? '-' : '').$rounded.'.00';
    }

    /**
     * An amount as id-ID text for Blade and PDF: "147.702", and "147.701,62" only when it has cents. A stored amount is
     * never rounded here, so an invoice that predates whole-rupiah billing still prints what it owes.
     */
    public static function display(string|int|float $a): string
    {
        $value = self::round($a);
        $negative = str_starts_with($value, '-');
        [$whole, $cents] = explode('.', ltrim($value, '-'), 2);
        $grouped = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole);

        return ($negative ? '-' : '').$grouped.($cents === '00' ? '' : ','.$cents);
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
