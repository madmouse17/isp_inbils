<?php

namespace Tests\Unit;

use App\Support\ServiceHours;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * K11: how long a target of so many service hours takes. Every moment is written in Jakarta time (UTC+7, no daylight
 * saving), the way the company wrote its hours; 2026-03-02 is a Monday.
 */
class ServiceHoursTest extends TestCase
{
    private const JAKARTA = 'Asia/Jakarta';

    /** Monday to Friday 08:00 to 17:00, Saturday 08:00 to 12:00, Sunday closed. */
    private const OFFICE = [
        'mon' => ['08:00', '17:00'],
        'tue' => ['08:00', '17:00'],
        'wed' => ['08:00', '17:00'],
        'thu' => ['08:00', '17:00'],
        'fri' => ['08:00', '17:00'],
        'sat' => ['08:00', '12:00'],
        'sun' => null,
    ];

    /** @return array<string, array{string, int, string}> from, minutes, expected */
    public static function counts(): array
    {
        return [
            'inside the hours and inside the day' => ['2026-03-02 09:00', 180, '2026-03-02 12:00'],
            'to the minute of closing time, which is where it ends' => ['2026-03-02 09:00', 480, '2026-03-02 17:00'],
            'a minute past closing time rolls to the next morning' => ['2026-03-02 09:00', 481, '2026-03-03 08:01'],
            'spilling over the night' => ['2026-03-02 15:00', 240, '2026-03-03 10:00'],
            'before the opening the clock starts at the opening' => ['2026-03-02 06:00', 120, '2026-03-02 10:00'],
            'exactly at the opening' => ['2026-03-02 08:00', 60, '2026-03-02 09:00'],
            'after closing it starts the next morning' => ['2026-03-02 18:30', 60, '2026-03-03 09:00'],
            'exactly at closing time the company is shut: the end is not open' => ['2026-03-02 17:00', 60, '2026-03-03 09:00'],
            'a minute before closing counts that minute' => ['2026-03-02 16:59', 61, '2026-03-03 09:00'],
            'into the short Saturday' => ['2026-03-06 16:00', 180, '2026-03-07 10:00'],
            'over the closed Sunday' => ['2026-03-07 11:00', 180, '2026-03-09 10:00'],
            'starting on the closed Sunday' => ['2026-03-08 10:00', 60, '2026-03-09 09:00'],
            'a whole week of open time ends at its last closing' => ['2026-03-02 08:00', 49 * 60, '2026-03-07 12:00'],
            'seconds of the start are kept while the company is open' => ['2026-03-02 09:00:30', 60, '2026-03-02 10:00:30'],
            // 49 open hours a week: 120 hours is two weeks and 22 hours, so it ends the third Wednesday, not after 14 days.
            'longer than two weeks of calendar time' => ['2026-03-02 08:00', 120 * 60, '2026-03-18 12:00'],
        ];
    }

    #[DataProvider('counts')]
    public function test_only_open_minutes_are_counted(string $from, int $minutes, string $expected): void
    {
        $moment = ServiceHours::add($this->at($from), $minutes, self::OFFICE, self::JAKARTA);

        $this->assertSame($expected, $this->jakarta($moment));
    }

    public function test_the_answer_keeps_the_time_zone_of_the_start(): void
    {
        $utc = ServiceHours::add(CarbonImmutable::parse('2026-03-02 11:30:00', 'UTC'), 60, self::OFFICE, self::JAKARTA);

        $this->assertSame('UTC', $utc->getTimezone()->getName());
        $this->assertSame('2026-03-03 02:00:00', $utc->format('Y-m-d H:i:s'));
    }

