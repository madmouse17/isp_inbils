<?php

namespace Tests\Feature\Api\V1\Matrix;

use App\Http\Middleware\Api\EnforceIdempotency;
use App\Support\PublicId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The throttle each api/v1 route sits behind, walked from route:list, and the three budgets a client can run
 * into in practice: the whole API per token, paying, uploading. Login (20 a minute per address) is
 * RateLimitersTest::test_logins_from_one_address_are_capped_at_twenty_a_minute_whatever_the_email.
 */
class ThrottleMatrixTest extends TestCase
{
    use BuildsMatrixWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWorld();
    }

    public function test_every_route_names_its_limiter(): void
    {
        $special = [
            'auth.login' => 'api-login',
            'auth.forgot-password' => 'api-forgot-password',
            'customer.invoices.pay' => 'api-pay',
            'customer.invoices.receipt' => 'api-receipt',
            'customer.promo-banners.tap' => 'api-promo-tap',
            'technician.work-orders.evidence.store' => 'api-upload',
            'customer.tickets.attachments.store' => 'api-upload',
            'technician.tickets.attachments.store' => 'api-upload',
            'me.push-test' => 'api-push-test',
            'technician.work-orders.network-credentials' => 'api-credentials',
        ];

        foreach ($this->apiRoutes() as $route) {
            $limiters = collect(app('router')->gatherRouteMiddleware($route))
                ->filter(fn (string $middleware): bool => str_starts_with($middleware, ThrottleRequests::class.':'))
                ->map(fn (string $middleware): string => Str::after($middleware, ':'))
                ->values()->all();

            $named = $special[$this->shortName($route)] ?? null;
            $expected = $named === null ? ['api'] : ['api', $named];
            $this->assertEqualsCanonicalizing($expected, $limiters, $route->uri().' carries the wrong throttles');
        }
    }

    public function test_the_seventh_payment_attempt_in_a_minute_is_a_429_and_another_customer_is_not_slowed(): void
    {
        $url = 'customer/invoices/'.$this->ids['invoice'].'/pay';

        for ($call = 1; $call <= 6; $call++) {
            $this->assertNotSame(429, $this->api('POST', $url, token: $this->customerToken)->status(), "call {$call}");
        }

        $key = (string) Str::uuid();
        $response = $this->api('POST', $url, token: $this->customerToken, headers: ['Idempotency-Key' => $key]);

        $response->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertNull(cache()->get(EnforceIdempotency::cacheKey($this->customerUser->id, $key)), 'a throttled call must not claim its key');
        $this->assertNotSame(429, $this->api('POST', $url, token: $this->issueToken($this->peerCustomerUser, 'customer'))->status());
    }

    public function test_the_thirty_first_upload_in_a_minute_is_a_429_whatever_the_work_order(): void
    {
        $url = 'technician/work-orders/'.$this->ids['work_order'].'/evidence';

        for ($call = 1; $call <= 30; $call++) {
            $this->assertSame(422, $this->api('POST', $url, token: $this->technicianToken)->status(), "call {$call} is counted even though it carries no file");
        }

        $this->api('POST', $url, token: $this->technicianToken)->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        $this->api('POST', 'technician/work-orders/'.PublicId::new().'/evidence', token: $this->technicianToken)->assertStatus(429);
        $this->assertNotSame(429, $this->api('POST', $url, token: $this->issueToken($this->peerTechnician, 'technician'))->status());
    }

    public function test_the_seventh_forgot_password_from_one_address_is_a_429(): void
    {
        for ($call = 1; $call <= 6; $call++) {
            $this->assertNotSame(429, $this->api('POST', 'auth/forgot-password', ['email' => "someone{$call}@example.test"])->status(), "call {$call}");
        }

        $this->api('POST', 'auth/forgot-password', ['email' => 'someone7@example.test'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests');
    }

    public function test_one_token_has_one_budget_of_120_calls_a_minute_across_all_routes(): void
    {
        $routes = ['me', 'notifications', 'devices', 'customer/profile'];

        for ($call = 0; $call < 120; $call++) {
            $this->api('GET', $routes[$call % 4], token: $this->customerToken)->assertOk();
        }

        $this->api('GET', 'customer/subscriptions', token: $this->customerToken)->assertStatus(429)->assertJsonPath('code', 'too_many_requests');

        // Each device holds its own budget: the user's other phone, and other users, carry on.
        $this->api('GET', 'me', token: $this->issueToken($this->customerUser, 'customer', 'Second phone'))->assertOk();
        $this->api('GET', 'me', token: $this->technicianToken)->assertOk();
    }
}
