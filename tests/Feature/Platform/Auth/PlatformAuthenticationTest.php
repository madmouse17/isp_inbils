<?php

namespace Tests\Feature\Platform\Auth;

use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Services\Core\CompanyService;
use App\Services\Core\TwoFactorService;
use App\Support\PlatformSession;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\EnrollsTwoFactor;
use Tests\Traits\InteractsWithPlatform;

/**
 * The operator login (/platform): a separate identity on a separate guard, TOTP mandatory and proven by every
 * session itself, a 30-minute idle timeout and a platform-only password confirmation. Inertia pages of the panel are
 * React files that a later ticket adds, so the assertions on a page pass `false` to `component()`.
 */
class PlatformAuthenticationTest extends TestCase
{
    use CreatesCompanyUser;
    use EnrollsTwoFactor;
    use InteractsWithPlatform;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // On a 30-second boundary; after enrolment the clock moves one step so the next code is fresh.
        $this->travelTo(Carbon::create(2026, 10, 19, 10, 0, 0, 'UTC'));
    }

    // ---------------------------------------------------------------------------------------------
    // Guard isolation
    // ---------------------------------------------------------------------------------------------

    public function test_a_tenant_session_cannot_reach_the_platform_and_is_sent_to_the_platform_login(): void
    {
        $tenant = $this->createCompanyUser();

        foreach (['platform.two-factor.setup', 'platform.password.confirm'] as $route) {
            $this->actingAs($tenant)->get(route($route))->assertRedirect(route('platform.login'));
        }
        $this->actingAs($tenant)->post(route('platform.logout'))->assertRedirect(route('platform.login'));
        $this->actingAs($tenant)->post(route('platform.two-factor.setup.store'))->assertRedirect(route('platform.login'));
    }

    public function test_a_platform_session_cannot_reach_the_tenant_app_and_is_sent_to_the_tenant_login(): void
    {
        $this->createCompanyUser();
        ['admin' => $admin] = $this->enrolledPlatformAdmin();
        $this->travel(30)->seconds();

        $this->signInPlatformAdmin($admin);

        foreach (['admin.dashboard', 'profile.edit', 'admin.users.index'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
        $this->assertGuest('web');
    }

    public function test_the_platform_admin_is_not_a_tenant_user_and_resolves_no_company(): void
    {
        // No createCompanyUser(): its test fallback company would answer for any non-user guard.
        Company::factory()->create();
        $admin = $this->platformAdmin();

        $this->actingAs($admin, 'platform');

        $this->assertNull(CompanyService::currentId(), 'no company context: tenant queries stay fail-closed');
        $this->assertNull(Auth::guard('web')->user());
        $this->assertDatabaseMissing('users', ['email' => $admin->email]);
        $this->assertDatabaseCount('company_user', 0);
    }

    public function test_tenant_credentials_do_not_open_the_platform_and_platform_credentials_do_not_open_the_tenant_app(): void
    {
        $tenant = $this->createCompanyUser();
        $admin = $this->platformAdmin();

        $this->post(route('platform.login.store'), ['email' => $tenant->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest('platform');

        $this->post('/login', $this->platformCredentials($admin))->assertSessionHasErrors('email');
        $this->assertGuest('web');
    }

    public function test_the_platform_runs_neither_company_resolution_nor_the_tenant_inertia_shell(): void
    {
        // A fresh install has no company at all: the tenant stack would send a signed-in user to /setup.
        $this->assertDatabaseCount('companies', 0);
        $admin = $this->platformAdmin();

        $this->get(route('platform.login'))->assertOk();

        $this->actingAs($admin, 'platform')->withSession([PlatformSession::LAST_ACTIVITY => now()->getTimestamp()])
            ->get(route('platform.two-factor.setup'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Auth/TwoFactorSetup')
                ->where('platform.admin.id', $admin->public_id)
                ->where('platform.admin.email', $admin->email)
                ->where('platform.admin.two_factor_enabled', false)
                ->where('app.locale', 'id')
                ->has('flash')
                ->missing('auth')
                ->missing('company')
                ->missing('workQueues')
                ->missing('notifications'));
    }

    public function test_each_shell_gets_only_its_own_named_routes(): void
    {
        $platformPage = $this->get(route('platform.login'))->assertOk()->getContent();
        $tenantPage = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('platform.login', $platformPage);
        $this->assertStringNotContainsString('admin.dashboard', $platformPage);
        $this->assertStringContainsString('admin.dashboard', $tenantPage);
        $this->assertStringNotContainsString('platform.login', $tenantPage);
    }

    // ---------------------------------------------------------------------------------------------
    // Login, inactive accounts, throttles
    // ---------------------------------------------------------------------------------------------

    public function test_the_login_page_renders(): void
    {
        $this->get(route('platform.login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Platform/Auth/Login')->where('status', null));
    }

    public function test_an_admin_without_two_factor_is_logged_in_but_confined_to_enrolment(): void
    {
        $admin = $this->platformAdmin();

        $this->post(route('platform.login.store'), $this->platformCredentials($admin))
            ->assertRedirect(route('platform.two-factor.setup'));

        $this->assertAuthenticatedAs($admin, 'platform');
        $this->assertGuest('web');
        $this->assertNotNull($admin->fresh()->last_login_at, 'the Login event fired');
        $this->assertIsInt(session(PlatformSession::LAST_ACTIVITY));
        $this->assertNull(session(PlatformSession::TWO_FACTOR_VERIFIED), 'this session has not proven a second factor');
        $this->get(route('platform.password.confirm'))->assertRedirect(route('platform.two-factor.setup'));
        $this->get(route('platform.two-factor.setup'))->assertOk();
    }

    public function test_an_admin_with_two_factor_is_parked_at_the_challenge_and_not_logged_in_yet(): void
    {
        ['admin' => $admin] = $this->enrolledPlatformAdmin();
        $this->travel(30)->seconds();

        $this->post(route('platform.login.store'), $this->platformCredentials($admin))
            ->assertRedirect(route('platform.two-factor.login'));

        $this->assertGuest('platform');
        $this->assertNull($admin->fresh()->last_login_at, 'the Login event has not fired');
        $this->assertSame(['id', 'remember', 'expires_at'], array_keys(session('platform.login.two_factor')));
        $this->assertNull(session('login.two_factor'), 'the tenant challenge key is untouched');
    }

    public function test_a_wrong_password_never_reaches_the_challenge(): void
    {
        ['admin' => $admin] = $this->enrolledPlatformAdmin();

        $this->post(route('platform.login.store'), $this->platformCredentials($admin, 'wrong-password-1'))
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);

        $this->assertGuest('platform');
        $this->assertNull(session('platform.login.two_factor'));
    }

    public function test_an_inactive_admin_fails_exactly_like_a_wrong_password(): void
    {
        $inactive = $this->platformAdmin(['is_active' => false]);
        $active = $this->platformAdmin();

        $this->post(route('platform.login.store'), $this->platformCredentials($inactive))
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $inactiveErrors = session('errors')->getBag('default')->toArray();

        $this->post(route('platform.login.store'), $this->platformCredentials($active, 'wrong-password-1'))
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);

        $this->assertSame(session('errors')->getBag('default')->toArray(), $inactiveErrors, 'same message for both');
        $this->assertGuest('platform');
        $this->assertNull(session('platform.login.two_factor'));
    }

    public function test_a_signed_in_admin_who_is_deactivated_meanwhile_is_ended_on_the_next_request(): void
    {
        $admin = $this->platformAdmin();
        $this->signInPlatformAdmin($admin);

        $this->get(route('platform.two-factor.setup'))->assertOk();

        $admin->forceFill(['is_active' => false])->save();
        $this->signInPlatformAdmin($admin->fresh());

        $this->get(route('platform.two-factor.setup'))->assertRedirect(route('platform.login'));
        $this->assertGuest('platform');
    }

    public function test_the_sixth_login_from_one_address_is_throttled_even_with_the_right_password(): void
    {
        $admin = $this->platformAdmin();
        Event::fake([Lockout::class]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('platform.login.store'), $this->platformCredentials($admin, 'wrong-password-1'))
                ->assertSessionHasErrors(['email' => trans('auth.failed')]);
        }

        $this->post(route('platform.login.store'), $this->platformCredentials($admin))->assertSessionHasErrors('email');
        $this->assertStringContainsString('Terlalu banyak', session('errors')->first('email'));
        $this->assertGuest('platform');
        Event::assertDispatched(Lockout::class);

        $this->travel(61)->seconds();

        $this->post(route('platform.login.store'), $this->platformCredentials($admin))->assertRedirect(route('platform.two-factor.setup'));
        $this->assertAuthenticatedAs($admin, 'platform');
    }

    public function test_the_address_limit_is_per_email_and_address(): void
    {
        $admin = $this->platformAdmin();
        $other = $this->platformAdmin();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('platform.login.store'), $this->platformCredentials($admin, 'wrong-password-1'));
        }

        $this->post(route('platform.login.store'), $this->platformCredentials($other))->assertRedirect(route('platform.two-factor.setup'));
        $this->post(route('platform.logout'));
        $this->fromAddress('203.0.113.9')
            ->post(route('platform.login.store'), $this->platformCredentials($admin))
            ->assertRedirect(route('platform.two-factor.setup'));
    }

    public function test_twenty_failures_in_an_hour_lock_the_email_across_every_address(): void
    {
        $admin = $this->platformAdmin();

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $this->fromAddress('203.0.113.'.$attempt)
                ->post(route('platform.login.store'), $this->platformCredentials($admin, 'wrong-password-1'))
                ->assertSessionHasErrors(['email' => trans('auth.failed')]);
        }

        $this->fromAddress('203.0.113.200')
            ->post(route('platform.login.store'), $this->platformCredentials($admin))
            ->assertSessionHasErrors('email');
        $this->assertStringContainsString('Terlalu banyak', session('errors')->first('email'));
        $this->assertGuest('platform');

        $this->travel(3601)->seconds();

        $this->fromAddress('203.0.113.201')
            ->post(route('platform.login.store'), $this->platformCredentials($admin))
            ->assertRedirect(route('platform.two-factor.setup'));
    }

    public function test_the_throttle_keys_ignore_the_case_of_the_email_and_a_success_clears_both_counters(): void
    {
        $admin = $this->platformAdmin(['email' => 'Operator@Example.test']);
        $addressKey = 'platform-login|operator@example.test|127.0.0.1';
        $emailKey = 'platform-login-email|operator@example.test';

        $this->post(route('platform.login.store'), ['email' => 'OPERATOR@example.TEST', 'password' => 'wrong-password-1']);
        $this->assertSame(1, RateLimiter::attempts($addressKey));
        $this->assertSame(1, RateLimiter::attempts($emailKey));

        $this->post(route('platform.login.store'), $this->platformCredentials($admin))->assertRedirect(route('platform.two-factor.setup'));

        $this->assertSame(0, RateLimiter::attempts($addressKey));
        $this->assertSame(0, RateLimiter::attempts($emailKey));
    }

    // ---------------------------------------------------------------------------------------------
    // Second factor: challenge, enrolment, per-session proof
    // ---------------------------------------------------------------------------------------------

    public function test_a_valid_code_completes_the_login_on_a_new_session_and_marks_this_session_verified(): void
    {
        ['admin' => $admin, 'secret' => $secret] = $this->enrolledPlatformAdmin();
        $this->travel(30)->seconds();
        $this->post(route('platform.login.store'), $this->platformCredentials($admin));
        $this->get(route('platform.two-factor.login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Platform/Auth/TwoFactorChallenge'));
        $parkedId = session()->getId();

        $this->post(route('platform.two-factor.login.store'), ['code' => $this->totp($secret)])
            ->assertRedirect(route('platform.dashboard', absolute: false));

        $this->assertAuthenticatedAs($admin, 'platform');
        $this->assertNotSame($parkedId, session()->getId(), 'regenerated once the challenge passed');
        $this->assertNull(session('platform.login.two_factor'), 'the parked login is gone');
        $this->assertIsInt(session(PlatformSession::TWO_FACTOR_VERIFIED));
        $this->assertNotNull($admin->fresh()->last_login_at);
    }

    public function test_a_wrong_code_is_refused_and_a_recovery_code_logs_in_once(): void
    {
        ['admin' => $admin, 'codes' => $codes] = $this->enrolledPlatformAdmin();
        $this->travel(30)->seconds();
        $this->post(route('platform.login.store'), $this->platformCredentials($admin));

        $this->post(route('platform.two-factor.login.store'), ['code' => '000000'])
            ->assertSessionHasErrors(['code' => 'Kode autentikasi dua langkah tidak valid.']);
        $this->assertGuest('platform');

        $this->post(route('platform.two-factor.login.store'), ['code' => $codes[0]])
            ->assertRedirect(route('platform.dashboard', absolute: false));
        $this->assertAuthenticatedAs($admin, 'platform');

        $this->post(route('platform.logout'));
        $this->post(route('platform.login.store'), $this->platformCredentials($admin));
        $this->post(route('platform.two-factor.login.store'), ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest('platform');
    }

    public function test_the_challenge_page_and_post_need_a_login_that_passed_the_password(): void
    {
        ['secret' => $secret] = $this->enrolledPlatformAdmin();
        $this->travel(30)->seconds();

        $this->get(route('platform.two-factor.login'))->assertRedirect(route('platform.login'));
        $this->post(route('platform.two-factor.login.store'), ['code' => $this->totp($secret)])->assertRedirect(route('platform.login'));
        $this->assertGuest('platform');
    }

    public function test_the_parked_login_expires_and_ends_when_the_admin_is_deactivated(): void
    {
        ['admin' => $admin, 'secret' => $secret] = $this->enrolledPlatformAdmin();
        $this->travel(30)->seconds();

        $this->post(route('platform.login.store'), $this->platformCredentials($admin));
        $this->travel(11)->minutes();
        $this->post(route('platform.two-factor.login.store'), ['code' => $this->totp($secret)])->assertRedirect(route('platform.login'));
        $this->assertGuest('platform');

        $this->post(route('platform.login.store'), $this->platformCredentials($admin));
        $admin->forceFill(['is_active' => false])->save();
        $this->post(route('platform.two-factor.login.store'), ['code' => $this->totp($secret, 1)])->assertRedirect(route('platform.login'));
        $this->assertGuest('platform');
    }

    public function test_a_parked_tenant_login_and_a_parked_platform_login_do_not_collide(): void
    {
        $tenant = $this->createCompanyUser();
        ['admin' => $admin, 'secret' => $platformSecret] = $this->enrolledPlatformAdmin();
        ['secret' => $tenantSecret] = $this->enrollTwoFactor($tenant);
        $this->travel(30)->seconds();

        $this->post('/login', ['email' => $tenant->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
        $this->post(route('platform.login.store'), $this->platformCredentials($admin))->assertRedirect(route('platform.two-factor.login'));

        $this->assertSame($tenant->id, session('login.two_factor.id'));
        $this->assertSame($admin->id, session('platform.login.two_factor.id'));

        $this->post(route('platform.two-factor.login.store'), ['code' => $this->totp($platformSecret)])
            ->assertRedirect(route('platform.dashboard', absolute: false));
        $this->assertAuthenticatedAs($admin, 'platform');
        $this->assertGuest('web');
        $this->assertSame($tenant->id, session('login.two_factor.id'), 'the tenant login is still waiting on its own challenge');

        $this->post(route('two-factor.login'), ['code' => $this->totp($tenantSecret)])->assertRedirect();
        $this->assertAuthenticatedAs($tenant, 'web');
    }

    public function test_an_unenrolled_session_can_enrol_and_only_then_reach_the_rest_of_the_panel(): void
    {
        $admin = $this->platformAdmin();
        $this->post(route('platform.login.store'), $this->platformCredentials($admin));

        $this->post(route('platform.two-factor.setup.store'))->assertRedirect(route('platform.two-factor.setup'));
        $page = $this->get(route('platform.two-factor.setup'));
        $page->assertInertia(fn (Assert $inertia) => $inertia
            ->component('Platform/Auth/TwoFactorSetup')
            ->where('twoFactor', ['enabled' => false, 'pending' => true, 'recovery_codes_remaining' => 0]));
        $key = $page->inertiaPage()['flash']['two_factor']['setup']['setup_key'];
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $key);
        $this->assertStringNotContainsString($key, json_encode($page->inertiaProps()), 'the key is flash data, not a prop');
        $this->get(route('platform.two-factor.setup'))->assertInertia(fn (Assert $inertia) => $inertia->missingFlash('two_factor'));

        $this->post(route('platform.two-factor.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->get(route('platform.password.confirm'))->assertRedirect(route('platform.two-factor.setup'));

        $this->post(route('platform.two-factor.confirm'), ['code' => $this->totp($key)])->assertRedirect(route('platform.two-factor.setup'));
        $reload = $this->get(route('platform.two-factor.setup'));
        $codes = $reload->inertiaPage()['flash']['two_factor']['recovery_codes'];
        $this->assertCount(8, $codes);
        $this->assertStringNotContainsString($codes[0], json_encode($reload->inertiaProps()));
        $this->get(route('platform.two-factor.setup'))->assertInertia(fn (Assert $inertia) => $inertia->missingFlash('two_factor'));

        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());
        $this->assertIsInt(session(PlatformSession::TWO_FACTOR_VERIFIED));
        $this->get(route('platform.password.confirm'))->assertOk();
    }

    public function test_a_confirmed_admin_cannot_start_a_second_enrolment(): void
    {
        ['admin' => $admin] = $this->enrolledPlatformAdmin();
        $this->travel(30)->seconds();
        $this->signInPlatformAdmin($admin);

        $this->post(route('platform.two-factor.setup.store'))->assertStatus(422);
        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());
    }

    public function test_a_session_that_never_met_the_challenge_is_ended_even_after_another_session_enrolled(): void
    {
        $admin = $this->platformAdmin();
        // Session A: signed in with the password alone, while the account had no second factor yet.
        $this->post(route('platform.login.store'), $this->platformCredentials($admin));
        $this->assertAuthenticatedAs($admin, 'platform');
        $this->get(route('platform.password.confirm'))->assertRedirect(route('platform.two-factor.setup'));

        // Session B enrols the account.
        $this->enrollTwoFactor($admin);
        $this->travel(30)->seconds();
        $this->app['auth']->forgetGuards(); // the next request is a new one: no cached user from the last

        $this->get(route('platform.password.confirm'))->assertRedirect(route('platform.login'));
        $this->assertGuest('platform');
    }

    public function test_a_session_without_the_verified_marker_is_ended_and_one_with_it_is_let_through(): void
    {
        ['admin' => $admin] = $this->enrolledPlatformAdmin();
        $this->travel(30)->seconds();

        $this->actingAs($admin, 'platform')->withSession([PlatformSession::LAST_ACTIVITY => now()->getTimestamp()])
            ->get(route('platform.password.confirm'))
            ->assertRedirect(route('platform.login'));
        $this->assertGuest('platform');

        $this->signInPlatformAdmin($admin)->get(route('platform.password.confirm'))->assertOk();
    }

    public function test_there_is_no_way_to_switch_the_platform_second_factor_off(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'platform.'));

        $this->assertNotEmpty($routes);
        // The DELETEs of the panel are the role editors' (ADR-004): a template and a role of one company. Any other would be a new way to remove something.
        $deletes = $routes->filter(fn ($route): bool => in_array('DELETE', $route->methods(), true))->map->getName()->sort()->values()->all();
        $this->assertSame(['platform.companies.roles.destroy', 'platform.role-templates.destroy'], $deletes);
        $this->assertSame([], $routes->filter(fn ($route): bool => str_contains((string) $route->getName(), 'disable'))->map->getName()->all());

        ['admin' => $admin] = $this->enrolledPlatformAdmin();

        try {
            app(TwoFactorService::class)->disable($admin);
            $this->fail('Switching the platform second factor off must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());
    }

    public function test_the_challenge_has_its_own_throttle_per_admin_and_address_and_per_admin(): void
    {
        ['admin' => $admin, 'secret' => $secret] = $this->enrolledPlatformAdmin();
        $this->travel(30)->seconds();
        $this->post(route('platform.login.store'), $this->platformCredentials($admin));

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('platform.two-factor.login.store'), ['code' => '000000'])
                ->assertSessionHasErrors(['code' => 'Kode autentikasi dua langkah tidak valid.']);
        }

        $this->assertSame(5, RateLimiter::attempts('platform-two-factor|'.$admin->id.'|127.0.0.1'));
        $this->assertSame(5, RateLimiter::attempts('platform-two-factor-admin|'.$admin->id));
        $this->assertSame(0, RateLimiter::attempts('two-factor|'.$admin->id.'|127.0.0.1'), 'the tenant keys are not touched');
        $this->assertSame(0, RateLimiter::attempts('two-factor-user|'.$admin->id));

        $this->post(route('platform.two-factor.login.store'), ['code' => $this->totp($secret)])->assertSessionHasErrors('code');
        $this->assertStringContainsString('Terlalu banyak', session('errors')->first('code'));
        $this->assertGuest('platform');

        $this->travel(61)->seconds();
        $this->post(route('platform.two-factor.login.store'), ['code' => $this->totp($secret)])
            ->assertRedirect(route('platform.dashboard', absolute: false));
    }

    public function test_ten_wrong_codes_across_addresses_lock_the_admin_and_a_tenant_user_with_the_same_number_is_unaffected(): void
    {
        $tenant = $this->createCompanyUser();
        ['admin' => $admin, 'secret' => $secret] = $this->enrolledPlatformAdmin();
        $this->travel(30)->seconds();
        $this->post(route('platform.login.store'), $this->platformCredentials($admin));

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->fromAddress('203.0.113.'.$attempt)->post(route('platform.two-factor.login.store'), ['code' => '000000']);
        }

        $this->fromAddress('203.0.113.200')->post(route('platform.two-factor.login.store'), ['code' => $this->totp($secret)])->assertSessionHasErrors('code');
        $this->assertStringContainsString('Terlalu banyak', session('errors')->first('code'));
        $this->assertSame(0, RateLimiter::attempts('two-factor-user|'.$tenant->id));
        $this->assertSame(11, RateLimiter::attempts('platform-two-factor-admin|'.$admin->id), 'the eleventh attempt was counted, then refused');
    }

    // ---------------------------------------------------------------------------------------------
    // Idle timeout
    // ---------------------------------------------------------------------------------------------

    public function test_a_session_idle_for_more_than_thirty_minutes_ends_and_is_audited(): void
    {
        $admin = $this->platformAdmin();
        $this->signInPlatformAdmin($admin, [PlatformSession::LAST_ACTIVITY => now()->getTimestamp() - 1801]);

        $this->get(route('platform.two-factor.setup'))
            ->assertRedirect(route('platform.login'))
            ->assertSessionHas('status', 'Sesi Anda telah berakhir. Silakan masuk kembali.');

        $this->assertGuest('platform');
        $row = Activity::query()->where('description', 'auth.idle_timeout')->sole();
        $this->assertSame('platform', $row->log_name);
        $this->assertSame(PlatformAdmin::class, $row->causer_type);
        $this->assertSame($admin->id, (int) $row->causer_id);
        $this->assertNull($row->company_id);
    }

    public function test_a_session_inside_the_window_is_let_through_and_its_clock_restarts(): void
    {
        $admin = $this->platformAdmin();
        $this->signInPlatformAdmin($admin, [PlatformSession::LAST_ACTIVITY => now()->getTimestamp() - 1799]);

        $this->get(route('platform.two-factor.setup'))->assertOk();
        $this->assertSame(now()->getTimestamp(), session(PlatformSession::LAST_ACTIVITY));

        $this->travel(1700)->seconds();
        $this->get(route('platform.two-factor.setup'))->assertOk();
    }

    public function test_a_session_that_never_went_through_the_login_has_no_clock_and_is_refused(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin, 'platform')->get(route('platform.two-factor.setup'))->assertRedirect(route('platform.login'));

        $this->assertGuest('platform');
    }

    // ---------------------------------------------------------------------------------------------
    // Platform password confirmation
    // ---------------------------------------------------------------------------------------------

    public function test_confirming_the_password_stamps_only_the_platform_key(): void
    {
        ['admin' => $admin] = $this->enrolledPlatformAdmin();
        $this->travel(30)->seconds();
        $this->signInPlatformAdmin($admin);

        $this->get(route('platform.password.confirm'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Platform/Auth/ConfirmPassword'));

        $this->post(route('platform.password.confirm.store'), ['password' => $this->platformPassword()])->assertRedirect();

        $this->assertSame(now()->getTimestamp(), session(PlatformSession::PASSWORD_CONFIRMED));
        $this->assertNull(session('auth.password_confirmed_at'), 'the tenant key is never written');
    }

    public function test_a_wrong_password_is_refused_with_the_platform_message_and_stamps_nothing(): void
    {
        ['admin' => $admin] = $this->enrolledPlatformAdmin();
        $this->travel(30)->seconds();
        $this->signInPlatformAdmin($admin);

        $this->post(route('platform.password.confirm.store'), ['password' => 'wrong-password-1'])
            ->assertSessionHasErrors(['password' => 'Kata sandi tidak sesuai.']);

        $this->assertNull(session(PlatformSession::PASSWORD_CONFIRMED));
    }

    public function test_the_confirmation_is_throttled_before_it_is_checked_and_cleared_by_a_success(): void
    {
        ['admin' => $admin] = $this->enrolledPlatformAdmin();
        $this->travel(30)->seconds();
        $this->signInPlatformAdmin($admin);
        $key = 'platform-password-confirm|'.$admin->id.'|127.0.0.1';

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('platform.password.confirm.store'), ['password' => 'wrong-password-1'])
                ->assertSessionHasErrors(['password' => 'Kata sandi tidak sesuai.']);
        }
        $this->assertSame(5, RateLimiter::attempts($key));

        $this->post(route('platform.password.confirm.store'), ['password' => $this->platformPassword()])->assertSessionHasErrors('password');
        $this->assertStringContainsString('Terlalu banyak', session('errors')->first('password'));
        $this->assertNull(session(PlatformSession::PASSWORD_CONFIRMED), 'the right password is not even looked at while throttled');

        $this->travel(61)->seconds();
        $this->post(route('platform.password.confirm.store'), ['password' => $this->platformPassword()])->assertSessionHasNoErrors();
        $this->assertSame(0, RateLimiter::attempts($key));
    }

    public function test_a_platform_confirmation_does_not_satisfy_the_tenant_one_and_the_other_way_round(): void
    {
        $tenant = $this->createCompanyUser();
        ['admin' => $admin] = $this->enrolledPlatformAdmin();
        $this->travel(30)->seconds();

        // Tenant route behind `password.confirm`, with only the platform key in the session.
        $this->actingAs($tenant)->withSession($this->platformPasswordConfirmed())
            ->post(route('two-factor.enable'))
            ->assertRedirect(route('password.confirm'));

        // Platform route behind the platform confirmation, with only the tenant key in the session.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->signInPlatformAdmin($admin, ['auth.password_confirmed_at' => now()->getTimestamp()])
            ->post(route('platform.two-factor.recovery-codes'))
            ->assertRedirect(route('platform.password.confirm'));
    }

    public function test_the_confirmation_covers_five_minutes_and_a_json_caller_is_told_with_423(): void
    {
        ['admin' => $admin] = $this->enrolledPlatformAdmin();
        $this->travel(30)->seconds();

        $this->signInPlatformAdmin($admin, $this->platformPasswordConfirmed(299))
            ->post(route('platform.two-factor.recovery-codes'))
            ->assertRedirect(route('platform.two-factor.setup'));

        $this->signInPlatformAdmin($admin, $this->platformPasswordConfirmed(301))
            ->post(route('platform.two-factor.recovery-codes'))
            ->assertRedirect(route('platform.password.confirm'));

        $this->signInPlatformAdmin($admin, $this->platformPasswordConfirmed(301))
            ->postJson(route('platform.two-factor.recovery-codes'))
            ->assertStatus(423);
    }

    public function test_new_recovery_codes_need_the_confirmation_and_replace_the_old_ones(): void
    {
        ['admin' => $admin, 'codes' => $old] = $this->enrolledPlatformAdmin();
        $this->travel(30)->seconds();

        $this->signInPlatformAdmin($admin)->post(route('platform.two-factor.recovery-codes'))
            ->assertRedirect(route('platform.password.confirm'));
        $this->assertSame(8, app(TwoFactorService::class)->status($admin->fresh())['recovery_codes_remaining']);

        $this->signInPlatformAdmin($admin, $this->platformPasswordConfirmed())->post(route('platform.two-factor.recovery-codes'))
            ->assertRedirect(route('platform.two-factor.setup'));
        $new = $this->get(route('platform.two-factor.setup'))->inertiaPage()['flash']['two_factor']['recovery_codes'];

        $this->assertCount(8, $new);
        $this->assertSame([], array_intersect($old, $new));
        $this->assertFalse(app(TwoFactorService::class)->verifyChallenge($admin->fresh(), $old[0]));
        $this->assertTrue(app(TwoFactorService::class)->verifyChallenge($admin->fresh(), $new[0]));
    }

    // ---------------------------------------------------------------------------------------------
    // Logout and audit
    // ---------------------------------------------------------------------------------------------

    public function test_logging_out_ends_the_platform_session_and_is_audited(): void
    {
        ['admin' => $admin] = $this->enrolledPlatformAdmin();
        $this->travel(30)->seconds();
        $this->signInPlatformAdmin($admin);
        $token = csrf_token();

        $this->post(route('platform.logout'))->assertRedirect(route('platform.login'));

        $this->assertGuest('platform');
        $this->assertNotSame($token, csrf_token(), 'a new CSRF token');
        $this->assertNull(session(PlatformSession::TWO_FACTOR_VERIFIED));
        $this->assertSame(1, Activity::query()->where('description', 'auth.logout')->where('log_name', 'platform')->count());
    }

    public function test_the_login_is_audited_under_the_platform_log_with_the_admin_as_causer_and_no_company(): void
    {
        $admin = $this->platformAdmin();

        $this->post(route('platform.login.store'), $this->platformCredentials($admin));

        $row = Activity::query()->where('description', 'auth.login')->sole();
        $this->assertSame('platform', $row->log_name);
        $this->assertSame(PlatformAdmin::class, $row->causer_type);
        $this->assertSame($admin->id, (int) $row->causer_id);
        $this->assertNull($row->company_id);
        $this->assertEquals(['platform_admin_id' => $admin->id, 'two_factor' => false, 'ip' => '127.0.0.1'], $row->properties->all());
        $this->assertStringNotContainsString($this->platformPassword(), json_encode($row->properties));
    }

    private function fromAddress(string $address): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $address]);
    }
}
