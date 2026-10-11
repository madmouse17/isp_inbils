<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Core\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\EnrollsTwoFactor;

/**
 * Turning two-factor authentication on, regenerating its recovery codes, turning it off and an admin reset
 * end the user's other logins: a new remember token kills every "remember me" cookie, and with the database
 * session driver (the deployed one) the other sessions are deleted. The acting session moves to a new id and
 * the old record is destroyed, so a stolen copy of its cookie stops working too.
 */
class TwoFactorSessionRevocationTest extends TestCase
{
    use BuildsCompanyGroup;
    use CreatesCompanyUser;
    use EnrollsTwoFactor;
    use RefreshDatabase;

    private TwoFactorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 12, 10, 0, 0, 'UTC'));
        $this->service = app(TwoFactorService::class);
    }

    public function test_turning_it_on_rotates_the_remember_token_but_starting_a_setup_does_not(): void
    {
        $user = User::factory()->create();
        $original = $user->remember_token;

        $secret = $this->service->beginEnrollment($user)['setup_key'];
        $this->assertSame($original, $user->fresh()->remember_token, 'a pending setup changes nothing for other logins');

        $this->service->confirm($user, $this->totp($secret));

        $this->assertNotEmpty($user->fresh()->remember_token);
        $this->assertNotSame($original, $user->fresh()->remember_token);
    }

    public function test_regenerating_codes_turning_it_off_and_an_admin_reset_each_rotate_it(): void
    {
        $actor = User::factory()->create();
        $steps = [
            'regenerating the recovery codes' => fn (User $user) => $this->service->regenerateRecoveryCodes($user),
            'turning it off' => fn (User $user) => $this->service->disable($user),
            'an admin reset' => fn (User $user) => $this->service->adminReset($actor, $user),
        ];

        foreach ($steps as $what => $step) {
            $user = User::factory()->create();
            $this->enrollTwoFactor($user);
            $token = $user->fresh()->remember_token;

            $step($user);

            $this->assertNotSame($token, $user->fresh()->remember_token, $what);
        }
    }

    public function test_cancelling_a_pending_setup_or_resetting_a_user_without_two_factor_leaves_other_logins_alone(): void
    {
        $actor = User::factory()->create();
        $user = User::factory()->create();
        $token = $user->remember_token;

        $this->service->beginEnrollment($user);
        $this->service->disable($user);
        $this->service->adminReset($actor, $user);

        $this->assertSame($token, $user->fresh()->remember_token);
    }

    public function test_rotating_the_remember_token_writes_nothing_to_the_user_activity_log(): void
    {
        $user = User::factory()->create();
        $this->enrollTwoFactor($user);
        $logged = Activity::query()->where('log_name', 'user')->count();

        $this->service->regenerateRecoveryCodes($user);
        $this->service->disable($user);

        $this->assertSame($logged, Activity::query()->where('log_name', 'user')->count());
    }

    public function test_a_stolen_remember_me_cookie_stops_working_once_two_factor_is_turned_on(): void
    {
        $user = $this->createCompanyUser();
        $name = Auth::guard('web')->getRecallerName();
        $cookie = $user->getKey().'|'.$user->getRememberToken().'|'.$user->getAuthPassword();

        $this->withCookie($name, $cookie)->get(route('profile.edit'))->assertOk();
        $this->assertAuthenticatedAs($user);

        $this->enrollTwoFactor($user);

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->withCookie($name, $cookie)->get(route('profile.edit'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_admin_reset_ends_the_targets_remember_me_logins(): void
    {
        $this->buildGroup();
        $this->grantTwoFactorResetToAdmin();
        $admin = $this->groupUser($this->head);
        $target = $this->groupUser($this->head, 'staff');
        $this->enrollTwoFactor($target);
        $name = Auth::guard('web')->getRecallerName();
        $target = $target->fresh();
        $cookie = $target->getKey().'|'.$target->getRememberToken().'|'.$target->getAuthPassword();

        $this->withCookie($name, $cookie)->get(route('profile.edit'))->assertOk();

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($admin)->post(route('admin.users.two-factor.reset', $target))->assertRedirect();

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->withCookie($name, $cookie)->get(route('profile.edit'))->assertRedirect(route('login'));
    }

    public function test_with_the_database_session_driver_the_users_other_sessions_are_deleted(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        $bystander = User::factory()->create();
        $mine = [$this->sessionRow($user), $this->sessionRow($user)];
        $others = [$this->sessionRow($bystander), $this->sessionRow(null)];
        $handler = new ArraySessionHandler(120);
        $session = new Store('test', $handler, Str::random(40));
        $before = $session->getId();
        $handler->write($before, 'x');
        $secret = $this->service->beginEnrollment($user)['setup_key'];

        $this->service->confirm($user, $this->totp($secret), $session);

        $this->assertSame(0, DB::table('sessions')->whereIn('id', $mine)->count(), 'the user\'s other sessions are gone');
        $this->assertSame(2, DB::table('sessions')->whereIn('id', $others)->count(), 'nobody else is touched');
        $this->assertNotSame($before, $session->getId(), 'the acting session moves to a new id');
        $this->assertSame('', $handler->read($before), 'and the old record is destroyed');
    }

    public function test_an_admin_reset_deletes_all_of_the_targets_sessions_with_the_database_driver(): void
    {
        config(['session.driver' => 'database']);
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $this->enrollTwoFactor($target);
        $targets = [$this->sessionRow($target), $this->sessionRow($target)];
        $actors = $this->sessionRow($actor);

        $this->service->adminReset($actor, $target);

        $this->assertSame(0, DB::table('sessions')->whereIn('id', $targets)->count());
        $this->assertTrue(DB::table('sessions')->where('id', $actors)->exists());
    }

    public function test_other_session_drivers_cannot_be_listed_by_user_so_the_table_is_left_alone(): void
    {
        config(['session.driver' => 'file']);
        $user = User::factory()->create();
        $row = $this->sessionRow($user);

        $this->enrollTwoFactor($user);

        $this->assertTrue(DB::table('sessions')->where('id', $row)->exists());
    }

    public function test_the_profile_actions_move_the_session_to_a_new_id_and_destroy_the_old_one(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
        $secret = $this->service->beginEnrollment($user)['setup_key'];

        $this->assertMovesTheSession('confirming', fn () => $this->post(route('two-factor.confirm'), ['code' => $this->totp($secret)])
            ->assertRedirect(route('profile.edit')));
        $this->assertMovesTheSession('regenerating the recovery codes', fn () => $this->post(route('two-factor.recovery-codes'))
            ->assertRedirect(route('profile.edit')));
        $this->assertMovesTheSession('turning it off', fn () => $this->delete(route('two-factor.disable'))
            ->assertRedirect(route('profile.edit')));
    }

    /** A browser holding the session cookie `$old`: after the action that id must no longer open a session. */
    private function assertMovesTheSession(string $what, callable $action): void
    {
        $handler = app('session.store')->getHandler();
        $old = Str::random(40);
        $this->withCookie(config('session.cookie'), $old);
        $this->get(route('profile.edit'))->assertOk();
        $this->assertNotSame('', $handler->read($old), "{$what}: the page request saved a session under the cookie id");

        $action();

        $this->assertNotSame($old, session()->getId(), "{$what}: new session id");
        $this->assertSame('', $handler->read($old), "{$what}: the old id no longer opens a session");
    }

    private function sessionRow(?User $user): string
    {
        DB::table('sessions')->insert([
            'id' => $id = Str::random(40),
            'user_id' => $user?->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ]);

        return $id;
    }
}