    public function test_the_hours_are_read_in_the_zone_given_not_the_zone_of_the_start(): void
    {
        $from = CarbonImmutable::parse('2026-03-02 09:30:00', 'UTC');

        // 16:30 in Jakarta is inside the hours; 17:30 in Makassar is not.
        $this->assertSame('2026-03-03 01:30:00', ServiceHours::add($from, 60, self::OFFICE, 'Asia/Jakarta')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-03 01:00:00', ServiceHours::add($from, 60, self::OFFICE, 'Asia/Makassar')->format('Y-m-d H:i:s'));
    }

    public function test_a_day_that_loses_an_hour_to_daylight_saving_counts_the_hours_it_really_has(): void
    {
        // New York changes to summer time on Sunday 2026-03-08 at 02:00: a Sunday window of 00:00 to 06:00 holds 5 real hours.
        $sundayOnly = ['mon' => null, 'tue' => null, 'wed' => null, 'thu' => null, 'fri' => null, 'sat' => null, 'sun' => ['00:00', '06:00']];
        $from = CarbonImmutable::parse('2026-03-08 00:00', 'America/New_York');

        $moment = ServiceHours::add($from, 6 * 60, $sundayOnly, 'America/New_York');

        $this->assertSame('2026-03-15 01:00', $moment->setTimezone('America/New_York')->format('Y-m-d H:i'));
    }

    public function test_a_week_that_was_never_set_counts_plain_elapsed_time(): void
    {
        $from = $this->at('2026-03-02 18:30');
        $closedAllWeek = array_fill_keys(ServiceHours::DAYS, null);

        $this->assertSame('2026-03-02 21:30', $this->jakarta(ServiceHours::add($from, 180, [], self::JAKARTA)));
        $this->assertSame('2026-03-02 21:30', $this->jakarta(ServiceHours::add($from, 180, $closedAllWeek, self::JAKARTA)));
    }

    public function test_no_minutes_is_no_time_at_all(): void
    {
        $from = $this->at('2026-03-02 18:30');

        $this->assertSame('2026-03-02 18:30', $this->jakarta(ServiceHours::add($from, 0, self::OFFICE, self::JAKARTA)), 'a closed company does not move a zero target to the opening');
    }

    public function test_malformed_days_are_closed_days(): void
    {
        $from = $this->at('2026-03-02 09:00');
        $hours = [
            'mon' => ['17:00', '08:00'],
            'tue' => ['8:00', '17:00'],
            'wed' => ['08:00'],
            'thu' => 'sepanjang hari',
            'fri' => ['08:00', '17:00', '18:00'],
            'sat' => ['08:00', '08:00'],
            'sun' => ['25:00', '26:00'],
        ];

        $this->assertFalse(ServiceHours::isSet($hours));
        $this->assertSame('2026-03-02 11:00', $this->jakarta(ServiceHours::add($from, 120, $hours, self::JAKARTA)), 'nothing valid is plain elapsed time');

        $hours['thu'] = ['09:00', '10:00'];
        $this->assertTrue(ServiceHours::isSet($hours));
        $this->assertSame('2026-03-05 10:00', $this->jakarta(ServiceHours::add($from, 60, $hours, self::JAKARTA)), 'the one valid day is the only open day');
    }

    public function test_a_company_open_only_a_minute_a_week_still_gets_an_answer_at_once(): void
    {
        $oneMinute = array_fill_keys(ServiceHours::DAYS, null);
        $oneMinute['mon'] = ['08:00', '08:01'];

        // 720 hours would need 43,200 weeks: beyond a year of open time the target counts plain elapsed time.
        $this->assertSame('2026-04-01 08:00', $this->jakarta(ServiceHours::add($this->at('2026-03-02 08:00'), 720 * 60, $oneMinute, self::JAKARTA)));
        $this->assertSame('2026-03-09 08:01', $this->jakarta(ServiceHours::add($this->at('2026-03-02 08:00'), 2, $oneMinute, self::JAKARTA)));
    }

    public function test_the_week_lists_all_seven_days_and_keeps_only_valid_windows(): void
    {
        $this->assertSame(
            ['mon' => null, 'tue' => null, 'wed' => null, 'thu' => null, 'fri' => null, 'sat' => null, 'sun' => null],
            ServiceHours::week(null),
        );
        $this->assertSame(
            ['mon' => ['08:00', '17:00'], 'tue' => null, 'wed' => null, 'thu' => null, 'fri' => null, 'sat' => ['08:00', '12:00'], 'sun' => null],
            ServiceHours::week(['mon' => ['08:00', '17:00'], 'tue' => ['17:00', '08:00'], 'sat' => ['08:00', '12:00'], 'xyz' => ['01:00', '02:00']]),
        );
        $this->assertTrue(ServiceHours::isSet(['sun' => ['10:00', '11:00']]));
        $this->assertFalse(ServiceHours::isSet(null));
        $this->assertFalse(ServiceHours::isSet('08:00-17:00'));
    }

    /** A moment as the company writes it. */
    private function at(string $jakartaTime): CarbonImmutable
    {
        return CarbonImmutable::parse($jakartaTime, self::JAKARTA)->utc();
    }

    private function jakarta(CarbonImmutable $moment): string
    {
        return $moment->setTimezone(self::JAKARTA)->format(substr_count($moment->format('s'), '0') === 2 ? 'Y-m-d H:i' : 'Y-m-d H:i:s');
    }
}
