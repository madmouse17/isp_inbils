<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\EnrollsTwoFactor;

/**
 * users.is_active is enforced where a session is opened: at the password step and at the two-factor step.
 * An inactive user is told exactly what a wrong password is told.
 */
class InactiveUserLoginTest extends TestCase
{
    use CreatesCompanyUser;
    use EnrollsTwoFactor;
    use RefreshDatabase;

    public function test_an_inactive_user_with_the_right_password_gets_the_same_error_as_a_wrong_password(): void
    {
        $user = $this->createCompanyUser();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $generic = session('errors')->first('email');

        $user->update(['is_active' => false]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => $generic]);

        $this->assertGuest();
        $this->assertNull($user->fresh()->last_login_at);
    }

    public function test_an_inactive_user_with_two_factor_is_not_parked_at_the_challenge(): void
    {
        $user = $this->createCompanyUser();
        $this->enrollTwoFactor($user);
        $user->update(['is_active' => false]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertNull(session('login.two_factor'));
    }

    public function test_a_user_who_is_reactivated_can_log_in_again(): void
    {
        $user = $this->createCompanyUser();
        $user->update(['is_active' => false]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');

        $user->update(['is_active' => true]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_user_deactivated_between_the_password_and_the_code_is_not_logged_in(): void
    {
        $this->travelTo(Carbon::create(2026, 10, 12, 10, 0, 0, 'UTC'));
        $user = $this->createCompanyUser();
        ['secret' => $secret] = $this->enrollTwoFactor($user);
        $this->travel(30)->seconds();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
        $step = $user->fresh()->two_factor_last_used_step;

        $user->update(['is_active' => false]);

        $this->post(route('two-factor.login'), ['code' => $this->totp($secret)])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame($step, $user->fresh()->two_factor_last_used_step, 'the code was never checked, so it is not spent');
        $this->assertSame(0, Activity::query()->where('description', 'two_factor.challenge_failed')->count());
        $this->assertNull(session('login.two_factor'), 'the parked login is dropped');
    }

    public function test_the_challenge_page_is_not_shown_to_a_user_deactivated_after_the_password_step(): void
    {
        $user = $this->createCompanyUser();
        $this->enrollTwoFactor($user);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));

        $user->update(['is_active' => false]);

        $this->get(route('two-factor.login'))->assertRedirect(route('login'));
    }
}
