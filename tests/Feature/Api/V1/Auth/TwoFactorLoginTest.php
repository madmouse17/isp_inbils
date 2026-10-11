<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use App\Services\Core\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;
use Tests\Traits\EnrollsTwoFactor;
use Tests\Traits\InteractsWithMobileApi;

/**
 * Login for a user with confirmed two-factor authentication: the password alone opens a challenge, never a token,
 * and the challenge is single-use, short-lived, capped at five wrong codes and throttled with the web budgets.
 */
class TwoFactorLoginTest extends TestCase
{
    use EnrollsTwoFactor;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private User $user;

    private string $secret;

    /** @var list<string> */
    private array $codes;

    protected function setUp(): void
    {
        parent::setUp();

        // On a 30-second boundary; after enrolment the clock moves one step so the next code is fresh.
        $this->travelTo(Carbon::create(2026, 10, 12, 10, 0, 0, 'UTC'));
        [$this->user] = $this->customerLogin();
        ['secret' => $this->secret, 'codes' => $this->codes] = $this->enrollTwoFactor($this->user);
        $this->travel(30)->seconds();
    }

    public function test_the_password_alone_opens_a_challenge_and_never_a_token(): void
    {
        $response = $this->password()->assertStatus(202);

        $response->assertJsonPath('data.two_factor_required', true)->assertJsonPath('data.expires_in', 300);
        $this->assertSame(64, strlen($response->json('data.challenge_id')));
        $this->assertSame(['two_factor_required', 'challenge_id', 'expires_in'], array_keys($response->json('data')));
        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->assertNull($this->user->fresh()->last_login_at, 'nobody is signed in yet');
    }

    public function test_a_valid_authenticator_code_completes_the_login(): void
    {
        $challenge = $this->challenge('Pixel 8');

        $response = $this->submit($challenge, $this->totp($this->secret))->assertOk();

        $response->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.id', $this->user->id)
            ->assertJsonPath('data.user.app', 'customer')
            ->assertJsonPath('data.user.two_factor_enabled', true);
        $this->assertTrue(Carbon::parse($response->json('data.expires_at'))->equalTo(now()->addDays(90)));
        $token = PersonalAccessToken::query()->sole();
        $this->assertSame('Pixel 8', $token->name, 'the device named at the password step');
        $this->assertSame(['customer'], $token->abilities);
        $this->assertNotNull($this->user->fresh()->last_login_at);
        $this->api('GET', 'me', token: $response->json('data.token'))->assertOk();
    }

    public function test_a_challenge_is_spent_by_the_login_it_completes(): void
    {
        $challenge = $this->challenge();
        $this->submit($challenge, $this->totp($this->secret))->assertOk();
        $this->travel(30)->seconds();

        $this->submit($challenge, $this->totp($this->secret))->assertStatus(422)->assertJsonPath('code', 'challenge_expired');

        $this->assertSame(1, PersonalAccessToken::query()->count());
    }

    public function test_a_replayed_authenticator_code_is_refused(): void
    {
        $code = $this->totp($this->secret);
        $this->submit($this->challenge(), $code)->assertOk();

        $this->submit($this->challenge(), $code)
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['code']);

