<?php

namespace App\Support\NumberSequences;

use Carbon\CarbonInterface;

/**
 * The format language of a business code: literal text plus {PREFIX} {YYYY} {YY} {MM} {DD} {COMPANY} and exactly
 * one {SEQ:n}. resources/js/lib/numberTemplate.ts runs the same grammar for the live preview; change both together.
 */
final class NumberTemplate
{
    public const MAX_SEQ_WIDTH = 10;

    private const TOKEN = '/\{([A-Z]+)(?::(\d+))?\}/';

    /** Same token, wrapped in one capture group so preg_split keeps it between the literal parts. */
    private const TOKEN_SPLIT = '/(\{[A-Z]+(?::\d+)?\})/';

    private const LITERAL = '/\A[A-Za-z0-9\-_\/.]*\z/';

    private const PLAIN_TOKENS = ['PREFIX', 'YYYY', 'YY', 'MM', 'DD', 'COMPANY'];

    /** @return list<array{token: string, description: string}> */
    public static function tokens(): array
    {
        return [
            ['token' => '{PREFIX}', 'description' => 'Prefix dokumen, diatur di kolom Prefix'],
            ['token' => '{YYYY}', 'description' => 'Tahun 4 digit, mis. 2026'],
            ['token' => '{YY}', 'description' => 'Tahun 2 digit, mis. 26'],
            ['token' => '{MM}', 'description' => 'Bulan 2 digit, mis. 10'],
            ['token' => '{DD}', 'description' => 'Tanggal 2 digit, mis. 03'],
            ['token' => '{COMPANY}', 'description' => 'Kode perusahaan'],
            ['token' => '{SEQ:5}', 'description' => 'Nomor urut berawalan nol, 5 = jumlah digit (1–10). Wajib, tepat satu.'],
        ];
    }

    /** The first problem of a template for a reset period, in Indonesian, or null when it is usable. */
    public static function validate(string $template, ResetPeriod $reset): ?string
    {
        if (preg_match(self::LITERAL, (string) preg_replace(self::TOKEN, '', $template)) !== 1) {
            return 'Gunakan huruf, angka, tanda - _ / . dan token di dalam kurung kurawal.';
        }

        preg_match_all(self::TOKEN, $template, $found, PREG_SET_ORDER);
        $names = [];

        foreach ($found as $match) {
            $width = $match[2] ?? null;
            $known = $match[1] === 'SEQ'
                ? $width !== null && (int) $width >= 1 && (int) $width <= self::MAX_SEQ_WIDTH
                : $width === null && in_array($match[1], self::PLAIN_TOKENS, true);

            if (! $known) {
                return 'Token '.$match[0].' tidak dikenal.';
            }

            $names[] = $match[1];
        }

        if (count(array_keys($names, 'SEQ', true)) !== 1) {
            return 'Format harus memuat tepat satu {SEQ:n}, n antara 1 dan '.self::MAX_SEQ_WIDTH.'.';
        }

        $parts = $reset->dateParts();

        if ($parts >= 1 && ! array_intersect($names, ['YYYY', 'YY'])) {
            return 'Reset berkala memerlukan {YYYY} atau {YY}.';
        }

        if ($parts >= 2 && ! in_array('MM', $names, true)) {
            return 'Reset bulanan atau harian memerlukan {MM}.';
        }

        if ($parts >= 3 && ! in_array('DD', $names, true)) {
            return 'Reset harian memerlukan {DD}.';
        }

        return null;
    }

    /** Digits of the {SEQ:n} token, or null when the template has none. */
    public static function seqWidth(string $template): ?int
    {
        return preg_match('/\{SEQ:(\d+)\}/', $template, $match) === 1 ? (int) $match[1] : null;
    }

    /** `{SEQ:n}` is zero-padded to n digits and never cut; a token this class does not know stays as typed. */
    public static function render(string $template, string $prefix, string $companyCode, int $number, CarbonInterface $at): string
    {
        return (string) preg_replace_callback(self::TOKEN, static fn (array $match): string => match (true) {
            $match[1] === 'SEQ' && isset($match[2]) => str_pad((string) $number, min((int) $match[2], self::MAX_SEQ_WIDTH), '0', STR_PAD_LEFT),
            isset($match[2]) => $match[0],
            default => match ($match[1]) {
                'PREFIX' => $prefix,
                'YYYY' => $at->format('Y'),
                'YY' => $at->format('y'),
                'MM' => $at->format('m'),
                'DD' => $at->format('d'),
                'COMPANY' => $companyCode,
                default => $match[0],
            },
        }, $template);
    }

    /**
     * The sequence number inside `$code`, or null when the code does not have this format in the period that `$at`
     * falls in. Date parts finer than the reset period (the month of a yearly reset) match any value, because the
     * counter runs on through them; with no reset every date part does.
     */
    public static function parse(string $template, string $code, string $prefix, string $companyCode, ResetPeriod $reset, CarbonInterface $at): ?int
    {
        $fixed = $reset->dateParts();
        $date = static fn (int $part, string $format, int $digits): string => $fixed >= $part
            ? preg_quote($at->format($format), '/')
            : '\d{'.$digits.'}';
        $regex = '';

        foreach (preg_split(self::TOKEN_SPLIT, $template, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $index => $part) {
            if ($index % 2 === 0) {
                $regex .= preg_quote($part, '/');

                continue;
            }

            preg_match(self::TOKEN, $part, $match);
            $regex .= match (true) {
                $match[1] === 'SEQ' && isset($match[2]) => '(\d{'.min((int) $match[2], self::MAX_SEQ_WIDTH).','.self::MAX_SEQ_WIDTH.'})',
                isset($match[2]) => preg_quote($part, '/'),
                default => match ($match[1]) {
                    'PREFIX' => preg_quote($prefix, '/'),
                    'COMPANY' => preg_quote($companyCode, '/'),
                    'YYYY' => $date(1, 'Y', 4),
                    'YY' => $date(1, 'y', 2),
                    'MM' => $date(2, 'm', 2),
                    'DD' => $date(3, 'd', 2),
                    default => preg_quote($part, '/'),
                },
            };
        }

        return preg_match('/\A'.$regex.'\z/', $code, $found) === 1 && isset($found[1]) ? (int) $found[1] : null;
    }
}
