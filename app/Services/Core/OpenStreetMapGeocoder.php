<?php

namespace App\Services\Core;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class OpenStreetMapGeocoder
{
    /** @return array<int, array<string, string>> */
    public function search(string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 3) {
            return [];
        }

        return Cache::remember('geocode:nominatim:'.sha1(mb_strtolower($query)), now()->addHour(), function () use ($query): array {
            try {
                $response = Http::acceptJson()
                    ->withUserAgent(config('app.name').' ('.config('app.url').')')
                    ->timeout(8)
                    ->get('https://nominatim.openstreetmap.org/search', [
                        'q' => $query,
                        'format' => 'jsonv2',
                        'addressdetails' => 1,
                        'countrycodes' => 'id',
                        'limit' => 5,
                    ])
                    ->throw();

                return collect($response->json())
                    ->filter(fn (mixed $result): bool => is_array($result) && isset($result['lat'], $result['lon'], $result['display_name']))
                    ->map(function (array $result): array {
                        $address = is_array($result['address'] ?? null) ? $result['address'] : [];

                        return [
                            'display_name' => (string) $result['display_name'],
                            'lat' => (string) $result['lat'],
                            'lng' => (string) $result['lon'],
                            'postal_code' => (string) ($address['postcode'] ?? ''),
                            ...IndonesiaRegionService::matchHierarchy($address),
                        ];
                    })
                    ->values()
                    ->all();
            } catch (Throwable) {
                return [];
            }
        });
    }
}
