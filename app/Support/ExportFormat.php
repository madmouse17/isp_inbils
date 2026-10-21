<?php

namespace App\Support;

use App\Models\Core\Company;
use App\Services\Core\SettingService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * How the PDF of an export writes a cell: the id-ID figure a person reads ("Rp 1.250.000", "18 Okt 2026", "2 j 15 m"),
 * where the CSV keeps the raw value a spreadsheet can use. A column is given its type by whoever builds the export
 * (ExportQuery::types()); a cell that has none prints as it is.
 */
final readonly class ExportFormat
{
    public const TYPES = ['money', 'number', 'percent', 'date', 'datetime', 'duration'];

    /** The types whose cells are figures, which the PDF aligns to the right. */
    public const FIGURES = ['money', 'number', 'percent', 'duration'];

    public function __construct(
        private string $currency = 'IDR',
        private string $dateFormat = 'd M Y',
        private string $timezone = 'UTC',
    ) {}

    /** The company's own currency, date format and clock; the application's defaults when there is none. */
    public static function forCompany(?Company $company): self
    {
        return new self(
            $company?->currency ?: 'IDR',
            (string) (data_get($company?->settings, 'date_format') ?: SettingService::get('default_date_format', 'd M Y')),
            $company?->timezone ?: (string) config('app.timezone'),
        );
    }

    /** @param  string|int|float|null  $value  what the CSV holds: a decimal string, a number, "Y-m-d" or "Y-m-d H:i", minutes */
    public function cell(mixed $value, string $type): string|int|float
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return match ($type) {
                'money' => $this->money($value),
                'number' => self::decimal($value, 2),
                'percent' => self::decimal($value, 1).'%',
                'date' => $this->moment($value)->translatedFormat($this->dateFormat),
                'datetime' => $this->moment($value)->translatedFormat($this->dateFormat.' H:i'),
                'duration' => self::minutes($value),
                default => $value,
            };
        } catch (Throwable) {
            // A value the type does not fit is printed as it is rather than hidden.
            return $value;
        }
    }

    /** "21 Okt 2026 22:15": the moment of printing, in the company's clock. */
    public function now(): string
    {
        return Carbon::now($this->timezone)->locale('id')->translatedFormat($this->dateFormat.' H:i');
    }

    /** "Rp 1.250.000": whole rupiah, cents only when the amount has them; another currency keeps its code and two decimals. */
    public function money(mixed $amount): string
    {
        return $this->currency === 'IDR'
            ? 'Rp '.Money::display($amount)
            : $this->currency.' '.number_format((float) $amount, 2, '.', ',');
    }

    /** "2 j 15 m", "45 m", "35 j": hours and minutes, never days, so a column of them reads the same all the way down. */
    public static function minutes(mixed $minutes): string
    {
        $total = max(0, (int) round((float) $minutes));

        if ($total < 60) {
            return $total.' m';
        }

        $remainder = $total % 60;

        return intdiv($total, 60).' j'.($remainder > 0 ? ' '.$remainder.' m' : '');
    }

    /** "4,3", "1.234": the Indonesian decimal comma, $digits decimals at most, no trailing zeros. */
    public static function decimal(mixed $value, int $digits): string
    {
        $text = number_format((float) $value, $digits, ',', '.');

        return $digits > 0 ? rtrim(rtrim($text, '0'), ',') : $text;
    }

    /** A moment in the company's clock: a bare "Y-m-d H:i" is already its local time, an offset is converted. */
    private function moment(mixed $value): CarbonInterface
    {
        $moment = $value instanceof CarbonInterface ? $value : Carbon::parse((string) $value, $this->timezone);

        return $moment->copy()->setTimezone($this->timezone)->locale('id');
    }
}
