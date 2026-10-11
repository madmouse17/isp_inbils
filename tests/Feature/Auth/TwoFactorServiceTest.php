<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Core\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\EnrollsTwoFactor;

/**
 * The service on its own, with a fixed clock: TOTP window and replay guard, recovery codes,
 * and the promise that no secret reaches the activity log or a serialised user.
 */
class TwoFactorServiceTest extends TestCase
{
    use EnrollsTwoFactor;
    use RefreshDatabase;

    private TwoFactorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // A multiple of 30 seconds, so "step + n" in the tests is unambiguous.
        $this->travelTo(Carbon::create(2026, 10, 12, 10, 0, 0, 'UTC'));
        $this->service = app(TwoFactorService::class);
    }

    public function test_beginning_enrolment_returns_the_setup_key_and_qr_code_and_leaves_it_pending(): void
    {
        $user = User::factory()->create();

        $setup = $this->service->beginEnrollment($user);

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $setup['setup_key']);
        $this->assertStringStartsWith('<svg', $setup['qr_svg']);
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
        $this->assertSame(
            ['enabled' => false, 'pending' => true, 'recovery_codes_remaining' => 0],
            $this->service->status($user->fresh()),
        );

        $stored = DB::table('users')->where('id', $user->id)->value('two_factor_secret');
        $this->assertStringNotContainsString($setup['setup_key'], $stored, 'the secret must be encrypted at rest');
        $this->assertSame($setup['setup_key'], Crypt::decryptString($stored));
    }

    public function test_an_unconfirmed_enrolment_does_not_count_as_enabled_and_verifies_nothing(): void
    {
        $user = User::factory()->create();
        $secret = $this->service->beginEnrollment($user)['setup_key'];

        $this->assertFalse($this->service->verifyChallenge($user, $this->totp($secret)));
    }

    public function test_confirming_with_the_current_code_enables_it_and_returns_eight_readable_recovery_codes(): void
    {
        $user = User::factory()->create();
        $secret = $this->service->beginEnrollment($user)['setup_key'];

        $codes = $this->service->confirm($user, $this->totp($secret));

        $this->assertCount(8, $codes);
        $this->assertCount(8, array_unique($codes));
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[a-hjkmnp-z2-9]{5}-[a-hjkmnp-z2-9]{5}$/', $code);
        }
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
        $this->assertSame(
            ['enabled' => true, 'pending' => false, 'recovery_codes_remaining' => 8],
            $this->service->status($user->fresh()),
        );

        $stored = DB::table('users')->where('id', $user->id)->value('two_factor_recovery_codes');
        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, $stored, 'recovery codes must be encrypted at rest');
        }
    }

    public function test_confirming_with_a_wrong_code_keeps_the_enrolment_pending(): void
    {
        $user = User::factory()->create();
        $secret = $this->service->beginEnrollment($user)['setup_key'];

        $this->assertNull($this->service->confirm($user, $this->totp($secret, 5)));
        $this->assertNull($this->service->confirm($user, 'abcdef'));

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
        $this->assertTrue($this->service->status($user->fresh())['pending']);
    }

    public function test_confirming_without_a_pending_enrolment_does_nothing(): void
    {
        $user = User::factory()->create();

        $this->assertNull($this->service->confirm($user, '123456'));
    }

    public function test_the_code_that_confirmed_the_enrolment_cannot_be_replayed_at_login(): void
    {
        $user = User::factory()->create();
        ['secret' => $secret] = $this->enrollTwoFactor($user);

        $this->assertFalse($this->service->verifyChallenge($user, $this->totp($secret)), 'same step, already spent');
        $this->assertTrue($this->service->verifyChallenge($user, $this->totp($secret, 1)));
    }

    public function test_a_valid_code_is_accepted_once_and_a_replay_is_rejected(): void
    {
        // Mutation check: handing verifyKeyNewer() a last step of 0 instead of two_factor_last_used_step
        // (TwoFactorService::matchingStep) makes this test, the confirm-replay test and the
        // "older than the last accepted step" assertion below fail.
        $user = User::factory()->create();
        ['secret' => $secret] = $this->enrollTwoFactor($user);
        $this->travel(30)->seconds();
        $code = $this->totp($secret);

        $this->assertTrue($this->service->verifyChallenge($user, $code));
        $this->assertFalse($this->service->verifyChallenge($user, $code), 'the replay guard');
        $this->assertFalse($this->service->verifyChallenge($user->fresh(), $code), 'also from a fresh model');
    }

    public function test_codes_one_step_either_side_are_accepted_and_two_steps_away_are_not(): void
    {
        $user = User::factory()->create();
        ['secret' => $secret] = $this->enrollTwoFactor($user);
        $this->travel(90)->seconds(); // three steps on: the window is now enrolment step + 2 .. + 4

        $this->assertFalse($this->service->verifyChallenge($user, $this->totp($secret, -2)), 'two steps behind');
        $this->assertFalse($this->service->verifyChallenge($user, $this->totp($secret, 2)), 'two steps ahead');
        $this->assertTrue($this->service->verifyChallenge($user, $this->totp($secret, -1)), 'one step behind');
        $this->assertTrue($this->service->verifyChallenge($user, $this->totp($secret, 1)), 'one step ahead');
        $this->assertFalse($this->service->verifyChallenge($user, $this->totp($secret)), 'older than the last accepted step');
    }

    public function test_a_code_with_spaces_and_a_wrong_length_are_handled(): void
    {
        $user = User::factory()->create();
        ['secret' => $secret] = $this->enrollTwoFactor($user);
        $this->travel(30)->seconds();
        $code = $this->totp($secret);

        $this->assertFalse($this->service->verifyChallenge($user, substr($code, 0, 5)));
        $this->assertFalse($this->service->verifyChallenge($user, $code.'0'));
        $this->assertTrue($this->service->verifyChallenge($user, substr($code, 0, 3).' '.substr($code, 3)));
    }

    public function test_a_recovery_code_works_exactly_once(): void
    {
        $user = User::factory()->create();
        ['codes' => $codes] = $this->enrollTwoFactor($user);

        $this->assertTrue($this->service->verifyChallenge($user, $codes[0]));
        $this->assertFalse($this->service->verifyChallenge($user, $codes[0]));
        $this->assertSame(7, $this->service->status($user->fresh())['recovery_codes_remaining']);
        $this->assertTrue($this->service->verifyChallenge($user, $codes[1]), 'the others still work');
    }

    public function test_a_recovery_code_is_accepted_in_capitals_and_without_the_hyphen(): void
    {
        $user = User::factory()->create();
        ['codes' => $codes] = $this->enrollTwoFactor($user);

        $this->assertTrue($this->service->verifyChallenge($user, strtoupper(str_replace('-', '', $codes[3]))));
    }

    public function test_a_wrong_recovery_code_is_rejected_and_consumes_nothing(): void
    {
        $user = User::factory()->create();
        $this->enrollTwoFactor($user);

        $this->assertFalse($this->service->verifyChallenge($user, 'aaaaa-aaaaa'));
        $this->assertFalse($this->service->verifyChallenge($user, ''));
        $this->assertSame(8, $this->service->status($user->fresh())['recovery_codes_remaining']);
    }

    public function test_regenerating_recovery_codes_invalidates_the_old_ones(): void
    {
        $user = User::factory()->create();
        ['codes' => $old] = $this->enrollTwoFactor($user);

        $new = $this->service->regenerateRecoveryCodes($user);

        $this->assertCount(8, $new);
        $this->assertSame([], array_intersect($old, $new));
        $this->assertFalse($this->service->verifyChallenge($user, $old[0]));
        $this->assertTrue($this->service->verifyChallenge($user, $new[0]));
    }

    public function test_regenerating_recovery_codes_needs_two_factor_to_be_on(): void
    {
        $user = User::factory()->create();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('not enabled');

        $this->service->regenerateRecoveryCodes($user);
    }

    public function test_enrolment_cannot_be_restarted_while_two_factor_is_on(): void
    {
        $user = User::factory()->create();
        ['secret' => $secret] = $this->enrollTwoFactor($user);

        try {
            $this->service->beginEnrollment($user);
            $this->fail('A second enrolment should be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertTrue($this->service->verifyChallenge($user, $this->totp($secret, 1)), 'the original secret still works');
    }

    public function test_restarting_a_pending_enrolment_replaces_the_secret(): void
    {
        $user = User::factory()->create();
        $first = $this->service->beginEnrollment($user)['setup_key'];
        $second = $this->service->beginEnrollment($user)['setup_key'];

        $this->assertNotSame($first, $second);
        $this->assertNull($this->service->confirm($user, $this->totp($first)), 'the old QR code is dead');
        $this->assertNotNull($this->service->confirm($user, $this->totp($second)));
    }

    public function test_disable_clears_every_two_factor_column(): void
    {
        $user = User::factory()->create();
        $this->enrollTwoFactor($user);

        $this->service->disable($user);

        $row = DB::table('users')->where('id', $user->id)->first();
        $this->assertNull($row->two_factor_secret);
        $this->assertNull($row->two_factor_recovery_codes);
        $this->assertNull($row->two_factor_confirmed_at);
        $this->assertNull($row->two_factor_last_used_step);
        $this->assertFalse($user->hasTwoFactorEnabled());
    }

    public function test_the_challenge_waits_in_the_session_and_expires_or_ends_with_two_factor(): void
    {
        $user = User::factory()->create();
        $this->enrollTwoFactor($user);
        $session = app('session.store');

        $this->service->startChallenge($session, $user, remember: true);
        $pending = $this->service->pendingChallenge($session);
        $this->assertTrue($pending['user']->is($user));
        $this->assertTrue($pending['remember']);

        $this->travel(11)->minutes();
        $this->assertNull($this->service->pendingChallenge($session), 'expired');
        $this->assertNull($session->get('login.two_factor'), 'and forgotten');

        $this->service->startChallenge($session, $user, remember: false);
        $this->service->disable($user);
        $this->assertNull($this->service->pendingChallenge($session), 'two-factor was turned off in the meantime');
    }

    public function test_every_event_is_audited_under_security_with_ids_and_no_secret_material(): void
    {
        $user = User::factory()->create();
        $actor = User::factory()->create();

        $secret = $this->service->beginEnrollment($user)['setup_key'];
        $codes = $this->service->confirm($user, $this->totp($secret));
        $this->travel(30)->seconds();
        $this->service->verifyChallenge($user, '000000');
        $this->service->verifyChallenge($user, $codes[0]);
        $regenerated = $this->service->regenerateRecoveryCodes($user);
        $this->service->adminReset($actor, $user);
        $this->service->beginEnrollment($user);
        $this->service->disable($user);

        $rows = Activity::query()->where('log_name', 'security')->orderBy('id')->get();

        $this->assertSame([
            'two_factor.enrollment_started',
            'two_factor.enabled',
            'two_factor.challenge_failed',
            'two_factor.recovery_code_used',
            'two_factor.recovery_codes_regenerated',
            'two_factor.admin_reset',
            'two_factor.enrollment_started',
            'two_factor.disabled',
        ], $rows->pluck('description')->all());

        $serialised = $rows->map(fn (Activity $row) => json_encode($row->properties))->implode("\n");
        foreach ([$secret, ...$codes, ...$regenerated] as $material) {
            $this->assertStringNotContainsString($material, $serialised);
        }
        foreach ($rows as $row) {
            $this->assertSame($user->id, $row->subject_id);
            $this->assertEmpty(array_diff(array_keys($row->properties->all()), ['user_id', 'actor_id', 'method', 'remaining', 'company_id']));
        }
        $this->assertSame($actor->id, $rows->firstWhere('description', 'two_factor.admin_reset')->properties['actor_id']);
    }

    public function test_the_two_factor_columns_never_reach_the_user_activity_log(): void
    {
        // Mutation check: removing the two_factor_* entries from User::getActivitylogOptions()->logExcept()
        // makes this fail, because spatie then logs the encrypted secret and codes as dirty attributes.
        $user = User::factory()->create();
        $this->enrollTwoFactor($user);
        $user->refresh()->update(['name' => 'Renamed while two-factor is on']);
        $this->service->regenerateRecoveryCodes($user);
        $this->service->disable($user);

        $logged = Activity::query()->where('log_name', 'user')->get()->map(fn (Activity $row) => json_encode($row->properties))->implode("\n");

        $this->assertStringContainsString('Renamed while two-factor is on', $logged, 'the user log itself still works');
        $this->assertStringNotContainsString('two_factor', $logged);
    }

    public function test_a_serialised_user_carries_no_two_factor_columns(): void
    {
        $user = User::factory()->create();
        ['secret' => $secret, 'codes' => $codes] = $this->enrollTwoFactor($user);
        $user->refresh();

        foreach ([$user->toArray(), json_decode($user->toJson(), true)] as $serialised) {
            foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_used_step'] as $column) {
                $this->assertArrayNotHasKey($column, $serialised);
            }
        }
        $this->assertStringNotContainsString($secret, $user->toJson());
        $this->assertStringNotContainsString($codes[0], $user->toJson());
    }
}
