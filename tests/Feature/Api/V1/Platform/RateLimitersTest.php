<?php

namespace Tests\Feature\Api\V1\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

/**
 * The named limiters of the API: 120 a minute per token, the login address cap, and the ones the
 * payment and upload tickets attach to their routes.
 */
class RateLimitersTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    public function test_every_call_counts_against_the_token_120_a_minute(): void
    {
        [$user] = $this->customerLogin();
        [$neighbour] = $this->customerLogin();
        $token = $this->issueToken($user, 'customer');
        $neighbourToken = $this->issueToken($neighbour, 'customer');

        for ($call = 1; $call <= 120; $call++) {
            $this->api('GET', 'me', token: $token)->assertOk();
        }
        $response = $this->api('GET', 'me', token: $token);

        $response->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->api('GET', 'me', token: $neighbourToken)->assertOk();
    }

    public function test_logins_from_one_address_are_capped_at_twenty_a_minute_whatever_the_email(): void
    {
        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $this->api('POST', 'auth/login', ['email' => "nobody{$attempt}@example.test", 'password' => 'x', 'device_name' => 'Pixel', 'app' => 'customer'])
                ->assertStatus(422);
        }

        $this->api('POST', 'auth/login', ['email' => 'nobody21@example.test', 'password' => 'x', 'device_name' => 'Pixel', 'app' => 'customer'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests');

        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])
            ->postJson('/api/v1/auth/login', ['email' => 'nobody22@example.test', 'password' => 'x', 'device_name' => 'Pixel', 'app' => 'customer'])
            ->assertStatus(422);
    }

    public function test_the_pay_limiter_allows_six_a_minute_per_user(): void
    {
        [$user] = $this->customerLogin();
        [$other] = $this->customerLogin();

        $limit = RateLimiter::limiter('api-pay')($this->requestFor($user));

        $this->assertSame(6, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertNotSame($limit->key, RateLimiter::limiter('api-pay')($this->requestFor($other))->key);
        $this->assertSame($limit->key, RateLimiter::limiter('api-pay')($this->requestFor($user))->key);
    }

    public function test_the_upload_limiter_allows_thirty_a_minute_per_user(): void
    {
        [$user] = $this->customerLogin();
        [$other] = $this->customerLogin();

        $limit = RateLimiter::limiter('api-upload')($this->requestFor($user));

        $this->assertSame(30, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertNotSame($limit->key, RateLimiter::limiter('api-upload')($this->requestFor($other))->key);
    }

    private function requestFor(object $user): Request
    {
        $request = Request::create('/api/v1/probe', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}
