<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Models\Activity;

final class FieldCheckIn
{
    private const REASONS = ['permission_denied', 'permission_denied_forever', 'location_disabled', 'no_fix', 'timeout'];

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'check_in' => ['nullable', 'array'],
            'check_in.lat' => ['exclude_without:check_in', 'required_without:check_in.unavailable_reason', 'numeric', 'between:-90,90'],
            'check_in.lng' => ['exclude_without:check_in', 'required_without:check_in.unavailable_reason', 'numeric', 'between:-180,180'],
            'check_in.accuracy_m' => ['exclude_without:check_in', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'check_in.captured_at' => ['exclude_without:check_in', 'nullable', 'date'],
            'check_in.unavailable_reason' => [
                'exclude_without:check_in',
                'required_without_all:check_in.lat,check_in.lng',
                'string',
                Rule::in(self::REASONS),
                'prohibits:check_in.lat,check_in.lng,check_in.accuracy_m,check_in.captured_at',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $checkIn
     * @param  array{lat: mixed, lng: mixed}|null  $site
     * @return array<string, mixed>
     */
    public static function properties(array $checkIn, ?array $site): array
    {
        $reason = Arr::get($checkIn, 'unavailable_reason');
        if ($reason !== null && $reason !== '') {
            return ['status' => 'unavailable', 'reason' => (string) $reason];
        }

        $lat = (float) $checkIn['lat'];
        $lng = (float) $checkIn['lng'];

        return [
            'status' => 'recorded',
            'lat' => $lat,
            'lng' => $lng,
            'accuracy_m' => array_key_exists('accuracy_m', $checkIn) && $checkIn['accuracy_m'] !== null ? (float) $checkIn['accuracy_m'] : null,
            'captured_at' => empty($checkIn['captured_at']) ? null : CarbonImmutable::parse((string) $checkIn['captured_at'])->utc()->toIso8601String(),
            'distance_m' => $site === null ? null : self::distanceMetres($lat, $lng, (float) $site['lat'], (float) $site['lng']),
        ];
    }

    /** @return array{lat: mixed, lng: mixed}|null */
    public static function site(?object ...$places): ?array
    {
        foreach ($places as $place) {
            if ($place === null) {
                continue;
            }

            $lat = data_get($place, 'lat');
            $lng = data_get($place, 'lng');
            if ($lat !== null && $lat !== '' && $lng !== null && $lng !== '') {
                return ['lat' => $lat, 'lng' => $lng];
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public static function recordFor(Model $subject, string $logName): ?array
    {
        $activity = Activity::query()
            ->where('log_name', $logName)
            ->where('description', 'check_in')
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->latest('id')
            ->first();

        $properties = $activity?->properties?->toArray();

        return $properties === null ? null : Arr::except($properties, ['company_id']);
    }

    /** @param  array<string, mixed>  $properties */
    public static function summary(array $properties): string
    {
        if (($properties['status'] ?? null) === 'recorded') {
            $accuracy = isset($properties['accuracy_m']) ? ' (accuracy ±'.rtrim(rtrim(number_format((float) $properties['accuracy_m'], 1), '0'), '.').' m)' : '';

            if (($properties['distance_m'] ?? null) !== null) {
                return 'Check-in '.number_format((int) $properties['distance_m']).' m from site'.$accuracy;
            }

            return 'Check-in recorded, no site location to compare'.$accuracy;
        }

        return 'Check-in unavailable ('.self::reasonLabel((string) ($properties['reason'] ?? 'no_reason')).')';
    }

    private static function distanceMetres(float $fromLat, float $fromLng, float $toLat, float $toLng): int
    {
        $earthMetres = 6371000;
        $lat1 = deg2rad($fromLat);
        $lat2 = deg2rad($toLat);
        $deltaLat = deg2rad($toLat - $fromLat);
        $deltaLng = deg2rad($toLng - $fromLng);

        $a = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLng / 2) ** 2;

        return (int) round($earthMetres * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    private static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'permission_denied' => 'permission denied',
            'permission_denied_forever' => 'permission denied permanently',
            'location_disabled' => 'location disabled',
            'no_fix' => 'no fix',
            'timeout' => 'timeout',
            default => 'no reason given',
        };
    }
}
