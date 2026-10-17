<?php

namespace App\Support;

/**
 * The properties of an audit entry as a browser may read them. A changed foreign key is logged as the integer
 * id of the record (`customer_id: 5`), and an integer id never leaves the server (spec .scratch/opaque-ids), so
 * every property that names a record by an integer is left out. Ids that were written as public ids are strings
 * and stay; so does every other integer (an amount, a quantity).
 */
final class AuditProperties
{
    private const RECORD_KEY = '/^(id|assigned_to)$|(_id|_ids|_by)$/';

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
