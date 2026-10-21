<?php

namespace App\Services\Core;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

        // One cache per company: a hit is answered at once and a miss takes a round trip, so a cache shared by all companies
        // would tell one of them that another searched the same address in the last hour. v3: the key names the company.
        $key = 'geocode:nominatim:v3:'.(CompanyService::currentId() ?? 'none').':'.sha1(mb_strtolower($query));
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

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

            $results = collect($response->json())
                ->filter(fn (mixed $result): bool => is_array($result) && isset($result['lat'], $result['lon'], $result['display_name']))
                ->map(function (array $result): array {
                    $address = is_array($result['address'] ?? null) ? $result['address'] : [];

                    return [
                        'display_name' => (string) $result['display_name'],
                        'lat' => (string) $result['lat'],
                        'lng' => (string) $result['lon'],
                        'postal_code' => (string) ($address['postcode'] ?? ''),
                        // Road and house number as the street line of the address form; empty when OpenStreetMap has no road for the place.
                        'street' => trim(($address['road'] ?? '').' '.($address['house_number'] ?? '')),
                        ...IndonesiaRegionService::matchHierarchy($address),
                    ];
                })
                ->values()
                ->all();
        } catch (Throwable $e) {
            // Class only: a connection error's message holds the URL, and the URL holds the address that was searched.
            Log::warning('geocode.failed', ['error' => $e::class]);

            // Not cached: a refused or timed-out call says nothing about the address, and an hour of "nothing found" would hide it from every retry.
            return [];
        }

        // A service that answers and finds nothing is an answer, and is kept like any other.
        Cache::put($key, $results, now()->addHour());

        return $results;
    }
}
