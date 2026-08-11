<?php

namespace App\Services\Core;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravolt\Indonesia\Models\City;
use Laravolt\Indonesia\Models\District;
use Laravolt\Indonesia\Models\Province;
use Laravolt\Indonesia\Models\Village;

class IndonesiaRegionService
{
    /** @return array{provinces: array<int, array{code: string, name: string}>, cities: array<int, array{code: string, name: string, province_code: string}>, districts: array<int, array{code: string, name: string, city_code: string}>, villages: array<int, array{code: string, name: string, district_code: string}>} */
    public static function options(mixed $provinceCodes = [], mixed $cityCodes = [], mixed $districtCodes = []): array
    {
        $provinceCodes = self::codes($provinceCodes, '/^\d{2}$/');
        $cityCodes = self::codes($cityCodes, '/^\d{4}$/');
        $districtCodes = self::codes($districtCodes, '/^\d{6,7}$/');

        return [
            'provinces' => Province::query()->orderBy('name')->get(['code', 'name'])->toArray(),
            'cities' => $provinceCodes === [] ? [] : City::query()->whereIn('province_code', $provinceCodes)->orderBy('name')->get(['code', 'name', 'province_code'])->toArray(),
            'districts' => $cityCodes === [] ? [] : District::query()->whereIn('city_code', $cityCodes)->orderBy('name')->get(['code', 'name', 'city_code'])->toArray(),
            'villages' => $districtCodes === [] ? [] : Village::query()->whereIn('district_code', $districtCodes)->orderBy('name')->get(['code', 'name', 'district_code'])->toArray(),
        ];
    }

    public static function hierarchyExists(string $provinceCode, string $cityCode, string $districtCode, string $villageCode): bool
    {
        return DB::table((new Village())->getTable().' as village')
            ->join((new District())->getTable().' as district', 'district.code', '=', 'village.district_code')
            ->join((new City())->getTable().' as city', 'city.code', '=', 'district.city_code')
            ->where('village.code', $villageCode)
            ->where('district.code', $districtCode)
            ->where('city.code', $cityCode)
            ->where('city.province_code', $provinceCode)
            ->exists();
    }

    /** @param array<string, mixed> $address @return array<string, mixed> */
    public static function normalizeAddress(array $address): array
    {
        $address['city'] = City::query()->where('code', $address['city_code'])->value('name');

        return $address;
    }

    /**
     * @param  array<string, mixed>  $address
     * @return array{province_code: string, city_code: string, district_code: string, village_code: string, city: string}
     */
    public static function matchHierarchy(array $address): array
    {
        $province = self::matchByName(
            Province::query()->get(['code', 'name']),
            self::names($address, ['state', 'province', 'region']),
        );
        $city = $province === null ? null : self::matchByName(
            City::query()->where('province_code', $province->code)->get(['code', 'name']),
            self::names($address, ['city', 'town', 'municipality', 'county', 'state_district']),
        );
        $district = $city === null ? null : self::matchByName(
            District::query()->where('city_code', $city->code)->get(['code', 'name']),
            self::names($address, ['city_district', 'district', 'municipality', 'suburb']),
        );
        $village = $district === null ? null : self::matchByName(
            Village::query()->where('district_code', $district->code)->get(['code', 'name']),
            self::names($address, ['village', 'quarter', 'neighbourhood', 'hamlet', 'suburb']),
        );

        return [
            'province_code' => (string) ($province?->code ?? ''),
            'city_code' => (string) ($city?->code ?? ''),
            'district_code' => (string) ($district?->code ?? ''),
            'village_code' => (string) ($village?->code ?? ''),
            'city' => (string) ($city?->name ?? ''),
        ];
    }

    /** @return array<int, string> */
    private static function codes(mixed $values, string $pattern): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_slice(array_filter(
            $values,
            fn (mixed $value): bool => is_string($value) && preg_match($pattern, $value) === 1,
        ), 0, 20)));
    }

    /** @param array<string, mixed> $address @param array<int, string> $keys @return array<int, string> */
    private static function names(array $address, array $keys): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $key): string => trim((string) ($address[$key] ?? '')),
            $keys,
        ))));
    }

    /** @param Collection<int, object{code: string, name: string}> $regions @param array<int, string> $names */
    private static function matchByName(Collection $regions, array $names): ?object
    {
        foreach ($names as $name) {
            $exact = $regions->first(fn (object $region): bool => mb_strtoupper($region->name) === mb_strtoupper($name));

            if ($exact !== null) {
                return $exact;
            }
        }

        foreach ($names as $name) {
            $normalized = self::normalizeRegionName($name);
            $matches = $regions->filter(
                fn (object $region): bool => self::normalizeRegionName($region->name) === $normalized,
            );

            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        return null;
    }

    private static function normalizeRegionName(string $name): string
    {
        return trim((string) preg_replace(
            '/\b(PROVINSI|PROVINCE|KABUPATEN|KAB\.?|KOTA|CITY|KECAMATAN|KEC\.?|KELURAHAN|DESA)\b/u',
            '',
            mb_strtoupper($name),
        ));
    }
}
