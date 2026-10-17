<?php

namespace Tests\Feature\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * TRUSTED_PROXIES (config/security.php): by default nobody may name the client address, so a forged X-Forwarded-For
 * is ignored; a proxy the operator lists is believed. The value is read per request, because .env is not loaded yet
 * when bootstrap/app.php configures the middleware.
 */
class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/__client-address', fn (Request $request): string => (string) $request->ip());
    }

    protected function tearDown(): void
    {
        // Symfony keeps the trusted proxies of the last request in a static.
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        parent::tearDown();
    }

    public function test_no_proxy_is_trusted_by_default(): void
    {
        $this->clientAddressBehind('10.0.0.5')->assertContent('10.0.0.5');
    }

    public function test_a_listed_proxy_hands_over_the_client_address(): void
    {
        config(['security.trusted_proxies' => '10.0.0.4, 10.0.0.5']);

        $this->clientAddressBehind('10.0.0.5')->assertContent('203.0.113.9');
    }

    public function test_a_proxy_that_is_not_listed_cannot_name_the_client(): void
    {
        config(['security.trusted_proxies' => '10.0.0.4']);

        $this->clientAddressBehind('10.0.0.9')->assertContent('10.0.0.9');
    }

    public function test_an_empty_setting_trusts_nobody(): void
    {
        config(['security.trusted_proxies' => '']);

        $this->clientAddressBehind('10.0.0.5')->assertContent('10.0.0.5');
    }

    private function clientAddressBehind(string $proxy): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $proxy])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.9'])
            ->get('/__client-address');
    }
}
