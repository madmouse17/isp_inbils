<?php

namespace App\Support;

/**
 * One-line postal address shared by the invoice snapshot backfill and the invoice party reader, so a printed
 * address never differs between the two. Region names come from the caller (a loaded relation or a join).
 */
final class AddressLine
{
    private const PARTS = ['address', 'village_name', 'district_name', 'city_name', 'province_name', 'postal_code'];

    /** @param  object|array<string, mixed>|null  $address */
    public static function of(object|array|null $address): ?string
    {
        if ($address === null) {
            return null;
        }

        $get = fn (string $key): mixed => is_array($address) ? ($address[$key] ?? null) : ($address->{$key} ?? null);

        $parts = [];

        foreach (self::PARTS as $key) {
            $value = self::text($get($key));

            if ($key === 'city_name' && $value === '') {
                $value = self::text($get('city'));
            }

            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
