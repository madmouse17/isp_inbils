<?php

namespace Tests\Feature\Api\V1\Auth;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

/**
 * POST /api/v1/auth/forgot-password: the answer never says whether the address has an account (web parity).
 * The reset itself happens on the existing web page that the e-mailed link opens.
 */
class ForgotPasswordTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private const MESSAGE = 'If an account exists for that email address, we have emailed a password reset link.';

    public function test_a_known_an_unknown_and_a_broker_throttled_address_get_exactly_the_same_202(): void
    {
        Notification::fake();
        [$user] = $this->customerLogin();

        $known = $this->api('POST', 'auth/forgot-password', ['email' => $user->email]);
        $unknown = $this->api('POST', 'auth/forgot-password', ['email' => 'nobody@example.test']);
        $again = $this->api('POST', 'auth/forgot-password', ['email' => $user->email]);

        foreach ([$known, $unknown, $again] as $response) {
            $response->assertStatus(202)->assertExactJson(['message' => self::MESSAGE]);
        }
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
        Notification::assertCount(1);
    }

    public function test_the_known_address_receives_the_standard_reset_link_for_the_web_page(): void
    {
        Notification::fake();
        [$user] = $this->customerLogin();

        $this->api('POST', 'auth/forgot-password', ['email' => $user->email])->assertStatus(202);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;

            return str_contains($url, '/reset-password/'.$notification->token) && str_contains($url, urlencode($user->email));
        });
    }

    public function test_a_login_flagged_for_a_password_reset_can_ask_for_the_link(): void
    {
        Notification::fake();
        [$user] = $this->customerLogin();
        $user->forceFill(['must_change_password' => true])->save();

        $this->api('POST', 'auth/forgot-password', ['email' => $user->email])->assertStatus(202);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_it_is_throttled_to_six_a_minute_per_address(): void
    {
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->api('POST', 'auth/forgot-password', ['email' => "nobody{$attempt}@example.test"])->assertStatus(202);
        }

        $response = $this->api('POST', 'auth/forgot-password', ['email' => 'nobody7@example.test']);

        $response->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.8.7'])->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody8@example.test'])->assertStatus(202);
    }

    public function test_a_malformed_address_is_a_validation_failure(): void
    {
        $this->api('POST', 'auth/forgot-password', ['email' => 'not-an-email'])->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->api('POST', 'auth/forgot-password', [])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }
}
