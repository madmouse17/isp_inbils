<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiExposureTest extends TestCase
{
    /** @var list<string> */
    private const SCAFFOLD_ENDPOINTS = [
        'billings',
        'customers',
        'inventories',
        'networkassets',
        'reportings',
        'services',
        'spks',
        'ticketings',
    ];

    /** @var list<string> */
    private const MEMBER_SUFFIXES = ['/1', '/abc', '/999999999999999'];

    /** @var list<non-empty-string> */
    private const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public function test_scaffold_module_api_routes_are_not_registered(): void
    {
        foreach (self::SCAFFOLD_ENDPOINTS as $endpoint) {
            $routeName = match ($endpoint) {
                'billings' => 'api.billing.index',
                'customers' => 'api.customer.index',
                'inventories' => 'api.inventory.index',
                'networkassets' => 'api.networkasset.index',
                'reportings' => 'api.reporting.index',
                'services' => 'api.service.index',
                'spks' => 'api.spk.index',
                'ticketings' => 'api.ticketing.index',
            };

            $this->assertNull(Route::getRoutes()->getByName($routeName));
        }
    }

    public function test_scaffold_module_api_collection_paths_return_404_for_guests(): void
    {
        foreach (self::SCAFFOLD_ENDPOINTS as $endpoint) {
            $this->getJson("/api/v1/{$endpoint}")->assertNotFound();
        }
    }

    public function test_scaffold_module_api_collection_paths_return_404_for_authenticated_users(): void
    {
        $user = new User(['id' => 1]);

        foreach (self::SCAFFOLD_ENDPOINTS as $endpoint) {
            $this->actingAs($user)->getJson("/api/v1/{$endpoint}")->assertNotFound();
        }
    }

    public function test_scaffold_module_api_member_paths_return_404_for_guests(): void
    {
        foreach (self::SCAFFOLD_ENDPOINTS as $endpoint) {
            foreach (self::MEMBER_SUFFIXES as $suffix) {
                $this->getJson("/api/v1/{$endpoint}{$suffix}")->assertNotFound();
            }
        }
    }

    public function test_scaffold_module_api_member_paths_return_404_for_authenticated_users(): void
    {
        $user = new User(['id' => 1]);

        foreach (self::SCAFFOLD_ENDPOINTS as $endpoint) {
            foreach (self::MEMBER_SUFFIXES as $suffix) {
                $this->actingAs($user)->getJson("/api/v1/{$endpoint}{$suffix}")->assertNotFound();
            }
        }
    }

    public function test_scaffold_module_api_returns_404_for_all_http_methods(): void
    {
        $user = new User(['id' => 1]);

        foreach (self::SCAFFOLD_ENDPOINTS as $endpoint) {
            foreach (self::HTTP_METHODS as $method) {
                $uri = "/api/v1/{$endpoint}";

                // GET is checked via collection path above
                if ($method === 'GET') {
                    $this->getJson($uri)->assertNotFound();

                    continue;
                }

                $this->json($method, $uri)->assertNotFound();
            }
        }
    }

    public function test_authenticated_users_get_404_for_all_http_methods(): void
    {
        $user = new User(['id' => 1]);

        foreach (self::SCAFFOLD_ENDPOINTS as $endpoint) {
            foreach (self::HTTP_METHODS as $method) {
                $uri = "/api/v1/{$endpoint}";

                if ($method === 'GET') {
                    $this->actingAs($user)->getJson($uri)->assertNotFound();

                    continue;
                }

                $this->actingAs($user)->json($method, $uri)->assertNotFound();
            }
        }
    }

    public function test_member_paths_return_404_for_all_http_methods(): void
    {
        $user = new User(['id' => 1]);

        foreach (self::SCAFFOLD_ENDPOINTS as $endpoint) {
            foreach (self::MEMBER_SUFFIXES as $suffix) {
                $uri = "/api/v1/{$endpoint}{$suffix}";

                foreach (self::HTTP_METHODS as $method) {
                    if ($method === 'GET') {
                        $this->getJson($uri)->assertNotFound();
                    } else {
                        $this->json($method, $uri)->assertNotFound();
                    }
                }
            }
        }
    }

    public function test_authenticated_member_paths_return_404_for_all_http_methods(): void
    {
        $user = new User(['id' => 1]);

        foreach (self::SCAFFOLD_ENDPOINTS as $endpoint) {
            foreach (self::MEMBER_SUFFIXES as $suffix) {
                $uri = "/api/v1/{$endpoint}{$suffix}";

                foreach (self::HTTP_METHODS as $method) {
                    if ($method === 'GET') {
                        $this->actingAs($user)->getJson($uri)->assertNotFound();
                    } else {
                        $this->actingAs($user)->json($method, $uri)->assertNotFound();
                    }
                }
            }
        }
    }

    public function test_scaffold_module_api_return_404_with_query_string(): void
    {
        foreach (self::SCAFFOLD_ENDPOINTS as $endpoint) {
            $this->getJson("/api/v1/{$endpoint}?foo=bar&page=1")->assertNotFound();
        }
    }

    public function test_scaffold_module_api_return_404_with_trailing_slash(): void
    {
        foreach (self::SCAFFOLD_ENDPOINTS as $endpoint) {
            $this->getJson("/api/v1/{$endpoint}/")->assertNotFound();
        }
    }
}
