<?php

namespace Tests\Feature\Platform\Auth;

use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Core\TwoFactorService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\EnrollsTwoFactor;
use Tests\Traits\InteractsWithPlatform;

/**
 * TwoFactorService for a platform admin: the same TOTP rules as for a tenant user (encrypted at rest, replay
 * guard, recovery codes spent once), and none of the tenant side effects, because the two ids belong to different
 * tables: no tenant token, session or remember token is touched, and every event is a `platform` row.
 */
class PlatformTwoFactorTest extends TestCase
{
    use EnrollsTwoFactor;
    use InteractsWithPlatform;
    use RefreshDatabase;

    private TwoFactorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 19, 10, 0, 0, 'UTC'));
        $this->service = app(TwoFactorService::class);
    }

    public function test_the_secret_and_the_recovery_codes_are_encrypted_at_rest_and_never_serialised(): void
    {
        $admin = $this->platformAdmin();

        $setup = $this->service->beginEnrollment($admin);
        $codes = $this->service->confirm($admin, $this->totp($setup['setup_key']));

        $row = DB::table('platform_admins')->where('id', $admin->id)->first();
        $this->assertStringNotContainsString($setup['setup_key'], $row->two_factor_secret);
        $this->assertSame($setup['setup_key'], Crypt::decryptString($row->two_factor_secret));
        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, $row->two_factor_recovery_codes);
        }
        $this->assertStringStartsWith('<svg', $setup['qr_svg']);

        $admin->refresh();
        foreach ([$admin->toArray(), json_decode($admin->toJson(), true)] as $serialised) {
            foreach (['password', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_used_step'] as $column) {
                $this->assertArrayNotHasKey($column, $serialised);
            }
        }
    }

    public function test_the_authenticator_entry_is_named_for_the_platform(): void
    {
        $captured = [];
        $spy = new class() extends Google2FA
        {
            public ?Closure $onQrCode = null;

            public function getQRCodeUrl($company, $holder, $secret)
            {
                ($this->onQrCode)($company, $holder);

                return parent::getQRCodeUrl($company, $holder, $secret);
            }
        };
        $spy->onQrCode = function (string $issuer, string $holder) use (&$captured): void {
            $captured[] = [$issuer, $holder];
        };
        $this->app->instance(Google2FA::class, $spy);
        $admin = $this->platformAdmin();
        $user = User::factory()->create();

        app(TwoFactorService::class)->beginEnrollment($admin);
        app(TwoFactorService::class)->beginEnrollment($user);

        $this->assertSame([[config('app.name').' Platform', $admin->email], [config('app.name'), $user->email]], $captured);
    }

    public function test_a_code_is_accepted_once_within_the_window_and_a_replay_is_refused(): void
    {
        ['admin' => $admin, 'secret' => $secret] = $this->enrolledPlatformAdmin();

        $this->assertFalse($this->service->verifyChallenge($admin, $this->totp($secret)), 'the confirming code is spent');

        $this->travel(30)->seconds();
        $code = $this->totp($secret);

        $this->assertTrue($this->service->verifyChallenge($admin, $code));
        $this->assertFalse($this->service->verifyChallenge($admin, $code), 'the replay guard');
        $this->assertFalse($this->service->verifyChallenge($admin->fresh(), $code), 'also from a fresh model');
        $this->assertFalse($this->service->verifyChallenge($admin, $this->totp($secret, -2)), 'outside the window');
        $this->assertTrue($this->service->verifyChallenge($admin, $this->totp($secret, 1)), 'one step ahead');
    }

    public function test_an_unconfirmed_enrolment_verifies_nothing_and_a_wrong_code_keeps_it_pending(): void
    {
        $admin = $this->platformAdmin();
        $secret = $this->service->beginEnrollment($admin)['setup_key'];

        $this->assertFalse($this->service->verifyChallenge($admin, $this->totp($secret)));
        $this->assertNull($this->service->confirm($admin, '000000'));
        $this->assertSame(
            ['enabled' => false, 'pending' => true, 'recovery_codes_remaining' => 0],
            $this->service->status($admin->fresh()),
        );

        $this->assertNotNull($this->service->confirm($admin, $this->totp($secret)));
        $this->assertSame(
            ['enabled' => true, 'pending' => false, 'recovery_codes_remaining' => 8],
            $this->service->status($admin->fresh()),
        );
    }

    public function test_a_recovery_code_works_exactly_once_and_regenerating_replaces_them_all(): void
    {
        ['admin' => $admin, 'codes' => $codes] = $this->enrolledPlatformAdmin();

        $this->assertTrue($this->service->verifyChallenge($admin, strtoupper(str_replace('-', '', $codes[0]))));
        $this->assertFalse($this->service->verifyChallenge($admin, $codes[0]));
        $this->assertSame(7, $this->service->status($admin->fresh())['recovery_codes_remaining']);

        $new = $this->service->regenerateRecoveryCodes($admin);

        $this->assertCount(8, $new);
        $this->assertSame([], array_intersect($codes, $new));
        $this->assertFalse($this->service->verifyChallenge($admin, $codes[1]));
        $this->assertTrue($this->service->verifyChallenge($admin, $new[0]));
    }

    public function test_the_challenge_waits_under_its_own_session_key_and_the_two_planes_do_not_see_each_other(): void
    {
        ['admin' => $admin] = $this->enrolledPlatformAdmin();
        $user = User::factory()->create();
        $this->enrollTwoFactor($user);
        $session = app('session.store');

        $this->service->startChallenge($session, $admin, remember: false);

        $this->assertNull($session->get('login.two_factor'), 'the tenant key is not used');
        $this->assertSame($admin->id, $session->get('platform.login.two_factor.id'));
        $this->assertNull($this->service->pendingChallenge($session), 'the tenant plane finds nothing');
        $this->assertTrue($this->service->pendingChallenge($session, PlatformAdmin::class)['user']->is($admin));

        $this->service->startChallenge($session, $user, remember: false);
        $this->assertTrue($this->service->pendingChallenge($session)['user']->is($user));
        $this->assertTrue($this->service->pendingChallenge($session, PlatformAdmin::class)['user']->is($admin), 'both can wait at once');

        $this->service->abandonChallenge($session, PlatformAdmin::class);
        $this->assertNull($this->service->pendingChallenge($session, PlatformAdmin::class));
        $this->assertTrue($this->service->pendingChallenge($session)['user']->is($user), 'abandoning one leaves the other');

        $this->travel(11)->minutes();
        $this->assertNull($this->service->pendingChallenge($session), 'expired');
    }

    public function test_a_platform_change_never_touches_the_tokens_or_sessions_of_a_tenant_user_with_the_same_number(): void
    {
        config(['session.driver' => 'database']);
        $admin = $this->platformAdmin();
        $tenant = User::factory()->create(['id' => $admin->id]);
        $tenant->createToken('phone', ['customer']);
        $tenant->createToken('tablet', ['customer']);
        $rememberToken = $tenant->fresh()->remember_token;
        $sessionRow = $this->sessionRow($tenant);
        $handler = new ArraySessionHandler(120);
        $session = new Store('test', $handler, Str::random(40));
        $before = $session->getId();
        $handler->write($before, 'x');

        $secret = $this->service->beginEnrollment($admin)['setup_key'];
        $this->service->confirm($admin, $this->totp($secret), $session);
        $this->service->regenerateRecoveryCodes($admin, $session);

        $this->assertSame($admin->id, $tenant->id, 'the fixture really collides');
        $this->assertSame(2, $tenant->tokens()->count(), 'no tenant token is revoked');
        $this->assertTrue(DB::table('sessions')->where('id', $sessionRow)->exists(), 'no tenant session is deleted');
        $this->assertSame($rememberToken, $tenant->fresh()->remember_token, 'no tenant remember token is rotated');
        $this->assertNotSame($before, $session->getId(), 'the acting session still moves to a new id');
        $this->assertSame('', $handler->read($before), 'and the old record is destroyed');
        $this->assertSame(0, Activity::query()->where('log_name', 'security')->count(), 'no tenant security row either');
    }

    public function test_every_event_is_a_platform_row_caused_by_the_admin_with_no_company_and_no_secret_material(): void
    {
        $admin = $this->platformAdmin();

        $secret = $this->service->beginEnrollment($admin)['setup_key'];
        $codes = $this->service->confirm($admin, $this->totp($secret));
        $this->travel(30)->seconds();
        $this->service->verifyChallenge($admin, '000000');
        $this->service->verifyChallenge($admin, $codes[0]);
        $regenerated = $this->service->regenerateRecoveryCodes($admin);

        $rows = Activity::query()->orderBy('id')->get();

        $this->assertSame([
            'two_factor.enrollment_started',
            'two_factor.enabled',
            'two_factor.challenge_failed',
            'two_factor.recovery_code_used',
            'two_factor.recovery_codes_regenerated',
        ], $rows->pluck('description')->all());
        $serialised = $rows->map(fn (Activity $row) => json_encode($row->properties))->implode("\n");
        foreach ([$secret, ...$codes, ...$regenerated] as $material) {
            $this->assertStringNotContainsString($material, $serialised);
        }
        foreach ($rows as $row) {
            $this->assertSame('platform', $row->log_name);
            $this->assertSame(PlatformAdmin::class, $row->causer_type);
            $this->assertSame($admin->id, (int) $row->causer_id);
            $this->assertSame(PlatformAdmin::class, $row->subject_type);
            $this->assertSame($admin->id, (int) $row->subject_id);
            $this->assertNull($row->company_id);
            $this->assertSame($admin->id, $row->properties['platform_admin_id']);
            $this->assertEmpty(array_diff(array_keys($row->properties->all()), ['platform_admin_id', 'method', 'remaining']));
        }
    }

    public function test_a_tenant_users_events_stay_security_rows_and_a_platform_reset_of_a_tenant_user_names_its_actor_type(): void
    {
        $user = User::factory()->create();
        $platformActor = $this->platformAdmin();
        $tenantActor = User::factory()->create();

        $this->service->beginEnrollment($user);
        $this->service->adminReset($tenantActor, $user);
        $this->service->adminReset($platformActor, $user);

        $this->assertSame(1, Activity::query()->where('log_name', 'security')->where('description', 'two_factor.enrollment_started')->count());
        $resets = Activity::query()->where('description', 'two_factor.admin_reset')->orderBy('id')->get();
        $this->assertSame(['security', 'security'], $resets->pluck('log_name')->all());
        $this->assertSame(['user' => $tenantActor->id, 'platform_admin' => $platformActor->id], $resets->mapWithKeys(fn (Activity $row): array => [$row->properties['actor_type'] => $row->properties['actor_id']])->all());
        $this->assertSame([User::class, PlatformAdmin::class], $resets->pluck('causer_type')->all());
    }

    private function sessionRow(User $user): string
    {
        DB::table('sessions')->insert([
            'id' => $id = Str::random(40),
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ]);

        return $id;
    }
}
