<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Core\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\EnrollsTwoFactor;

/**
 * A user managing their own second factor from the Profile page: password confirmation first, secrets
 * shown once as flash data and never again, and nothing sensitive in props, pages or the user log.
 */
class TwoFactorEnrollmentTest extends TestCase
{
    use CreatesCompanyUser;
    use EnrollsTwoFactor;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 12, 10, 0, 0, 'UTC'));
        $this->user = $this->createCompanyUser();
    }

    public function test_every_endpoint_asks_for_the_password_again_first(): void
    {
        $this->actingAs($this->user);

        foreach ([
            ['post', route('two-factor.enable')],
            ['post', route('two-factor.confirm')],
            ['post', route('two-factor.recovery-codes')],
            ['delete', route('two-factor.disable')],
        ] as [$method, $url]) {
            $this->{$method}($url, ['code' => '123456'])->assertRedirect(route('password.confirm'));
        }

        $this->assertNull($this->rawColumn('two_factor_secret'), 'nothing was started');
    }

    public function test_the_endpoints_are_for_signed_in_users_only(): void
    {
        foreach ([
            ['post', route('two-factor.enable')],
            ['post', route('two-factor.confirm')],
            ['post', route('two-factor.recovery-codes')],
            ['delete', route('two-factor.disable')],
        ] as [$method, $url]) {
            $this->{$method}($url)->assertRedirect(route('login'));
        }
    }

    public function test_after_confirming_the_password_the_user_comes_back_to_the_profile(): void
    {
        $this->actingAs($this->user)->from('/profile')->post(route('two-factor.enable'))->assertRedirect(route('password.confirm'));

        $this->post(route('password.confirm'), ['password' => 'password'])->assertRedirect('/profile');
    }

    public function test_enabling_flashes_the_qr_code_and_setup_key_once_and_leaves_it_pending(): void
    {
        $this->actingAs($this->user)->withSession($this->confirmed());

        $this->post(route('two-factor.enable'))->assertRedirect(route('profile.edit'));

        $page = $this->get(route('profile.edit'));
        $page->assertInertia(fn (Assert $inertia) => $inertia
            ->component('Profile/Edit')
            ->where('twoFactor', ['enabled' => false, 'pending' => true, 'recovery_codes_remaining' => 0])
            ->hasFlash('two_factor.setup.setup_key')
            ->hasFlash('two_factor.setup.qr_svg'));

        $key = $page->inertiaPage()['flash']['two_factor']['setup']['setup_key'];
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $key);
        $this->assertStringNotContainsString($key, json_encode($page->inertiaProps()), 'the key is flash data, not a prop');
        $this->assertFalse($this->user->fresh()->hasTwoFactorEnabled());

        $this->get(route('profile.edit'))
            ->assertInertia(fn (Assert $inertia) => $inertia->missingFlash('two_factor')->where('twoFactor.pending', true))
            ->assertDontSee($key, false);
    }

    public function test_confirming_with_the_right_code_turns_it_on_and_flashes_eight_recovery_codes_once(): void
    {
        $this->actingAs($this->user)->withSession($this->confirmed());
        $this->post(route('two-factor.enable'));
        $key = $this->get(route('profile.edit'))->inertiaPage()['flash']['two_factor']['setup']['setup_key'];

        $this->post(route('two-factor.confirm'), ['code' => $this->totp($key)])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('success');

        $page = $this->get(route('profile.edit'));
        $page->assertInertia(fn (Assert $inertia) => $inertia
            ->where('twoFactor', ['enabled' => true, 'pending' => false, 'recovery_codes_remaining' => 8])
            ->hasFlash('two_factor.recovery_codes')
            ->missingFlash('two_factor.setup'));
        $codes = $page->inertiaPage()['flash']['two_factor']['recovery_codes'];
        $this->assertCount(8, $codes);
        $this->assertStringNotContainsString($codes[0], json_encode($page->inertiaProps()));
        $this->assertTrue($this->user->fresh()->hasTwoFactorEnabled());

        // Reloading: no flash, no secret, no code anywhere in the page.
        $reload = $this->get(route('profile.edit'));
        $reload->assertInertia(fn (Assert $inertia) => $inertia->missingFlash('two_factor')->where('twoFactor.recovery_codes_remaining', 8));
        foreach ([$key, ...$codes] as $secret) {
            $reload->assertDontSee($secret, false);
        }
    }

    public function test_a_wrong_code_keeps_the_enrolment_pending_and_reveals_nothing(): void
    {
        $this->actingAs($this->user)->withSession($this->confirmed());
        $this->post(route('two-factor.enable'));

        $this->from('/profile')->post(route('two-factor.confirm'), ['code' => '000000'])
            ->assertRedirect('/profile')
            ->assertSessionHasErrors(['code' => 'Kode autentikasi dua langkah tidak valid.']);

        $this->assertFalse($this->user->fresh()->hasTwoFactorEnabled());
        $this->get(route('profile.edit'))->assertInertia(fn (Assert $inertia) => $inertia->missingFlash('two_factor.recovery_codes'));
    }

    public function test_the_confirmation_needs_a_code(): void
    {
        $this->actingAs($this->user)->withSession($this->confirmed());

        $this->post(route('two-factor.confirm'), [])->assertSessionHasErrors('code');
    }

    public function test_regenerating_flashes_new_codes_once_and_the_old_ones_stop_working(): void
    {
        ['codes' => $old] = $this->enrollTwoFactor($this->user);
        $this->actingAs($this->user)->withSession($this->confirmed());

        $this->post(route('two-factor.recovery-codes'))->assertRedirect(route('profile.edit'));

        $page = $this->get(route('profile.edit'));
        $new = $page->inertiaPage()['flash']['two_factor']['recovery_codes'];
        $this->assertCount(8, $new);
        $this->assertSame([], array_intersect($old, $new));
        $this->assertFalse(app(TwoFactorService::class)->verifyChallenge($this->user, $old[0]));
        $this->assertTrue(app(TwoFactorService::class)->verifyChallenge($this->user, $new[0]));
        $this->assertSame(1, Activity::query()->where('description', 'two_factor.recovery_codes_regenerated')->count());
    }

    public function test_disabling_clears_it_and_cancelling_a_pending_setup_does_too(): void
    {
        $this->enrollTwoFactor($this->user);
        $this->actingAs($this->user)->withSession($this->confirmed());

        $this->delete(route('two-factor.disable'))->assertRedirect(route('profile.edit'))->assertSessionHas('success');
        $this->assertNull($this->rawColumn('two_factor_secret'));
        $this->assertNull($this->rawColumn('two_factor_recovery_codes'));
        $this->assertNull($this->rawColumn('two_factor_confirmed_at'));
        $this->assertFalse($this->user->fresh()->hasTwoFactorEnabled());

        $this->post(route('two-factor.enable'));
        $this->assertNotNull($this->rawColumn('two_factor_secret'), 'a pending setup exists');
        $this->delete(route('two-factor.disable'))->assertRedirect(route('profile.edit'));
        $this->assertNull($this->rawColumn('two_factor_secret'));
    }

    public function test_enabling_again_while_it_is_on_is_refused_and_the_secret_is_kept(): void
    {
        ['secret' => $secret] = $this->enrollTwoFactor($this->user);
        $this->actingAs($this->user)->withSession($this->confirmed());

        $this->post(route('two-factor.enable'))->assertStatus(422);

        $this->travel(30)->seconds();
        $this->assertTrue(app(TwoFactorService::class)->verifyChallenge($this->user->fresh(), $this->totp($secret)));
    }

    public function test_regenerating_codes_while_it_is_off_is_refused(): void
    {
        $this->actingAs($this->user)->withSession($this->confirmed());

        $this->post(route('two-factor.recovery-codes'))->assertStatus(422);
    }

    public function test_the_profile_page_and_shared_props_never_carry_two_factor_material(): void
    {
        $this->actingAs($this->user);

        $states = [
            'off' => fn () => null,
            'pending' => fn () => app(TwoFactorService::class)->beginEnrollment($this->user)['setup_key'],
            'on' => fn () => $this->enrollTwoFactor($this->user)['secret'],
        ];

        foreach ($states as $state => $enter) {
            $secret = $enter();
            $this->user->refresh();
            $page = $this->get(route('profile.edit'))->assertOk();

            $props = json_encode($page->inertiaProps());
            foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_used_step'] as $column) {
                $this->assertStringNotContainsString($column, $props, "{$state}: {$column} in props");
            }
            if ($secret !== null) {
                $page->assertDontSee($secret, false);
            }
            $this->assertSame(['id', 'name', 'email', 'company_id', 'home_company_id', 'is_active', 'permissions'], array_keys($page->inertiaProps('auth.user')));
        }
    }

    public function test_the_events_are_audited_against_the_acting_user_without_secrets(): void
    {
        $this->actingAs($this->user)->withSession($this->confirmed());
        $this->post(route('two-factor.enable'));
        $key = $this->get(route('profile.edit'))->inertiaPage()['flash']['two_factor']['setup']['setup_key'];
        $this->post(route('two-factor.confirm'), ['code' => $this->totp($key)]);
        $codes = $this->get(route('profile.edit'))->inertiaPage()['flash']['two_factor']['recovery_codes'];
        $this->delete(route('two-factor.disable'));

        // The fixture's role grant is a security row too (role.attached); this test is about the second factor's own events.
        $rows = Activity::query()->where('log_name', 'security')->where('description', 'like', 'two_factor.%')->orderBy('id')->get();

        $this->assertSame(['two_factor.enrollment_started', 'two_factor.enabled', 'two_factor.disabled'], $rows->pluck('description')->all());
        $this->assertSame([$this->user->id], $rows->pluck('causer_id')->unique()->all());
        $serialised = $rows->map(fn (Activity $row) => json_encode($row->properties))->implode("\n");
        foreach ([$key, ...$codes] as $secret) {
            $this->assertStringNotContainsString($secret, $serialised);
        }
    }

    /** @return array<string, int> */
    private function confirmed(): array
    {
        // The middleware reads Date::now(), which the frozen test clock moves; time() would not follow it.
        return ['auth.password_confirmed_at' => now()->getTimestamp()];
    }

    private function rawColumn(string $column): mixed
    {
        return DB::table('users')->where('id', $this->user->id)->value($column);
    }
}
