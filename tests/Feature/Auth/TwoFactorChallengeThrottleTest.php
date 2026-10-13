<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Core\TwoFactorService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\EnrollsTwoFactor;

/**
 * The login challenge's brute-force limits (docs/SECURITY.md section 3.1): every attempt is counted before
 * the code is looked at, per user and address and, across every address, per user.
 */
class TwoFactorChallengeThrottleTest extends TestCase
{
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

        $this->travelTo(Carbon::create(2026, 10, 12, 10, 0, 0, 'UTC'));
        $this->user = $this->createCompanyUser();
        ['secret' => $this->secret, 'codes' => $this->codes] = $this->enrollTwoFactor($this->user);
        $this->travel(30)->seconds();

        $this->post('/login', ['email' => $this->user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.login'));
    }

    public function test_every_attempt_is_counted_before_the_code_is_checked(): void
    {
        // Counting after the check, which waits on a row lock, lets a burst of parallel attempts all pass the
        // "too many?" test before the first one is counted: N guesses instead of the allowed few.
        $counted = null;
        $spy = new class() extends Google2FA
        {
            public ?Closure $beforeVerify = null;

            public function verifyKeyNewer($secret, $key, $oldTimestamp, $window = null, $timestamp = null)
            {
                ($this->beforeVerify)();

                return parent::verifyKeyNewer($secret, $key, $oldTimestamp, $window, $timestamp);
            }
        };
        $spy->beforeVerify = function () use (&$counted): void {
            $counted = [RateLimiter::attempts($this->addressKey()), RateLimiter::attempts($this->userKey())];
        };
        $this->app->instance(Google2FA::class, $spy);

        $this->post(route('two-factor.login'), ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertSame([1, 1], $counted, 'both counters include this attempt when the code is checked');
    }

    public function test_the_account_is_locked_across_every_address_after_ten_wrong_codes(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->fromAddress("203.0.113.{$attempt}")
                ->post(route('two-factor.login'), ['code' => '000000'])
                ->assertSessionHasErrors(['code' => 'Kode autentikasi dua langkah tidak valid.']);
        }

        $this->fromAddress('203.0.113.200')
            ->post(route('two-factor.login'), ['code' => $this->totp($this->secret)])
            ->assertSessionHasErrors('code');
        $this->assertStringContainsString('Terlalu banyak', session('errors')->first('code'));
        $this->assertGuest();

        // A new window: the parked login lapsed meanwhile, so the password step is repeated.
        $this->travel(16)->minutes();
        $this->post('/login', ['email' => $this->user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.login'));
        $this->fromAddress('203.0.113.201')
            ->post(route('two-factor.login'), ['code' => $this->totp($this->secret)])
            ->assertRedirect(route('admin.dashboard', absolute: false));
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_a_passing_code_clears_both_counters(): void
    {
        $this->post(route('two-factor.login'), ['code' => '000000']);
        $this->assertSame(1, RateLimiter::attempts($this->userKey()));

        $this->post(route('two-factor.login'), ['code' => $this->totp($this->secret)])
            ->assertRedirect(route('admin.dashboard', absolute: false));

        $this->assertSame(0, RateLimiter::attempts($this->addressKey()));
        $this->assertSame(0, RateLimiter::attempts($this->userKey()));
    }

    public function test_a_blocked_address_cannot_use_up_the_accounts_budget(): void
    {
        for ($attempt = 1; $attempt <= 9; $attempt++) {
            $this->post(route('two-factor.login'), ['code' => '000000']);
        }

        $this->assertSame(5, RateLimiter::attempts($this->userKey()), 'attempts 6 to 9 stopped at the address limit');
    }

    public function test_a_throttled_attempt_is_not_checked_so_a_valid_recovery_code_stays_unspent(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('two-factor.login'), ['code' => '000000']);
        }

        $this->post(route('two-factor.login'), ['code' => $this->codes[0]])->assertSessionHasErrors('code');

        $this->assertSame(8, app(TwoFactorService::class)->status($this->user->fresh())['recovery_codes_remaining']);
        $this->assertSame(5, Activity::query()->where('description', 'two_factor.challenge_failed')->count(), 'only checked codes are logged as failures');

        $this->travel(61)->seconds();
        $this->post(route('two-factor.login'), ['code' => $this->codes[0]])
            ->assertRedirect(route('admin.dashboard', absolute: false));
    }

    private function addressKey(): string
    {
        return 'two-factor|'.$this->user->id.'|127.0.0.1';
    }

    private function userKey(): string
    {
        return 'two-factor-user|'.$this->user->id;
    }

    private function fromAddress(string $address): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $address]);
    }
}
