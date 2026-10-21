<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\Core\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\EnrollsTwoFactor;

/**
 * POST /login for a user with two-factor authentication on: nobody is authenticated until the
 * challenge passes, and the challenge is throttled, single-use and bound to the session it started in.
 */
class TwoFactorLoginTest extends TestCase
{
    use BuildsCompanyGroup;
    use CreatesCompanyUser;
    use EnrollsTwoFactor;
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
        $this->user = $this->createCompanyUser();
        ['secret' => $this->secret, 'codes' => $this->codes] = $this->enrollTwoFactor($this->user);
        $this->travel(30)->seconds();
    }

    public function test_a_user_without_two_factor_still_logs_in_with_the_password_alone(): void
    {
        $plain = $this->userWithoutTwoFactor();

        $this->passwordLogin($plain)->assertRedirect(route('admin.dashboard', absolute: false));

        $this->assertAuthenticatedAs($plain);
        $this->assertNotNull($plain->fresh()->last_login_at);
        $this->assertNull(session('login.two_factor'));
    }

    public function test_an_unconfirmed_enrolment_does_not_block_the_login(): void
    {
        $plain = $this->userWithoutTwoFactor();
        app(TwoFactorService::class)->beginEnrollment($plain);

        $this->passwordLogin($plain)->assertRedirect(route('admin.dashboard', absolute: false));

        $this->assertAuthenticatedAs($plain);
    }

    public function test_the_password_alone_does_not_log_in_a_user_with_two_factor(): void
    {
        $sessionId = $this->pinSession();

        $this->passwordLogin()->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
        $this->assertNull($this->user->fresh()->last_login_at, 'the Login event has not fired');
        $this->assertNotSame($sessionId, session()->getId(), 'the half-open state gets a new session id');
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertSame(['id', 'remember', 'expires_at'], array_keys(session('login.two_factor')), 'only the id and the remember choice are kept');
    }

    public function test_a_wrong_password_never_reaches_the_challenge(): void
    {
        $this->passwordLogin(password: 'wrong-password')->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertNull(session('login.two_factor'));
    }

    public function test_the_challenge_page_needs_a_login_that_passed_the_password(): void
    {
        $this->get(route('two-factor.login'))->assertRedirect(route('login'));

        $this->passwordLogin();

        $this->get(route('two-factor.login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/TwoFactorChallenge'));
    }

    public function test_an_authenticated_user_is_sent_away_from_the_challenge(): void
    {
        $this->actingAs($this->user)->get(route('two-factor.login'))->assertRedirect();
    }

    public function test_a_valid_code_completes_the_login_on_a_new_session(): void
    {
        $sessionId = $this->pinSession();
        $this->passwordLogin();

        $this->post(route('two-factor.login'), ['code' => $this->totp($this->secret)])
            ->assertRedirect(route('admin.dashboard', absolute: false));

        $this->assertAuthenticatedAs($this->user);
        $this->assertNotNull($this->user->fresh()->last_login_at);
        $this->assertNotSame($sessionId, session()->getId(), 'regenerated once the challenge passed');
        $this->assertNull(session('login.two_factor'), 'the parked login is gone');
    }

    public function test_the_login_goes_on_to_the_page_the_user_was_headed_for(): void
    {
        $this->withSession(['url.intended' => url('/admin/customers')]);
        $this->passwordLogin();

        $this->post(route('two-factor.login'), ['code' => $this->totp($this->secret)])
            ->assertRedirect(url('/admin/customers'));
    }

    public function test_the_company_switch_is_cleared_only_once_the_challenge_passes(): void
    {
        $this->withSession([CompanyService::SESSION_KEY => 987654]);
        $this->passwordLogin();

        $this->post(route('two-factor.login'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertSame(987654, session(CompanyService::SESSION_KEY), 'a failed code changes nothing');

        $this->post(route('two-factor.login'), ['code' => $this->totp($this->secret)]);
        $this->assertNull(session(CompanyService::SESSION_KEY));
    }

    public function test_a_wrong_code_is_refused_audited_and_leaves_the_session_alone(): void
    {
        $sessionId = $this->pinSession();
        $this->passwordLogin();
        $parkedId = session()->getId();

        $this->withCookie(config('session.cookie'), $parkedId)
            ->post(route('two-factor.login'), ['code' => '000000'])
            ->assertSessionHasErrors(['code' => 'Kode autentikasi dua langkah tidak valid.']);

        $this->assertGuest();
        $this->assertSame($parkedId, session()->getId(), 'no regeneration without a pass');
        $this->assertNotSame($sessionId, $parkedId);
        $audit = Activity::query()->where('description', 'two_factor.challenge_failed')->sole();
        $this->assertSame($this->user->id, $audit->subject_id);
        $this->assertSame('totp', $audit->properties['method']);
    }

    public function test_the_challenge_needs_a_code(): void
    {
        $this->passwordLogin();

        $this->post(route('two-factor.login'), [])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_codes_outside_the_window_are_refused(): void
    {
        $this->passwordLogin();

        foreach ([-2, 2] as $offset) {
            $this->post(route('two-factor.login'), ['code' => $this->totp($this->secret, $offset)])->assertSessionHasErrors('code');
        }

        $this->assertGuest();
    }

    public function test_a_code_cannot_be_replayed_to_log_in_again(): void
    {
        $code = $this->totp($this->secret);
        // Mutation check: without the replay guard in TwoFactorService::matchingStep this fails at the
        // second login, where the spent code is accepted again.
        $this->passwordLogin();
        $this->post(route('two-factor.login'), ['code' => $code])->assertRedirect(route('admin.dashboard', absolute: false));
        $this->post(route('logout'));
        $this->assertGuest();

        $this->passwordLogin();
        $this->post(route('two-factor.login'), ['code' => $code])->assertSessionHasErrors('code');

        $this->assertGuest();
        // The next step's code is still fine: only the spent step is closed.
        $this->post(route('two-factor.login'), ['code' => $this->totp($this->secret, 1)])->assertRedirect(route('admin.dashboard', absolute: false));
    }

    public function test_a_recovery_code_logs_in_once_and_then_fails(): void
    {
        $this->passwordLogin();
        $this->post(route('two-factor.login'), ['code' => $this->codes[0]])->assertRedirect(route('admin.dashboard', absolute: false));
        $this->assertAuthenticatedAs($this->user);
        $this->post(route('logout'));

        $this->passwordLogin();
        $this->post(route('two-factor.login'), ['code' => $this->codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->post(route('two-factor.login'), ['code' => $this->codes[1]])->assertRedirect(route('admin.dashboard', absolute: false));

        $this->assertSame(6, app(TwoFactorService::class)->status($this->user->fresh())['recovery_codes_remaining']);
        $this->assertSame(2, Activity::query()->where('description', 'two_factor.recovery_code_used')->count());
    }

    public function test_the_sixth_attempt_is_throttled_even_with_the_right_code(): void
    {
        $this->passwordLogin();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('two-factor.login'), ['code' => '000000'])
                ->assertSessionHasErrors(['code' => 'Kode autentikasi dua langkah tidak valid.']);
        }

        $this->post(route('two-factor.login'), ['code' => $this->totp($this->secret)])->assertSessionHasErrors('code');
        $this->assertStringContainsString('Terlalu banyak', session('errors')->first('code'));
        $this->assertGuest();

        $this->travel(61)->seconds();

        $this->post(route('two-factor.login'), ['code' => $this->totp($this->secret)])->assertRedirect(route('admin.dashboard', absolute: false));
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_the_challenge_throttle_is_per_user_and_address(): void
    {
        $this->passwordLogin();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('two-factor.login'), ['code' => '000000']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->post(route('two-factor.login'), ['code' => $this->totp($this->secret)])
            ->assertRedirect(route('admin.dashboard', absolute: false));
    }

    public function test_the_password_step_keeps_its_own_throttle(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->passwordLogin(password: 'wrong-password')->assertSessionHasErrors('email');
        }

        $this->passwordLogin()->assertSessionHasErrors('email');
        $this->assertStringContainsString('Terlalu banyak', session('errors')->first('email'));
        $this->assertNull(session('login.two_factor'), 'even the right password is not parked while throttled');
    }

    public function test_remember_me_is_applied_when_the_challenge_passes(): void
    {
        $recaller = Auth::guard('web')->getRecallerName();

        $this->passwordLogin(remember: true);
        $this->assertTrue(session('login.two_factor.remember'));
        $this->post(route('two-factor.login'), ['code' => $this->totp($this->secret)])->assertCookie($recaller, null, false);
    }

    public function test_without_remember_me_no_remember_cookie_is_set(): void
    {
        $recaller = Auth::guard('web')->getRecallerName();

        $this->passwordLogin();
        $this->post(route('two-factor.login'), ['code' => $this->totp($this->secret)])->assertCookieMissing($recaller);
    }

    public function test_the_parked_login_expires(): void
    {
        $this->passwordLogin();
        $this->travel(11)->minutes();

        $this->post(route('two-factor.login'), ['code' => $this->totp($this->secret)])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull(session('login.two_factor'));
    }

    public function test_a_parked_login_is_dropped_when_two_factor_is_turned_off_meanwhile(): void
    {
        $this->passwordLogin();
        app(TwoFactorService::class)->disable($this->user);

        $this->post(route('two-factor.login'), ['code' => $this->totp($this->secret)])->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_posting_a_code_without_a_parked_login_goes_back_to_the_login(): void
    {
        $this->post(route('two-factor.login'), ['code' => $this->totp($this->secret)])->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_two_factor_still_applies_to_a_user_of_several_companies_who_had_switched(): void
    {
        $this->buildGroup();
        $member = $this->groupUser($this->head, 'admin', $this->subA);
        ['secret' => $secret] = $this->enrollTwoFactor($member);
        $this->travel(30)->seconds();

        $this->withSession([CompanyService::SESSION_KEY => $this->subA->id]);
        $this->passwordLogin($member)->assertRedirect(route('two-factor.login'));
        $this->assertGuest();

        $this->post(route('two-factor.login'), ['code' => $this->totp($secret)]);
        $this->assertAuthenticatedAs($member);
        $this->assertNull(session(CompanyService::SESSION_KEY), 'every login starts in the home company');
    }

    public function test_resetting_the_password_does_not_log_in_and_the_challenge_still_applies(): void
    {
        $token = Password::broker()->createToken($this->user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $this->user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect(route('login'));
        $this->assertGuest();

        $this->post('/login', ['email' => $this->user->email, 'password' => 'new-password-123'])
            ->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    private function passwordLogin(?User $user = null, string $password = 'password', bool $remember = false): TestResponse
    {
        return $this->post('/login', [
            'email' => ($user ?? $this->user)->email,
            'password' => $password,
            'remember' => $remember,
        ]);
    }

    /** Pins the session id the browser would send, so a test can see it being replaced. */
    private function pinSession(): string
    {
        $this->withCookie(config('session.cookie'), $id = Str::random(40));

        return $id;
    }

    private function userWithoutTwoFactor(): User
    {
        $user = User::factory()->create([
            'company_id' => $this->user->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->grantRole($user, 'admin');

        return $user;
    }
}
