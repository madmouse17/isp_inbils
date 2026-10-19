<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/__error/{status}', fn (int $status) => abort($status));
        Route::middleware('api')->get('/api/v1/__error/{status}', fn (int $status) => abort($status));
        Route::get('/webhooks/__error/{status}', fn (int $status) => abort($status));
    }

    /** @return array<string, array{int}> */
    public static function statuses(): array
    {
        return [403 => [403], 404 => [404], 419 => [419], 500 => [500], 503 => [503]];
    }

    #[DataProvider('statuses')]
    public function test_a_browser_gets_the_inertia_error_page_with_its_status(int $status): void
    {
        config(['app.debug' => false]);

        $this->get("/__error/{$status}")
            ->assertStatus($status)
            ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', $status));
    }

    public function test_an_unknown_url_renders_the_error_page_for_a_guest(): void
    {
        $this->get('/tidak-ada-halaman')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 404));
    }

    public function test_the_error_page_carries_the_request_id(): void
    {
        config(['app.debug' => false]);

        $this->get('/__error/500', ['X-Request-Id' => 'req-error-page-1'])
            ->assertStatus(500)
            ->assertHeader('X-Request-Id', 'req-error-page-1')
            ->assertInertia(fn (Assert $page) => $page->component('Error')->where('requestId', 'req-error-page-1'));

        $generated = $this->get('/__error/404')->assertNotFound();

        $generated->assertInertia(fn (Assert $page) => $page->where('requestId', $generated->headers->get('X-Request-Id')));
    }

    public function test_a_maintenance_503_carries_a_request_id(): void
    {
        // The cache driver on the array store keeps maintenance mode inside this test: the file driver would take the whole dev site down.
        config(['app.debug' => false, 'app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
        $this->app->maintenanceMode()->activate(['retry' => 60]);

        $this->get('/tidak-ada-halaman', ['X-Request-Id' => 'req-maintenance-1'])
            ->assertStatus(503)
            ->assertHeader('X-Request-Id', 'req-maintenance-1')
            ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 503)->where('requestId', 'req-maintenance-1'));
    }

    public function test_a_500_keeps_laravels_debug_page_while_debug_is_on(): void
    {
        config(['app.debug' => true]);

        $response = $this->get('/__error/500')->assertStatus(500);

        $this->assertStringNotContainsString('data-page', (string) $response->getContent());
    }

    public function test_json_clients_the_api_and_webhooks_keep_plain_answers(): void
    {
        config(['app.debug' => false]);

        $this->getJson('/__error/404')->assertNotFound()->assertJsonStructure(['message']);
        $this->getJson('/api/v1/__error/404')->assertNotFound()->assertJsonStructure(['message']);
        $this->get('/api/v1/__error/403', ['Accept' => 'text/html'])->assertForbidden()->assertJsonStructure(['message']);

        $webhook = $this->get('/webhooks/__error/403', ['Accept' => 'text/html'])->assertForbidden();
        $this->assertStringNotContainsString('data-page', (string) $webhook->getContent());
    }
}
