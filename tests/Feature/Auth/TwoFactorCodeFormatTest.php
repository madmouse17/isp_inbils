<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Core\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\EnrollsTwoFactor;

/**
 * Authenticator apps show the code grouped ("123 456") and phones paste odd spaces; none of it is part of the code.
 */
class TwoFactorCodeFormatTest extends TestCase
{
    use CreatesCompanyUser;
    use EnrollsTwoFactor;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 12, 10, 0, 0, 'UTC'));
    }

    public function test_confirming_accepts_the_code_grouped_with_a_space(): void
    {
        $service = app(TwoFactorService::class);
        $user = User::factory()->create();
        $secret = $service->beginEnrollment($user)['setup_key'];
        $code = $this->totp($secret);

        $this->assertNotNull($service->confirm($user, substr($code, 0, 3).' '.substr($code, 3)));
    }

    public function test_confirming_and_the_challenge_accept_non_breaking_and_thin_spaces(): void
    {
        $service = app(TwoFactorService::class);
        $user = User::factory()->create();
        $secret = $service->beginEnrollment($user)['setup_key'];
        $code = $this->totp($secret);

        $this->assertNotNull($service->confirm($user, substr($code, 0, 3)."\u{00A0}".substr($code, 3)));

        $this->travel(30)->seconds();
        $next = $this->totp($secret);
        $this->assertTrue($service->verifyChallenge($user, substr($next, 0, 3)."\u{2009}".substr($next, 3)));
    }

    public function test_the_profile_form_takes_a_grouped_code(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
        $this->post(route('two-factor.enable'));
        $secret = $this->get(route('profile.edit'))->inertiaPage()['flash']['two_factor']['setup']['setup_key'];
        $code = $this->totp($secret);

        $this->post(route('two-factor.confirm'), ['code' => substr($code, 0, 3).' '.substr($code, 3)])
            ->assertSessionHasNoErrors();

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }
}
