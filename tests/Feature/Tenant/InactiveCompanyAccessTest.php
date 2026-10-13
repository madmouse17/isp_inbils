<?php

namespace Tests\Feature\Tenant;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;
use Tests\Traits\EnrollsTwoFactor;
use Tests\Traits\InteractsWithMobileApi;

/**
 * companies.is_active is enforced where a user acts, not only in scheduled jobs and the company switcher:
 * at login, on every web request and on every API call. A suspension also revokes the app tokens of the
 * users it leaves without any active company.
 */
class InactiveCompanyAccessTest extends TestCase
{
    use BuildsCompanyGroup;
    use EnrollsTwoFactor;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private const MESSAGE = 'Akun perusahaan Anda sedang ditangguhkan. Hubungi dukungan.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGroup();
        CompanyService::resetCache();
    }

    /** A bulk update skips the model events, the way a writer that bypasses the model would. */
    private function suspendWithoutEvents(Company $company): void
    {
        Company::query()->whereKey($company->id)->update(['is_active' => false]);
    }

    private function login(User $user): TestResponse
    {
        return $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    }

    // ---------------------------------------------------------------------------------------------
    // Web login
    // ---------------------------------------------------------------------------------------------

    public function test_a_user_whose_only_company_is_inactive_cannot_log_in(): void
    {
        $user = $this->groupUser($this->subA);
        $this->suspendWithoutEvents($this->subA);

        $this->login($user)->assertSessionHasErrors(['email' => self::MESSAGE]);

        $this->assertGuest();
        $this->assertNull($user->fresh()->last_login_at);
    }

    public function test_a_user_with_another_active_membership_logs_in_and_lands_there(): void
    {
        $user = $this->groupUser($this->subA, 'admin', $this->subB);
        $this->suspendWithoutEvents($this->subA);

        $this->login($user)->assertRedirect(route('admin.dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertSame($this->subB->id, session(CompanyService::SESSION_KEY));
        $this->get(route('admin.dashboard'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('company.id', $this->subB->id));
    }

    public function test_a_user_of_an_active_company_still_logs_in_to_the_home_company(): void
    {
        $user = $this->groupUser($this->subA, 'admin', $this->subB);

        $this->login($user)->assertRedirect(route('admin.dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertNull(session(CompanyService::SESSION_KEY));
    }

    // ---------------------------------------------------------------------------------------------
    // Web requests of a session that is already open
    // ---------------------------------------------------------------------------------------------

    public function test_a_session_falls_back_to_another_active_membership_when_its_company_is_suspended(): void
    {
        $user = $this->groupUser($this->subA, 'admin', $this->subB);

        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('company.id', $this->subA->id));

        $this->suspendWithoutEvents($this->subA);

        $this->get(route('admin.dashboard'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('company.id', $this->subB->id));
        $this->assertSame($this->subB->id, session(CompanyService::SESSION_KEY));
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_session_is_logged_out_when_its_only_company_is_suspended(): void
    {
        $user = $this->groupUser($this->subA);

        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();

        $this->suspendWithoutEvents($this->subA);

        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => self::MESSAGE]);
        $this->assertGuest();
    }

    public function test_a_forced_sign_out_answers_a_put_with_a_303_so_inertia_lands_on_the_login(): void
    {
        $user = $this->groupUser($this->subA);
        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();
        $this->suspendWithoutEvents($this->subA);

        $this->put(route('password.update'), [], ['X-Inertia' => 'true'])
            ->assertStatus(303)
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_company_suspended_between_the_password_and_the_two_factor_code_opens_no_session(): void
    {
        $this->travelTo(Carbon::create(2026, 10, 12, 10, 0, 0, 'UTC'));
        $user = $this->groupUser($this->subA);
        ['secret' => $secret] = $this->enrollTwoFactor($user);
        $this->travel(30)->seconds();
        $this->login($user)->assertRedirect(route('two-factor.login'));

        $this->suspendWithoutEvents($this->subA);

        $this->post(route('two-factor.login'), ['code' => $this->totp($secret)])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => self::MESSAGE]);

        $this->assertGuest();
        $this->assertNull($user->fresh()->last_login_at);
    }

    public function test_a_switched_session_whose_company_is_suspended_with_no_active_home_is_logged_out(): void
    {
        $user = $this->groupUser($this->subA, 'admin', $this->subB);
        $this->actingAs($user)->post(route('admin.company.switch'), ['company_id' => $this->subB->id]);

        $this->suspendWithoutEvents($this->subA);
        $this->suspendWithoutEvents($this->subB);

        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // ---------------------------------------------------------------------------------------------
    // Mobile API
    // ---------------------------------------------------------------------------------------------

    public function test_an_api_call_of_a_user_in_a_suspended_company_is_a_403_company_inactive(): void
    {
        [$user] = $this->customerLogin($this->subA);
        $token = $this->issueToken($user, 'customer');
        $this->api('GET', 'me', token: $token)->assertOk();

        $this->suspendWithoutEvents($this->subA);

        $this->api('GET', 'me', token: $token)->assertStatus(403)->assertJsonPath('code', 'company_inactive');
    }

    public function test_an_api_login_in_a_suspended_company_gets_no_token(): void
    {
        [$user] = $this->customerLogin($this->subA);
        $this->suspendWithoutEvents($this->subA);

        $this->api('POST', 'auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'Pixel 8',
            'app' => 'customer',
        ])->assertStatus(422);

        $this->assertSame(0, $user->tokens()->count());
    }

    // ---------------------------------------------------------------------------------------------
    // Token revocation on suspension
    // ---------------------------------------------------------------------------------------------

    public function test_suspending_a_company_revokes_tokens_only_of_users_left_without_an_active_company(): void
    {
        [$sole] = $this->customerLogin($this->subA);
        [$spare] = $this->customerLogin($this->subA);
        app(CompanyMembershipService::class)->grant($spare, $this->subB);
        [$homeElsewhere] = $this->customerLogin($this->subB);
        app(CompanyMembershipService::class)->grant($homeElsewhere, $this->subA);
        [$bystander] = $this->customerLogin($this->subB);

        $tokens = [];
        foreach ([$sole, $spare, $homeElsewhere, $bystander] as $user) {
            $tokens[$user->id] = $this->issueToken($user, 'customer');
        }

        $this->subA->update(['is_active' => false]);

        $this->assertSame(0, $sole->tokens()->count());
        $this->assertSame(1, $spare->tokens()->count());
        $this->assertSame(1, $homeElsewhere->tokens()->count());
        $this->assertSame(1, $bystander->tokens()->count());

        $audit = Activity::query()->where('description', 'api.tokens_revoked')->sole();
        $this->assertSame('security', $audit->log_name);
        $this->assertSame($sole->id, $audit->subject_id);
        $this->assertSame('company_suspended', $audit->properties['reason']);
        $this->assertSame(1, $audit->properties['count']);
    }

    public function test_other_company_edits_and_reactivation_revoke_nothing(): void
    {
        [$user] = $this->customerLogin($this->subA);
        $this->issueToken($user, 'customer');

        $this->subA->update(['name' => 'Renamed']);
        $this->assertSame(1, $user->tokens()->count());

        $this->subA->update(['is_active' => false]);
        $this->assertSame(0, $user->tokens()->count());

        $this->subA->update(['is_active' => true]);
        $this->issueToken($user, 'customer');
        $this->subA->update(['code' => 'SBX']);

        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame(1, Activity::query()->where('description', 'api.tokens_revoked')->count());
    }
}
