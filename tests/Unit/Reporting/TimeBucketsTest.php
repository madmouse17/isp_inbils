<?php

namespace Tests\Unit\Reporting;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Reporting\Support\TimeBuckets;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The buckets of a report series: granularity by the length of the period, bucket starts, labels, the
 * company-zone boundaries (Jakarta is UTC+7 all year, no daylight saving) and the zero-filled series.
 */
class TimeBucketsTest extends TestCase
{
    private const JAKARTA = 'Asia/Jakarta';

    /** @return array<string, array{0: string, 1: string, 2: string, 3: int}> From, To, granularity, bucket count */
    public static function periods(): array
    {
        return [
            'one day' => ['2026-10-13', '2026-10-13', 'day', 1],
            'a week' => ['2026-10-05', '2026-10-11', 'day', 7],
            'a 31-day month is still per day' => ['2026-10-01', '2026-10-31', 'day', 31],
            '32 days is per ISO week' => ['2026-10-01', '2026-11-01', 'week', 5],
            'July to December, 184 days, is per week' => ['2026-07-01', '2026-12-31', 'week', 27],
            '185 days is per month' => ['2026-07-01', '2027-01-01', 'month', 7],
            'a year is per month' => ['2026-01-01', '2026-12-31', 'month', 12],
        ];
    }

    #[DataProvider('periods')]
    public function test_granularity_follows_the_length_of_the_period_with_both_ends_included(string $from, string $to, string $granularity, int $count): void
    {
        $buckets = TimeBuckets::forRange(CarbonImmutable::parse($from), CarbonImmutable::parse($to), self::JAKARTA);

        $this->assertSame($granularity, $buckets->granularity());
        $this->assertCount($count, $buckets->keys());
    }

    public function test_day_buckets_cover_every_day_and_are_labelled_in_indonesian(): void
    {
        $buckets = TimeBuckets::forRange(CarbonImmutable::parse('2026-10-11'), CarbonImmutable::parse('2026-10-14'), self::JAKARTA);

        $this->assertSame(['2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14'], $buckets->keys());
        $this->assertSame('13 Okt', $buckets->label('2026-10-13'));
        $this->assertSame('1 Jan', TimeBuckets::forRange(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-01'), self::JAKARTA)->label('2026-01-01'));
    }

    public function test_week_buckets_start_on_the_monday_before_the_first_day(): void
    {
        // 2026-10-14 is a Wednesday, 2026-11-18 a Wednesday: 36 days, six ISO weeks.
        $buckets = TimeBuckets::forRange(CarbonImmutable::parse('2026-10-14'), CarbonImmutable::parse('2026-11-18'), self::JAKARTA);

        $this->assertSame('week', $buckets->granularity());
        $this->assertSame(['2026-10-12', '2026-10-19', '2026-10-26', '2026-11-02', '2026-11-09', '2026-11-16'], $buckets->keys());
        $this->assertSame('W42 2026', $buckets->label('2026-10-12'));
    }

    public function test_week_labels_use_the_iso_week_year_and_two_digits(): void
    {
        $buckets = TimeBuckets::forRange(CarbonImmutable::parse('2026-12-21'), CarbonImmutable::parse('2027-02-07'), self::JAKARTA);

        $this->assertSame('W52 2026', $buckets->label('2026-12-21'));
        $this->assertSame('W53 2026', $buckets->label('2026-12-28'));
        $this->assertSame('W01 2027', $buckets->label('2027-01-04'));
    }

    public function test_month_buckets_start_on_the_first_and_are_labelled_in_indonesian(): void
    {
        $buckets = TimeBuckets::forRange(CarbonImmutable::parse('2026-01-15'), CarbonImmutable::parse('2026-08-20'), self::JAKARTA);

        $this->assertSame('month', $buckets->granularity());
        $this->assertSame(['2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01'], $buckets->keys());
        $this->assertSame('Okt 2026', $buckets->label('2026-10-01'));
        $this->assertSame('Mei 2026', $buckets->label('2026-05-01'));
    }

    public function test_month_buckets_are_capped_at_36_and_older_months_fold_into_the_first(): void
    {
        $buckets = TimeBuckets::forRange(CarbonImmutable::parse('2020-01-01'), CarbonImmutable::parse('2026-10-13'), self::JAKARTA);

        $this->assertCount(36, $buckets->keys());
        $this->assertSame('2023-11-01', $buckets->keys()[0]);
        $this->assertSame('2026-10-01', $buckets->keys()[35]);

        $series = $buckets->series(['spk' => ['2020-03-01' => 2, '2023-10-01' => 3, '2023-11-01' => 4, '2026-10-01' => 1]]);

        $this->assertSame(9, $series['points'][0]['spk'], 'two old months and the first month itself');
        $this->assertSame(1, $series['points'][35]['spk']);
        $this->assertSame(10, array_sum(array_column($series['points'], 'spk')), 'nothing is lost by folding');
    }

    public function test_without_a_start_the_series_runs_per_month_from_the_oldest_row(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'UTC'));

        $buckets = TimeBuckets::forRange(null, null, self::JAKARTA, CarbonImmutable::parse('2026-07-20 09:00:00', 'UTC'));

        $this->assertSame('month', $buckets->granularity());
        $this->assertSame(['2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01'], $buckets->keys());
    }

