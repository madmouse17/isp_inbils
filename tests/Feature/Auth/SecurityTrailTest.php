<?php

namespace Tests\Feature\Auth;

use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\Core\TwoFactorService;
use App\Support\SecurityTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\EnrollsTwoFactor;
use Tests\Traits\InteractsWithMobileApi;
use Tests\Traits\InteractsWithPlatform;

/**
 * The security trail: who signed in or out, who failed to, who was locked out, whose secret changed, which
 * roles were granted or taken. Failures are counted per account and address per 15 minutes (never one row per
 * attempt), and what was typed as a secret appears in no row.
 */
class SecurityTrailTest extends TestCase
{
    use CreatesCompanyUser;
    use EnrollsTwoFactor;
    use InteractsWithMobileApi;
    use InteractsWithPlatform;
    use RefreshDatabase;

    private const TYPED_SECRET = 'typed-wrong-password-7731';

    private const NEW_SECRET = 'N3w-Passw0rd-xyz';

    protected function setUp(): void
    {
        parent::setUp();

        // On the start of a quarter hour: a counting window never ends in the middle of a test.
        $this->travelTo(Carbon::create(2026, 10, 12, 10, 0, 0, 'UTC'));
    }

    public function test_a_web_login_and_logout_are_recorded(): void
    {
        $user = $this->createCompanyUser();

        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => true])
            ->assertRedirect(route('admin.dashboard', absolute: false));
        $this->post('/logout')->assertRedirect('/');

        $login = $this->row('login');
        $this->assertSame('security', $login->log_name);
        $this->assertSame([User::class, $user->id, $user->company_id], [$login->subject_type, $login->subject_id, (int) $login->company_id]);
        $this->assertSame([User::class, $user->id], [$login->causer_type, $login->causer_id], 'the user who signs in is the one who acted');
        $this->assertEquals(['user_id' => $user->id, 'remember' => true], $login->properties->all());

        $logout = $this->row('logout');
        $this->assertSame('security', $logout->log_name);
        $this->assertSame([User::class, $user->id, $user->company_id], [$logout->subject_type, $logout->subject_id, (int) $logout->company_id]);
        $this->assertSame([User::class, $user->id], [$logout->causer_type, $logout->causer_id]);
        $this->assertEquals(['user_id' => $user->id], $logout->properties->all());
    }

    public function test_an_api_sign_in_is_its_token_row_and_not_a_web_login_row(): void
    {
        [$user] = $this->customerLogin();

        $this->api('POST', 'auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'Pixel 8', 'app' => 'customer'])->assertOk();

        $this->assertSame(0, Activity::query()->where('description', 'login')->count());
        $this->assertSame(1, Activity::query()->where('description', 'api.token_issued')->count());
    }

    public function test_failed_logins_write_one_row_per_account_and_address_per_window_and_count_the_rest(): void
    {
        $user = $this->createCompanyUser();
        $other = User::factory()->create(['company_id' => $user->company_id]);

        // The typed address is read without regard to case, so these are three failures of one account.
        foreach ([strtoupper($user->email), $user->email, $user->email] as $email) {
            $this->failedLogin($email)->assertSessionHasErrors('email');
        }

        $row = $this->row('login.failed');
        $this->assertSame('security', $row->log_name);
        $this->assertSame([User::class, $user->id, $user->company_id], [$row->subject_type, $row->subject_id, (int) $row->company_id]);
        $this->assertNull($row->causer_id, 'nobody is signed in');
        $this->assertEquals([
            'email_hash' => $this->hash($user->email),
            'channel' => 'web',
            'failures' => 3,
            'window_minutes' => 15,
        ], $row->properties->all());
        $this->assertSame(3, $row->properties['failures'], 'a number, not a string');

        // Another address, another account and the next window each get a row of their own.
        $this->fromAddress('203.0.113.9')->failedLogin($user->email);
        $this->fromAddress('127.0.0.1')->failedLogin($other->email);
        $this->travel(16)->minutes();
        $this->failedLogin($user->email);

        $this->assertSame(
            [3, 1, 1, 1],
            Activity::query()->where('description', 'login.failed')->orderBy('id')->get()->map(fn (Activity $failed): int => $failed->properties['failures'])->all(),
        );
        $this->assertStringNotContainsString(self::TYPED_SECRET, Activity::query()->get()->toJson(), 'the typed secret is in no row');
        $this->assertStringNotContainsString(strtolower($user->email), strtolower(json_encode(Activity::query()->where('description', 'login.failed')->pluck('properties'))), 'the typed address is only hashed');
    }

    public function test_a_failure_that_lands_while_the_first_row_is_written_is_counted_once_the_row_is_stored(): void
    {
        $user = $this->createCompanyUser();
        $raced = false;
        // A second failure of the account arrives after the counter was raised and before the row's id is known.
        Event::listen('eloquent.created: '.Activity::class, function () use ($user, &$raced): void {
            if (! $raced) {
                $raced = true;
                SecurityTrail::failedLogin($user->email, 'web', $user);
            }
        });

        SecurityTrail::failedLogin($user->email, 'web', $user);

        $this->assertTrue($raced);
        $this->assertSame(2, $this->row('login.failed')->properties['failures']);
    }

    public function test_the_email_hash_is_an_hmac_of_the_normalised_address_under_the_app_key(): void
    {
        $this->assertSame(hash_hmac('sha256', 'ana@example.test', config('app.key')), SecurityTrail::emailHash("  Ana@Example.TEST \n"));
        $this->assertNotSame(hash('sha256', 'ana@example.test'), SecurityTrail::emailHash('ana@example.test'), 'keyed: a bare digest of a guessed address would match');
    }

    public function test_a_failed_login_for_an_unknown_email_has_no_subject(): void
    {
        $this->failedLogin('Nobody@Example.test')->assertSessionHasErrors('email');

        $row = $this->row('login.failed');
        $this->assertSame('security', $row->log_name);
        $this->assertNull($row->subject_type);
        $this->assertNull($row->subject_id);
        $this->assertNull($row->company_id, 'no account, no tenant: the row is for forensic queries only');
        $this->assertEquals([
            'email_hash' => $this->hash('nobody@example.test'),
            'channel' => 'web',
            'failures' => 1,
            'window_minutes' => 15,
        ], $row->properties->all());
        $this->assertStringNotContainsString('nobody@example.test', strtolower($row->toJson()));
    }

    public function test_an_api_login_failure_is_recorded_with_channel_api(): void
    {
        [$user] = $this->customerLogin();

        $this->api('POST', 'auth/login', ['email' => $user->email, 'password' => self::TYPED_SECRET, 'device_name' => 'Pixel 8', 'app' => 'customer'])->assertStatus(422);

        $row = $this->row('login.failed');
        $this->assertSame('security', $row->log_name);
        $this->assertSame([User::class, $user->id], [$row->subject_type, $row->subject_id]);
        $this->assertSame('api', $row->properties['channel']);
        $this->assertSame(1, $row->properties['failures']);
        $this->assertStringNotContainsString(self::TYPED_SECRET, Activity::query()->get()->toJson());
    }

    public function test_a_platform_login_failure_goes_to_the_platform_log(): void
    {
        $admin = $this->platformAdmin();

        $this->post(route('platform.login.store'), ['email' => $admin->email, 'password' => self::TYPED_SECRET])->assertSessionHasErrors('email');
        $this->post(route('platform.login.store'), ['email' => 'ghost@example.test', 'password' => self::TYPED_SECRET])->assertSessionHasErrors('email');

        $rows = Activity::query()->where('description', 'login.failed')->orderBy('id')->get();
        $this->assertSame(['platform', 'platform'], $rows->pluck('log_name')->all(), 'the operator plane never writes a tenant `security` row');
        $this->assertSame([PlatformAdmin::class, $admin->id], [$rows[0]->subject_type, $rows[0]->subject_id]);
        $this->assertNull($rows[0]->company_id);
        $this->assertEquals([
            'email_hash' => $this->hash($admin->email),
            'channel' => 'platform',
            'failures' => 1,
            'window_minutes' => 15,
        ], $rows[0]->properties->all());
        $this->assertNull($rows[1]->subject_id, 'an unknown operator address names nobody');
        $this->assertStringNotContainsString(self::TYPED_SECRET, Activity::query()->get()->toJson());
    }

    public function test_a_platform_failure_beside_a_tenant_session_names_no_tenant_causer_or_company(): void
    {
        $tenantUser = $this->createCompanyUser();
        $admin = $this->platformAdmin();
        CompanyService::resetCache(); // drops the test-only company fallback the fixture set: only the session may name a tenant here

        $this->actingAs($tenantUser)
            ->post(route('platform.login.store'), ['email' => $admin->email, 'password' => self::TYPED_SECRET])
            ->assertSessionHasErrors('email');

        $row = $this->row('login.failed');
        $this->assertSame('platform', $row->log_name);
        $this->assertNull($row->causer_id);
        $this->assertNull($row->causer_type);
        $this->assertNull($row->company_id, 'the tenant of the signed-in user is not stamped on a platform row');
    }

    public function test_the_web_login_takes_twenty_failures_a_minute_per_address_whatever_the_email(): void
    {
        $this->freezeTime();
        $user = $this->createCompanyUser();
        foreach (range(1, 25) as $attempt) {
            $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasNoErrors();
            $this->assertAuthenticatedAs($user);
            $this->post(route('logout'));
            $this->assertGuest();
        }

        foreach (range(1, 20) as $attempt) {
            $this->failedLogin("nobody{$attempt}@example.test")->assertSessionHasErrors('email');
        }
        $rows = Activity::query()->whereIn('description', ['login.failed', 'lockout'])->count();
        $this->assertSame(20, $rows, 'twenty-five successful sign-ins spent none of the budget');

        $this->failedLogin('nobody21@example.test')->assertSessionHasErrors(['email' => trans('auth.throttle', ['seconds' => 60, 'minutes' => 1])]);

        $this->assertSame($rows, Activity::query()->whereIn('description', ['login.failed', 'lockout'])->count(), 'a refused attempt writes no row');
        $this->fromAddress('10.20.30.40')->failedLogin('nobody22@example.test')->assertSessionHasErrors('email');
        $this->assertSame($rows + 1, Activity::query()->where('description', 'login.failed')->count(), 'another address keeps its own budget');
    }

    public function test_the_platform_login_takes_twenty_failures_a_minute_per_address_whatever_the_email(): void
    {
        foreach (range(1, 20) as $attempt) {
            $this->post(route('platform.login.store'), ['email' => "ghost{$attempt}@example.test", 'password' => self::TYPED_SECRET])->assertSessionHasErrors('email');
        }
        $rows = Activity::query()->whereIn('description', ['login.failed', 'lockout'])->count();
        $this->assertSame(20, $rows);

        $this->post(route('platform.login.store'), ['email' => 'ghost21@example.test', 'password' => self::TYPED_SECRET])->assertSessionHasErrors('email');

        $this->assertSame($rows, Activity::query()->whereIn('description', ['login.failed', 'lockout'])->count(), 'a refused attempt writes no row');
    }

    public function test_a_lockout_is_recorded_once_per_window(): void
    {
        $user = $this->createCompanyUser();

        $this->lockOut($user->email);

        $this->assertSame(5, $this->row('login.failed')->properties['failures'], 'a blocked attempt is not checked, so it is no failure');
        $lockout = $this->row('lockout');
        $this->assertSame('security', $lockout->log_name);
        $this->assertNull($lockout->subject_id, 'nobody is signed in at the login form');
        $this->assertEquals(['route' => 'login', 'channel' => 'web', 'email_hash' => $this->hash($user->email)], $lockout->properties->all());

        $this->fromAddress('203.0.113.9')->lockOut($user->email);

        $this->assertSame(2, Activity::query()->where('description', 'lockout')->count(), 'one row per address, not one per blocked request');
    }

    public function test_a_lockout_on_the_api_and_the_platform_names_its_channel_and_log(): void
    {
        [$user] = $this->customerLogin();
        $admin = $this->platformAdmin();

        foreach (range(1, 6) as $attempt) {
            $this->api('POST', 'auth/login', ['email' => $user->email, 'password' => self::TYPED_SECRET, 'device_name' => 'Pixel 8', 'app' => 'customer']);
            $this->post(route('platform.login.store'), ['email' => $admin->email, 'password' => self::TYPED_SECRET]);
        }

        $api = Activity::query()->where('description', 'lockout')->where('log_name', 'security')->sole();
        $this->assertEquals(['route' => 'api.v1.auth.login', 'channel' => 'api', 'email_hash' => $this->hash($user->email)], $api->properties->all());
        $platform = Activity::query()->where('description', 'lockout')->where('log_name', 'platform')->sole();
        $this->assertEquals(['route' => 'platform.login.store', 'channel' => 'platform', 'email_hash' => $this->hash($admin->email)], $platform->properties->all());
    }

    public function test_a_lockout_of_a_signed_in_user_names_that_user(): void
    {
        $user = $this->createCompanyUser();

        foreach (range(1, 6) as $attempt) {
            $this->actingAs($user)->post('/confirm-password', ['password' => self::TYPED_SECRET]);
        }

        $row = $this->row('lockout');
        $this->assertSame([User::class, $user->id, $user->company_id], [$row->subject_type, $row->subject_id, (int) $row->company_id]);
        $this->assertEquals(['route' => 'confirm-password', 'channel' => 'web'], $row->properties->all(), 'no typed address on this step');
    }

    public function test_a_reset_request_and_a_reset_are_recorded_and_an_unknown_email_writes_nothing(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => 'nobody@example.test'])->assertSessionHasNoErrors();
        $this->assertSame(0, Activity::query()->where('log_name', 'security')->count(), 'an unknown address leaves nothing to tell it from a known one');

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();

        $requested = $this->row('password.reset_requested');
        $this->assertSame('security', $requested->log_name);
        $this->assertSame([User::class, $user->id], [$requested->subject_type, $requested->subject_id]);
        $this->assertEquals(['user_id' => $user->id], $requested->properties->all());

        $this->post('/reset-password', [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => self::NEW_SECRET,
            'password_confirmation' => self::NEW_SECRET,
        ])->assertRedirect(route('login'));

        $reset = $this->row('password.reset');
        $this->assertSame('security', $reset->log_name);
        $this->assertSame([User::class, $user->id], [$reset->subject_type, $reset->subject_id]);
        $this->assertEquals(['user_id' => $user->id], $reset->properties->all());
        $this->assertSame(1, Activity::query()->where('description', 'password.changed')->count(), 'a reset sets a new secret too');
        $this->assertStringNotContainsString(self::NEW_SECRET, Activity::query()->get()->toJson());
    }

    public function test_a_changed_secret_is_recorded_for_the_profile_and_the_admin_form(): void
    {
        $admin = $this->createCompanyUser();
        [$member] = $this->customerLogin(Company::query()->findOrFail($admin->company_id));

        // The profile form.
        $this->actingAs($member)->from('/profile')->put('/password', [
            'current_password' => 'password',
            'password' => self::NEW_SECRET,
            'password_confirmation' => self::NEW_SECRET,
        ])->assertSessionHasNoErrors();

        $profile = $this->row('password.changed');
        $this->assertSame('security', $profile->log_name);
        $this->assertSame([User::class, $member->id, $member->company_id], [$profile->subject_type, $profile->subject_id, (int) $profile->company_id]);
        $this->assertSame([User::class, $member->id], [$profile->causer_type, $profile->causer_id]);
        $this->assertEquals(['user_id' => $member->id], $profile->properties->all());

        // The admin's user form: a blank field changes nothing and records nothing.
        $this->actingAs($admin)->put(route('admin.users.update', $member), $this->userForm($member, ['password' => '']))->assertSessionHasNoErrors();
        $this->assertSame(1, Activity::query()->where('description', 'password.changed')->count());

        $this->actingAs($admin)->put(route('admin.users.update', $member), $this->userForm($member, ['password' => 'Adm1n-Set-Passw0rd']))->assertSessionHasNoErrors();

        $form = Activity::query()->where('description', 'password.changed')->orderByDesc('id')->firstOrFail();
        $this->assertSame([User::class, $member->id, $admin->id], [$form->subject_type, $form->subject_id, $form->causer_id], 'the admin acted on the member');
        $this->assertSame(2, Activity::query()->where('description', 'password.changed')->count());

        // The app's own change.
        $token = $this->issueToken($member, 'customer');
        $this->api('POST', 'me/password', [
            'current_password' => 'Adm1n-Set-Passw0rd',
            'password' => 'An0ther-Passw0rd-xyz',
            'password_confirmation' => 'An0ther-Passw0rd-xyz',
        ], $token)->assertNoContent();

        $this->assertSame(3, Activity::query()->where('description', 'password.changed')->count());
        foreach ([self::NEW_SECRET, 'Adm1n-Set-Passw0rd', 'An0ther-Passw0rd-xyz'] as $secret) {
            $this->assertStringNotContainsString($secret, Activity::query()->get()->toJson());
        }
    }

    public function test_a_totp_challenge_that_passes_is_recorded(): void
    {
        $user = $this->createCompanyUser();
        ['secret' => $secret] = $this->enrollTwoFactor($user);
        $this->travel(30)->seconds();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
        $this->assertSame(0, Activity::query()->where('description', 'login')->count(), 'nobody is signed in before the challenge passes');

        $code = $this->totp($secret);
        $this->post(route('two-factor.login'), ['code' => $code])->assertRedirect(route('admin.dashboard', absolute: false));

        $passed = $this->row('two_factor.challenge_passed');
        $this->assertSame('security', $passed->log_name);
        $this->assertSame([User::class, $user->id, $user->company_id], [$passed->subject_type, $passed->subject_id, (int) $passed->company_id]);
        $this->assertEquals(['user_id' => $user->id, 'method' => 'totp'], $passed->properties->all());
        $this->assertSame(1, Activity::query()->where('description', 'login')->count(), 'the sign-in itself is recorded once the challenge passed');
        $this->assertStringNotContainsString($code, Activity::query()->get()->toJson());

        // The operator plane writes the same event to its own log.
        ['admin' => $admin, 'secret' => $adminSecret] = $this->enrolledPlatformAdmin();
        $this->travel(30)->seconds();

        $this->assertTrue(app(TwoFactorService::class)->verifyChallenge($admin, $this->totp($adminSecret)));

        $platform = Activity::query()->where('description', 'two_factor.challenge_passed')->where('log_name', 'platform')->sole();
        $this->assertSame([PlatformAdmin::class, $admin->id, $admin->id], [$platform->subject_type, $platform->subject_id, $platform->causer_id]);
        $this->assertEquals(['platform_admin_id' => $admin->id, 'method' => 'totp'], $platform->properties->all());
    }

    public function test_role_grants_and_revocations_are_recorded_and_an_unchanged_edit_records_none(): void
    {
        $admin = $this->createCompanyUser();
        $member = User::factory()->create(['company_id' => $admin->company_id]);
        Activity::query()->delete();

        $this->actingAs($admin)->put(route('admin.users.update', $member), $this->userForm($member, ['roles' => ['staff', 'manager']]))->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('admin.users.update', $member), $this->userForm($member, ['roles' => ['manager', 'staff']]))->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('admin.users.update', $member), $this->userForm($member, ['roles' => ['staff', 'technician']]))->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('admin.users.update', $member), $this->userForm($member, ['roles' => []]))->assertSessionHasNoErrors();

        $rows = Activity::query()->where('description', 'like', 'role.%')->orderBy('id')->get();
        $this->assertSame(['role.attached', 'role.detached', 'role.attached', 'role.detached'], $rows->pluck('description')->all(), 'the unchanged edit records nothing, and no role is taken and given back');
        $this->assertSame([['manager', 'staff'], ['manager'], ['technician'], ['staff', 'technician']], $rows->map(fn (Activity $row): array => $row->properties['roles'])->all());
        foreach ($rows as $row) {
            $this->assertSame('security', $row->log_name);
            $this->assertSame([User::class, $member->id, $admin->company_id, $admin->id], [$row->subject_type, $row->subject_id, (int) $row->company_id, $row->causer_id]);
            $this->assertSame($member->id, $row->properties['user_id']);
            $this->assertEqualsCanonicalizing(['user_id', 'roles'], array_keys($row->properties->all()), 'MySQL keeps JSON keys in its own order');
        }

        // A new user's roles are a grant too; a form without roles grants nothing and records nothing.
        $this->actingAs($admin)->post(route('admin.users.store'), ['name' => 'Fresh', 'email' => 'fresh@example.test', 'password' => 'Fr3sh-Passw0rd', 'roles' => ['customer']])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.users.store'), ['name' => 'Bare', 'email' => 'bare@example.test', 'password' => 'B4re-Passw0rd', 'roles' => []])->assertSessionHasNoErrors();

        $fresh = User::query()->where('email', 'fresh@example.test')->firstOrFail();
        $granted = Activity::query()->where('description', 'role.attached')->where('subject_id', $fresh->id)->sole();
        $this->assertSame(['customer'], $granted->properties['roles']);
        $this->assertSame(0, Activity::query()->where('description', 'like', 'role.%')->where('subject_id', User::query()->where('email', 'bare@example.test')->value('id'))->count());
    }

    /** One wrong login. */
    private function failedLogin(string $email): TestResponse
    {
        return $this->post('/login', ['email' => $email, 'password' => self::TYPED_SECRET]);
    }

    /** Five wrong logins, then two more that the throttle refuses. */
    private function lockOut(string $email): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->failedLogin($email);
        }

        $this->failedLogin($email)->assertSessionHasErrors('email');
        $this->failedLogin($email)->assertSessionHasErrors('email');
    }

    private function fromAddress(string $address): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $address]);
    }

    private function row(string $description): Activity
    {
        return Activity::query()->where('description', $description)->sole();
    }

    private function hash(string $email): string
    {
        return hash_hmac('sha256', strtolower($email), config('app.key'));
    }

    /**
     * The admin's user form, as the browser sends it for $user: prefilled with the roles the user holds now.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function userForm(User $user, array $changes): array
    {
        return ['name' => $user->name, 'email' => $user->email, 'is_active' => true, 'roles' => $user->getRoleNames()->all(), ...$changes];
    }
}
