<?php

namespace Modules\Reporting\Support;

use App\Models\Core\Company;
use App\Services\Core\CompanyService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The time buckets of a report series: a granularity chosen from the length of the period, the ordered
 * bucket starts that cover it and the zone they are drawn in. Queries group their rows by sqlExpression()
 * (or sqlDateExpression() for a DATE column) and hand the grouped values to series(), which adds the empty
 * buckets.
 *
 * A bucket is named by its first calendar day (Y-m-d): the day itself, the Monday of an ISO week or the 1st
 * of a month. Calendar arithmetic runs on UTC dates, so no bucket depends on a daylight saving shift.
 *
 * Granularity by the length of the period, both ends included: up to 31 days per day, up to 184 days per ISO
 * week, longer per month. A period without a start (all time) is drawn per month from the oldest row. Month
 * buckets are capped at 36; older months fold into the first one.
 *
 * @phpstan-type Granularity 'day'|'week'|'month'
 */
final class TimeBuckets
{
    public const DAY = 'day';

    public const WEEK = 'week';

    public const MONTH = 'month';

    private const MAX_DAY_DAYS = 31;

    private const MAX_WEEK_DAYS = 184;

    private const MAX_MONTHS = 36;

    /**
     * @param  Granularity  $granularity
     * @param  list<string>  $keys
     */
    private function __construct(
        private readonly string $granularity,
        private readonly string $timezone,
        private readonly array $keys,
        private readonly string $lastDay,
    ) {}

    /**
     * $from and $to are calendar days as written (their time and zone are ignored). A missing $to is today
     * in $tz; a missing $from starts at $earliest, the instant of the oldest row, and draws per month.
     * A $to before $from collapses onto $from.
     */
    public static function forRange(?CarbonImmutable $from, ?CarbonImmutable $to, string $tz, ?CarbonInterface $earliest = null): self
    {
        if (! in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException("Unknown timezone [{$tz}].");
        }

        $last = $to?->toDateString() ?? CarbonImmutable::now($tz)->toDateString();

        if ($from === null) {
            $granularity = self::MONTH;
            $first = $earliest === null ? $last : min($last, CarbonImmutable::instance($earliest)->setTimezone($tz)->toDateString());
        } else {
            $first = $from->toDateString();
            $last = max($last, $first);
            $days = (int) CarbonImmutable::parse($first, 'UTC')->diffInDays(CarbonImmutable::parse($last, 'UTC')) + 1;
            $granularity = match (true) {
                $days <= self::MAX_DAY_DAYS => self::DAY,
                $days <= self::MAX_WEEK_DAYS => self::WEEK,
                default => self::MONTH,
            };
        }

        return new self($granularity, $tz, self::keysBetween($granularity, $first, $last), $last);
    }

    /** forRange() for the From and To strings of a report filter. */
    public static function forPeriod(?string $dateFrom, ?string $dateTo, string $tz, ?CarbonInterface $earliest = null): self
    {
        return self::forRange(
            $dateFrom ? CarbonImmutable::parse($dateFrom) : null,
            $dateTo ? CarbonImmutable::parse($dateTo) : null,
            $tz,
            $earliest,
        );
    }

    /** The zone a company's series are drawn in: its own, else the application's. */
    public static function timezoneOf(?int $companyId): string
    {
        $companyId ??= CompanyService::currentId();
        $timezone = $companyId === null ? null : Company::query()->whereKey($companyId)->value('timezone');

        return $timezone ?: (string) config('app.timezone');
    }

    /** @return Granularity */
    public function granularity(): string
    {
        return $this->granularity;
    }

    /** @return list<string> bucket starts, oldest first */
    public function keys(): array
    {
        return $this->keys;
    }

    /** The bucket start holding an instant, read in the bucket zone. */
    public function keyFor(CarbonInterface $at): string
    {
        $day = CarbonImmutable::parse(CarbonImmutable::instance($at)->setTimezone($this->timezone)->toDateString(), 'UTC');

        return self::bucketStart($this->granularity, $day)->toDateString();
    }

    /** Indonesian axis label: `13 Okt`, `W42 2026`, `Okt 2026`. */
    public function label(string $key): string
    {
        $start = CarbonImmutable::parse($key, 'UTC')->locale('id');

        return match ($this->granularity) {
            self::DAY => $start->translatedFormat('j M'),
            self::WEEK => sprintf('W%02d %d', $start->isoWeek(), $start->isoWeekYear()),
            self::MONTH => $start->translatedFormat('M Y'),
        };
    }