    public function test_the_oldest_row_is_read_in_the_bucket_zone(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'UTC'));

        // 2026-06-30 18:00 UTC is already 1 July in Jakarta.
        $buckets = TimeBuckets::forRange(null, null, self::JAKARTA, CarbonImmutable::parse('2026-06-30 18:00:00', 'UTC'));

        $this->assertSame('2026-07-01', $buckets->keys()[0]);
    }

    public function test_all_time_without_any_row_is_the_current_month_only(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'UTC'));

        $this->assertSame(['2026-10-01'], TimeBuckets::forRange(null, null, self::JAKARTA)->keys());
    }

    public function test_a_start_without_an_end_runs_to_today_in_the_bucket_zone(): void
    {
        // 20:00 UTC on 13 October is already 03:00 on 14 October in Jakarta.
        $this->travelTo(CarbonImmutable::parse('2026-10-13 20:00:00', 'UTC'));

        $buckets = TimeBuckets::forRange(CarbonImmutable::parse('2026-10-12'), null, self::JAKARTA);

        $this->assertSame(['2026-10-12', '2026-10-13', '2026-10-14'], $buckets->keys());
    }

    public function test_an_end_before_the_start_collapses_onto_the_start(): void
    {
        $buckets = TimeBuckets::forRange(CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2026-10-13'), self::JAKARTA);

        $this->assertSame(['2026-10-20'], $buckets->keys());
    }

    public function test_a_period_is_read_as_calendar_days_whatever_time_the_dates_carry(): void
    {
        $buckets = TimeBuckets::forPeriod('2026-10-13', '2026-10-14 23:59:59', self::JAKARTA);

        $this->assertSame(['2026-10-13', '2026-10-14'], $buckets->keys());
    }

    public function test_key_for_reads_an_instant_in_the_bucket_zone(): void
    {
        $days = TimeBuckets::forRange(CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-18'), self::JAKARTA);
        $weeks = TimeBuckets::forRange(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-18'), self::JAKARTA);
        $months = TimeBuckets::forRange(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'), self::JAKARTA);

        // 16:59:59 UTC is the last second of 12 October in Jakarta, 17:00:00 UTC the first of the 13th.
        $this->assertSame('2026-10-12', $days->keyFor(CarbonImmutable::parse('2026-10-12 16:59:59', 'UTC')));
        $this->assertSame('2026-10-13', $days->keyFor(CarbonImmutable::parse('2026-10-12 17:00:00', 'UTC')));
        // Sunday 18 October 17:00 UTC is Monday 19 October in Jakarta: the next ISO week.
        $this->assertSame('2026-10-12', $weeks->keyFor(CarbonImmutable::parse('2026-10-18 16:59:59', 'UTC')));
        $this->assertSame('2026-10-19', $weeks->keyFor(CarbonImmutable::parse('2026-10-18 17:00:00', 'UTC')));
        // 31 October 17:00 UTC is 1 November in Jakarta.
        $this->assertSame('2026-10-01', $months->keyFor(CarbonImmutable::parse('2026-10-31 16:59:59', 'UTC')));
        $this->assertSame('2026-11-01', $months->keyFor(CarbonImmutable::parse('2026-10-31 17:00:00', 'UTC')));
    }

    public function test_end_date_is_the_last_day_of_the_bucket_cut_at_the_end_of_the_period(): void
    {
        $weeks = TimeBuckets::forRange(CarbonImmutable::parse('2026-10-14'), CarbonImmutable::parse('2026-11-18'), self::JAKARTA);
        $months = TimeBuckets::forRange(CarbonImmutable::parse('2026-01-15'), CarbonImmutable::parse('2026-08-20'), self::JAKARTA);
        $days = TimeBuckets::forRange(CarbonImmutable::parse('2026-10-11'), CarbonImmutable::parse('2026-10-14'), self::JAKARTA);

        $this->assertSame('2026-10-18', $weeks->endDate('2026-10-12'));
        $this->assertSame('2026-11-18', $weeks->endDate('2026-11-16'), 'the last week stops at the period end');
        $this->assertSame('2026-02-28', $months->endDate('2026-02-01'));
        $this->assertSame('2026-08-20', $months->endDate('2026-08-01'));
        $this->assertSame('2026-10-12', $days->endDate('2026-10-12'));
    }

    public function test_series_has_every_bucket_with_zero_for_the_empty_ones(): void
    {
        $buckets = TimeBuckets::forRange(CarbonImmutable::parse('2026-10-11'), CarbonImmutable::parse('2026-10-14'), self::JAKARTA);

        $series = $buckets->series([
            'revenue' => ['2026-10-12' => 1500.5, '2026-10-14' => 20.0],
            'orders' => ['2026-10-12' => 3],
        ]);

        $this->assertSame('day', $series['granularity']);
        $this->assertSame([
            ['bucket' => '2026-10-11', 'label' => '11 Okt', 'revenue' => 0, 'orders' => 0],
            ['bucket' => '2026-10-12', 'label' => '12 Okt', 'revenue' => 1500.5, 'orders' => 3],
            ['bucket' => '2026-10-13', 'label' => '13 Okt', 'revenue' => 0, 'orders' => 0],
            ['bucket' => '2026-10-14', 'label' => '14 Okt', 'revenue' => 20.0, 'orders' => 0],
        ], $series['points']);
    }

    public function test_series_leaves_out_rows_outside_the_buckets(): void
    {
        $buckets = TimeBuckets::forRange(CarbonImmutable::parse('2026-10-11'), CarbonImmutable::parse('2026-10-12'), self::JAKARTA);

        $series = $buckets->series(['orders' => ['2026-10-10' => 5, '2026-10-12' => 2, '2026-10-13' => 7]]);

        $this->assertSame([0, 2], array_column($series['points'], 'orders'));
    }

    public function test_sql_expressions_group_by_the_bucket_start_without_bindings(): void
    {
        $days = TimeBuckets::forRange(CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-11'), self::JAKARTA);
        $weeks = TimeBuckets::forRange(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-18'), self::JAKARTA);
        $months = TimeBuckets::forRange(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'), self::JAKARTA);
        $convert = "CONVERT_TZ(`tickets`.`resolved_at`, 'UTC', 'Asia/Jakarta')";

        $this->assertSame("DATE_FORMAT({$convert}, '%Y-%m-%d')", $days->sqlExpression('tickets.resolved_at'));
        $this->assertSame("DATE_FORMAT(DATE_SUB(DATE({$convert}), INTERVAL WEEKDAY({$convert}) DAY), '%Y-%m-%d')", $weeks->sqlExpression('tickets.resolved_at'));
        $this->assertSame("DATE_FORMAT({$convert}, '%Y-%m-01')", $months->sqlExpression('tickets.resolved_at'));
        $this->assertStringNotContainsString('?', $weeks->sqlExpression('tickets.resolved_at'));
    }

    public function test_a_date_column_is_not_converted_and_a_storage_zone_period_needs_no_conversion(): void
    {
        $months = TimeBuckets::forRange(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'), self::JAKARTA);
        $utc = TimeBuckets::forRange(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'), 'UTC');

        $this->assertSame("DATE_FORMAT(`issue_date`, '%Y-%m-01')", $months->sqlDateExpression('issue_date'));
        $this->assertSame("DATE_FORMAT(`customers`.`created_at`, '%Y-%m-01')", $utc->sqlExpression('customers.created_at'));
    }

    public function test_a_column_that_is_not_a_plain_name_is_refused(): void
    {
        $buckets = TimeBuckets::forRange(CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-11'), self::JAKARTA);

        $this->expectException(InvalidArgumentException::class);

        $buckets->sqlExpression('resolved_at); DROP TABLE tickets; --');
    }

    public function test_an_unknown_timezone_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TimeBuckets::forRange(CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-11'), "Asia/Jakarta'); DROP TABLE tickets; --");
    }
}
