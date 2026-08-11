<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiExposureTest extends TestCase
{
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

    public function test_scaffold_module_api_endpoints_return_404(): void
    {
        foreach (self::SCAFFOLD_ENDPOINTS as $endpoint) {
            $this->getJson("/api/v1/{$endpoint}")->assertNotFound();
        }
    }

    public function test_authenticated_users_cannot_bypass_scaffold_api_containment(): void
    {
        $user = new User(['id' => 1]);

        foreach (self::SCAFFOLD_ENDPOINTS as $endpoint) {
            $this->actingAs($user)->getJson("/api/v1/{$endpoint}")->assertNotFound();
        }
    }
}
