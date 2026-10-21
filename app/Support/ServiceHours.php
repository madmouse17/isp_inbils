<?php

namespace App\Support;

use App\Models\Core\Company;
use Carbon\CarbonImmutable;

/**
 * The company's opening hours (`companies.settings.service_hours`) and the one sum an SLA tier on the business calendar
 * needs from them (K11): the moment a target of so many open minutes runs out.
 *
 * The hours are stored as `{mon..sun: null or ["HH:MM", "HH:MM"]}` in the company's own time zone; `null` is a closed day.
 * A week without one open day counts as "not set".
 */
final class ServiceHours
{
    /** The keys of the stored week, Monday first (ISO weekday 1 to 7). */
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * When `$minutes` of open time have passed since `$from`. The clock starts at `$from` while the company is open and at
     * the next opening while it is closed. A window is open from its first minute up to, not including, its last: a target
     * that runs out exactly at closing time ends at closing time. The answer keeps the time zone of `$from`.
     *
     * A week that was never set cannot be counted, so the answer is then plain elapsed time, as it is for no minutes.
     *
     * @param  array<string, mixed>  $hours  the stored week
     * @param  string  $tz  the company's time zone, in which the hours are read
     */
    public static function add(CarbonImmutable $from, int $minutes, array $hours, string $tz): CarbonImmutable
    {
        $windows = self::windows($hours);
        $weekMinutes = array_sum(array_map(static fn (array $window): int => $window[1] - $window[0], $windows));

        if ($minutes <= 0 || $weekMinutes === 0) {
            return $from->addMinutes($minutes);
        }

        $start = $from->setTimezone($tz);
        $left = $minutes * 60;
        // Every full week of open time the target needs, the week it starts in, and one more for the part of a day it starts in;
        // never more than a year, so a week of minute-wide hours cannot keep a request busy.
        $lastDay = min(366, 7 * (intdiv($minutes, $weekMinutes) + 2));

        for ($offset = 0; $offset <= $lastDay; $offset++) {
            $day = $start->startOfDay()->addDays($offset);
            $window = $windows[$day->dayOfWeekIso] ?? null;

            if ($window === null) {
                continue;
            }

            $opens = $day->setTime(intdiv($window[0], 60), $window[0] % 60);
            $closes = $day->setTime(intdiv($window[1], 60), $window[1] % 60);
            $begin = $start->greaterThan($opens) ? $start : $opens;
            $room = $closes->getTimestamp() - $begin->getTimestamp();

            if ($room <= 0) {
                continue;
            }

            if ($left <= $room) {
                return $begin->addSeconds($left)->setTimezone($from->getTimezone());
            }

            $left -= $room;
        }

        // shortcut: a target that needs more than a year of open hours counts plain elapsed time; raise the cap if a company ever sets hours that thin.
        return $from->addMinutes($minutes);
    }

    /**
     * The week a company has set, every day present, or null while it has not set one.
     *
     * @return array<string, array{0: string, 1: string}|null>|null
     */
    public static function of(?Company $company): ?array
    {
        $stored = $company?->settings['service_hours'] ?? null;

        return self::isSet($stored) ? self::week($stored) : null;
    }

    /** Whether the stored week has at least one open day. */
    public static function isSet(mixed $stored): bool
    {
        return self::windows($stored) !== [];
    }

    /**
     * The stored week with every day present: the valid windows as `["HH:MM", "HH:MM"]`, anything else as `null`.
     *
     * @return array<string, array{0: string, 1: string}|null>
     */
    public static function week(mixed $stored): array
    {
        $windows = self::windows($stored);
        $week = [];

        foreach (self::DAYS as $index => $day) {
            $window = $windows[$index + 1] ?? null;
            $week[$day] = $window === null ? null : [self::clock($window[0]), self::clock($window[1])];
        }

        return $week;
    }

    /**
     * The minutes of the day each open day starts and ends at, by ISO weekday. A day that is closed, malformed, or ends at or
     * before it starts is left out.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private static function windows(mixed $stored): array
    {
        $windows = [];

        foreach (self::DAYS as $index => $day) {
            $hours = is_array($stored) ? ($stored[$day] ?? null) : null;

            if (! is_array($hours) || ! array_is_list($hours) || count($hours) !== 2) {
                continue;
            }

            $opens = self::minuteOfDay($hours[0]);
            $closes = self::minuteOfDay($hours[1]);

            if ($opens !== null && $closes !== null && $opens < $closes) {
                $windows[$index + 1] = [$opens, $closes];
            }
        }

        return $windows;
    }

    private static function minuteOfDay(mixed $time): ?int
    {
        return is_string($time) && preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $parts) === 1
            ? (int) $parts[1] * 60 + (int) $parts[2]
            : null;
    }

    private static function clock(int $minuteOfDay): string
    {
        return sprintf('%02d:%02d', intdiv($minuteOfDay, 60), $minuteOfDay % 60);
    }
}