    /** Last calendar day of a bucket, cut at the end of the period. */
    public function endDate(string $key): string
    {
        $start = CarbonImmutable::parse($key, 'UTC');
        $end = match ($this->granularity) {
            self::DAY => $start,
            self::WEEK => $start->addDays(6),
            self::MONTH => $start->endOfMonth(),
        };

        return min($end->toDateString(), $this->lastDay);
    }

    /**
     * The bucket start of a timestamp column stored in the application timezone, as MySQL text. Same text
     * with no binding, so it can sit in a select and a group by. The conversion is skipped when the bucket
     * zone is the storage zone; otherwise MySQL needs its time zone tables (mysql_tzinfo_to_sql).
     */
    public function sqlExpression(string $column): string
    {
        $column = self::identifier($column);
        $storage = (string) config('app.timezone');

        return $this->bucketSql($this->timezone === $storage ? $column : "CONVERT_TZ({$column}, '{$storage}', '{$this->timezone}')");
    }

    /** The bucket start of a DATE column: a calendar day, no zone applies. */
    public function sqlDateExpression(string $column): string
    {
        return $this->bucketSql(self::identifier($column));
    }

    /**
     * The series of these buckets: every bucket with a value per metric, 0 where a metric has no row.
     * Months older than the cap fold into the first bucket; rows after the last bucket are not drawn.
     *
     * @param  array<string, array<string, int|float>>  $metrics  metric => (bucket start => value)
     * @return array{granularity: Granularity, points: list<array<string, int|float|string>>}
     */
    public function series(array $metrics): array
    {
        $sums = [];
        foreach ($metrics as $metric => $values) {
            foreach ($values as $key => $value) {
                $key = $this->granularity === self::MONTH && $key < $this->keys[0] ? $this->keys[0] : $key;
                $sums[$metric][$key] = ($sums[$metric][$key] ?? 0) + $value;
            }
        }

        $points = [];
        foreach ($this->keys as $key) {
            $point = ['bucket' => $key, 'label' => $this->label($key)];
            foreach (array_keys($metrics) as $metric) {
                $point[$metric] = $sums[$metric][$key] ?? 0;
            }
            $points[] = $point;
        }

        return ['granularity' => $this->granularity, 'points' => $points];
    }

    private function bucketSql(string $local): string
    {
        return match ($this->granularity) {
            self::DAY => "DATE_FORMAT({$local}, '%Y-%m-%d')",
            self::WEEK => "DATE_FORMAT(DATE_SUB(DATE({$local}), INTERVAL WEEKDAY({$local}) DAY), '%Y-%m-%d')",
            self::MONTH => "DATE_FORMAT({$local}, '%Y-%m-01')",
        };
    }

    /** A table.column or column name, quoted. Never user input; anything else is refused. */
    private static function identifier(string $column): string
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column)) {
            throw new InvalidArgumentException("Not a column name: [{$column}].");
        }

        return '`'.str_replace('.', '`.`', $column).'`';
    }

    /** @param Granularity $granularity */
    private static function bucketStart(string $granularity, CarbonImmutable $day): CarbonImmutable
    {
        return match ($granularity) {
            self::DAY => $day,
            self::WEEK => $day->startOfWeek(CarbonInterface::MONDAY),
            self::MONTH => $day->startOfMonth(),
        };
    }

    /**
     * @param  Granularity  $granularity
     * @return list<string>
     */
    private static function keysBetween(string $granularity, string $first, string $last): array
    {
        $end = CarbonImmutable::parse($last, 'UTC');
        $cursor = self::bucketStart($granularity, CarbonImmutable::parse($first, 'UTC'));

        if ($granularity === self::MONTH) {
            $cursor = max($cursor, $end->startOfMonth()->subMonthsNoOverflow(self::MAX_MONTHS - 1));
        }

        $keys = [];
        while ($cursor <= $end) {
            $keys[] = $cursor->toDateString();
            $cursor = match ($granularity) {
                self::DAY => $cursor->addDay(),
                self::WEEK => $cursor->addWeek(),
                self::MONTH => $cursor->addMonthNoOverflow(),
            };
        }

        return $keys;
    }
}
