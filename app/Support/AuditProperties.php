<?php

namespace App\Support;

use Illuminate\Support\Arr;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Spatie\Activitylog\Models\Activity;

/**
 * The properties of an audit entry as a browser may read them. A changed foreign key is logged as the integer
 * id of the record (`customer_id: 5`), and an integer id never leaves the server (spec .scratch/opaque-ids), so
 * every property that names a record by an integer is left out. Ids that were written as public ids are strings
 * and stay; so does every other integer (an amount, a quantity). maskValues is the write side: a model hides the
 * values of its personal columns before an entry is stored.
 */
final class AuditProperties
{
    /** What stands in for a hidden value: the entry still says which field changed. */
    public const MASKED = '[disembunyikan]';

    private const RECORD_KEY = '/^(id|assigned_to)$|(_id|_ids|_by)$/';

    /** The audit events that keep where a technician stood. */
    private const POSITION_EVENTS = ['check_in', 'en_route'];

    /**
     * Hides what $keys held, in the new (`attributes`) and the old (`old`) values of the entry about to be stored: it
     * says which field changed and never what it held. A dotted key reaches into an array column
     * (`settings.bank_account_info`). An empty value stays as it is, so a first value reads as added, not as hidden.
     *
     * @param  list<string>  $keys
     */
    public static function maskValues(ActivityContract $activity, array $keys): void
    {
        /** @var Activity $activity */
        $properties = $activity->properties?->toArray() ?? [];

        foreach (['attributes', 'old'] as $section) {
            foreach ($keys as $key) {
                if (filled(Arr::get($properties[$section] ?? [], $key))) {
                    Arr::set($properties[$section], $key, self::MASKED);
                }
            }
        }

        $activity->properties = collect($properties);
    }

    /**
     * The properties of one entry as a screen or a file may read them: forDisplay(), and for the entries that keep where a
     * technician stood (a check-in, the announcement of a trip) the two coordinates are hidden like a personal column. How far
     * they were (`distance_m`), whether that was too far and why (`far`, `far_reason`) stay. The stored entry keeps the fix.
     *
     * @return array<array-key, mixed>
     */
    public static function forActivity(Activity $activity): array
    {
        $properties = $activity->properties?->toArray() ?? [];

        if (in_array($activity->description, self::POSITION_EVENTS, true)) {
            foreach (['lat', 'lng'] as $key) {
                if (filled($properties[$key] ?? null)) {
                    $properties[$key] = self::MASKED;
                }
            }
        }

        return self::forDisplay($properties);
    }

    /**
     * @param  array<array-key, mixed>  $properties
     * @return array<array-key, mixed>
     */
    public static function forDisplay(array $properties): array
    {
        $shown = [];

        foreach ($properties as $key => $value) {
            if (is_string($key) && preg_match(self::RECORD_KEY, $key) === 1 && self::isInteger($value)) {
                continue;
            }

            $shown[$key] = is_array($value) ? self::forDisplay($value) : $value;
        }

        return $shown;
    }

    private static function isInteger(mixed $value): bool
    {
        return is_int($value) || (is_array($value) && $value !== [] && array_filter($value, fn (mixed $item): bool => ! is_int($item)) === []);
    }
}