        $this->assertSame(1, PersonalAccessToken::query()->count());
    }

    public function test_a_recovery_code_works_once(): void
    {
        $this->submit($this->challenge(), $this->codes[0])->assertOk();

        $this->submit($this->challenge(), $this->codes[0])->assertStatus(422)->assertJsonValidationErrors(['code']);
        $this->submit($this->challenge(), $this->codes[1])->assertOk();

        $this->assertSame(2, PersonalAccessToken::query()->count());
    }

    public function test_a_wrong_code_is_a_validation_failure_without_a_token(): void
    {
        $response = $this->submit($this->challenge(), '000000')->assertStatus(422);

        $response->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['code']);
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_an_unknown_and_an_expired_challenge_are_the_same_422(): void
    {
        $this->submit(str_repeat('a', 64), $this->totp($this->secret))->assertStatus(422)->assertJsonPath('code', 'challenge_expired');

        $challenge = $this->challenge();
        $this->travel(301)->seconds();

        $this->submit($challenge, $this->totp($this->secret))->assertStatus(422)->assertJsonPath('code', 'challenge_expired');
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_the_sixth_wrong_code_within_a_minute_is_a_429_and_a_dead_challenge_stays_dead(): void
    {
        $challenge = $this->challenge();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->submit($challenge, '000000')->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        }

        $blocked = $this->submit($challenge, '000000');
        $blocked->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        $this->assertGreaterThan(0, (int) $blocked->headers->get('Retry-After'));

        // The address window is over, yet five wrong codes killed the challenge: even the right code opens nothing.
        $this->travel(61)->seconds();
        $this->submit($challenge, $this->totp($this->secret))->assertStatus(422)->assertJsonPath('code', 'challenge_expired');
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_the_web_challenge_budget_is_shared_so_even_the_right_code_is_refused_and_stays_unspent(): void
    {
        $challenge = $this->challenge();
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            RateLimiter::hit('two-factor|'.$this->user->id.'|127.0.0.1', 60);
        }
        $stepBefore = $this->user->fresh()->two_factor_last_used_step;

        $this->submit($challenge, $this->totp($this->secret))->assertStatus(429);

        $this->assertSame($stepBefore, $this->user->fresh()->two_factor_last_used_step, 'a throttled attempt is never checked');
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_the_per_user_budget_across_addresses_is_shared_too(): void
    {
        $challenge = $this->challenge();
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            RateLimiter::hit('two-factor-user|'.$this->user->id, 900);
        }

        $this->submit($challenge, $this->totp($this->secret))->assertStatus(429);
    }

    public function test_wrong_codes_here_spend_the_same_counters_as_the_web_challenge_and_a_good_one_gives_them_back(): void
    {
        $challenge = $this->challenge();
        $this->submit($challenge, '000000')->assertStatus(422);
        $this->submit($challenge, '000000')->assertStatus(422);

        $this->assertSame(2, RateLimiter::attempts('two-factor|'.$this->user->id.'|127.0.0.1'));
        $this->assertSame(2, RateLimiter::attempts('two-factor-user|'.$this->user->id));

        $this->submit($challenge, $this->totp($this->secret))->assertOk();

        $this->assertSame(0, RateLimiter::attempts('two-factor|'.$this->user->id.'|127.0.0.1'));
        $this->assertSame(0, RateLimiter::attempts('two-factor-user|'.$this->user->id));
    }

    public function test_deactivating_the_user_or_turning_two_factor_off_between_the_steps_voids_the_challenge(): void
    {
        $deactivated = $this->challenge();
        User::query()->whereKey($this->user->id)->update(['is_active' => false]);
        $this->submit($deactivated, $this->totp($this->secret))->assertStatus(422)->assertJsonPath('code', 'challenge_expired');

        User::query()->whereKey($this->user->id)->update(['is_active' => true]);
        $turnedOff = $this->challenge();
        app(TwoFactorService::class)->disable($this->user->fresh());
        $this->submit($turnedOff, $this->totp($this->secret))->assertStatus(422)->assertJsonPath('code', 'challenge_expired');

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_access_to_the_app_is_checked_again_when_the_code_is_accepted(): void
    {
        $challenge = $this->challenge();
        $this->user->removeRole('customer');

        $this->submit($challenge, $this->totp($this->secret))->assertForbidden()->assertJsonPath('code', 'app_not_allowed');

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_the_password_step_does_not_reveal_whether_the_app_is_allowed(): void
    {
        $this->user->removeRole('customer');

        $this->password()->assertStatus(202);
    }

    public function test_the_technician_app_gets_a_challenge_too_and_a_thirty_day_token(): void
    {
        $technician = $this->technicianLogin();
        ['secret' => $secret] = $this->enrollTwoFactor($technician);
        $this->travel(30)->seconds();

        $challenge = $this->api('POST', 'auth/login', ['email' => $technician->email, 'password' => 'password', 'device_name' => 'Galaxy', 'app' => 'technician'])
            ->assertStatus(202)->json('data.challenge_id');
        $response = $this->submit($challenge, $this->totp($secret))->assertOk();

        $response->assertJsonPath('data.user.app', 'technician');
        $this->assertTrue(Carbon::parse($response->json('data.expires_at'))->equalTo(now()->addDays(30)));
        $this->assertSame(['technician'], PersonalAccessToken::query()->where('tokenable_id', $technician->id)->sole()->abilities);
    }

    public function test_both_fields_are_required(): void
    {
        $this->api('POST', 'auth/two-factor', [])->assertStatus(422)->assertJsonValidationErrors(['challenge_id', 'code']);
    }

    private function password(string $device = 'Pixel 8'): TestResponse
    {
        return $this->api('POST', 'auth/login', ['email' => $this->user->email, 'password' => 'password', 'device_name' => $device, 'app' => 'customer']);
    }

    private function challenge(string $device = 'Pixel 8'): string
    {
        return $this->password($device)->assertStatus(202)->json('data.challenge_id');
    }

    private function submit(string $challenge, string $code): TestResponse
    {
        return $this->api('POST', 'auth/two-factor', ['challenge_id' => $challenge, 'code' => $code]);
    }
}
