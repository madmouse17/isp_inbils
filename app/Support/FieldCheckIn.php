<?php

namespace App\Support;

use App\Services\Core\CompanyService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

final class FieldCheckIn
{
    /** How far from the site a start may be checked in without a reason, until the company sets its own (K10). */
    public const DEFAULT_MAX_DISTANCE_M = 500;

    private const REASONS = ['permission_denied', 'permission_denied_forever', 'location_disabled', 'no_fix', 'timeout'];

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'check_in' => ['nullable', 'array'],
            'check_in.lat' => ['exclude_without:check_in', 'required_without:check_in.unavailable_reason', 'numeric', 'between:-90,90'],
            'check_in.lng' => ['exclude_without:check_in', 'required_without:check_in.unavailable_reason', 'numeric', 'between:-180,180'],
            'check_in.accuracy_m' => ['exclude_without:check_in', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'check_in.captured_at' => ['exclude_without:check_in', 'nullable', 'date', 'before_or_equal:+5 minutes'],
            /** Why the technician starts farther from the customer's address than the company allows. The start is refused (422 on this field) without it; the trip announcement ignores it. */
            'check_in.far_reason' => ['exclude_without:check_in', 'nullable', 'string', 'max:300'],
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

    /**
     * The check-in of a start (K10). It is the check-in of `properties()`, and when the technician stands farther from the site
     * than the company allows it is flagged `far` and keeps the reason they gave. Without a reason the start is refused whole,
     * on the field the app asks the reason in. A fix that could not be taken, or a site without coordinates, is never far.
     *
     * @param  array<string, mixed>  $checkIn
     * @param  array{lat: mixed, lng: mixed}|null  $site
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function forStart(array $checkIn, ?array $site): array
    {
        $properties = self::properties($checkIn, $site);
        $distance = $properties['distance_m'] ?? null;

        if ($distance === null) {
            return $properties;
        }

        $far = $distance > self::maxDistanceMetres();
        $reason = $far ? trim((string) Arr::get($checkIn, 'far_reason', '')) : '';

        if ($far && $reason === '') {
            throw ValidationException::withMessages([
                'check_in.far_reason' => 'Anda berjarak '.self::distanceText($distance).' dari lokasi pelanggan. Tulis alasannya untuk melanjutkan.',
            ]);
        }

        return [...$properties, 'far' => $far, 'far_reason' => $far ? $reason : null];
    }

    /** The company's limit for a start, in metres; the default while it never set one. */
    public static function maxDistanceMetres(): int
    {
        $limit = CompanyService::setting('checkin_max_distance_m', self::DEFAULT_MAX_DISTANCE_M);

        return is_numeric($limit) && (int) $limit > 0 ? (int) $limit : self::DEFAULT_MAX_DISTANCE_M;
    }

    /** `820 m` below a kilometre, `1,2 km` from it: the way the pages write a distance. */
    public static function distanceText(int $metres): string
    {
        return $metres < 1000
            ? number_format($metres, 0, ',', '.').' m'
            : number_format($metres / 1000, 1, ',', '.').' km';
    }

    /**
     * The coordinates of the first place that has both, whether the place is a model or an array such as a resolved site.
     *
     * @param  array<string, mixed>|object|null  ...$places
     * @return array{lat: mixed, lng: mixed}|null
     */
    public static function site(array|object|null ...$places): ?array
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

    /**
     * Machine-readable summary for the technician API. `far` says the start was farther from the site than the company allows,
     * and `far_reason` is what the technician wrote then.
     *
     * @return array{status: string, distance_m: int|null, accuracy_m: float|null, captured_at: string|null, reason: string|null, far: bool, far_reason: string|null}
     */
    public static function apiSummary(Model $subject, string $logName): array
    {
        $record = self::recordFor($subject, $logName);

        return [
            'status' => $record['status'] ?? 'none',
            'distance_m' => $record['distance_m'] ?? null,
            'accuracy_m' => $record['accuracy_m'] ?? null,
            'captured_at' => $record['captured_at'] ?? null,
            'reason' => $record['reason'] ?? null,
            'far' => (bool) ($record['far'] ?? false),
            'far_reason' => $record['far_reason'] ?? null,
        ];
    }

    /**
     * What a page words of a stored check-in: the distance and how well the fix was taken, why there is none, and whether the
     * start was far from the site and why. The technician's own coordinates stay on the server. A key a record never held
     * (every check-in made before the distance limit) is absent.
     *
     * @param  array<string, mixed>|null  $record  from `recordFor()`
     * @return array<string, mixed>|null
     */
    public static function pageSummary(?array $record): ?array
    {
        return $record === null ? null : Arr::only($record, ['status', 'distance_m', 'accuracy_m', 'reason', 'far', 'far_reason']);
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
}
