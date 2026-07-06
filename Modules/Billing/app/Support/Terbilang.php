<?php

namespace Modules\Billing\Support;

final class Terbilang
{
    private const ANGKA = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];

    public static function make(float $number): string
    {
        $n = (int) floor(abs($number));
        $words = $n === 0 ? 'nol' : self::convert($n);

        return trim(preg_replace('/\s+/', ' ', $words)) . ' rupiah';
    }

    private static function convert(int $n): string
    {
        return match (true) {
            $n === 0 => '',
            $n < 12 => self::ANGKA[$n],
            $n < 20 => self::convert($n - 10) . ' belas',
            $n < 100 => self::convert(intdiv($n, 10)) . ' puluh ' . self::convert($n % 10),
            $n < 200 => 'seratus ' . self::convert($n - 100),
            $n < 1000 => self::convert(intdiv($n, 100)) . ' ratus ' . self::convert($n % 100),
            $n < 2000 => 'seribu ' . self::convert($n - 1000),
            $n < 1000000 => self::convert(intdiv($n, 1000)) . ' ribu ' . self::convert($n % 1000),
            $n < 1000000000 => self::convert(intdiv($n, 1000000)) . ' juta ' . self::convert($n % 1000000),
            default => self::convert(intdiv($n, 1000000000)) . ' miliar ' . self::convert($n % 1000000000),
        };
    }
}
