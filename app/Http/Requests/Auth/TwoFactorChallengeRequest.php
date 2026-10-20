<?php

namespace App\Http\Requests\Auth;

use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Core\TwoFactorService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The login challenge of both planes. A platform admin's limiter keys carry their own prefix, so a platform admin
 * and a tenant user who happen to share a number never share a budget (the ids belong to different tables).
 */
class TwoFactorChallengeRequest extends FormRequest
{
    /** Per account and address, as the password step is per email and address. */
    public const ATTEMPTS_PER_ADDRESS = 5;

    private const ADDRESS_WINDOW_SECONDS = 60;

    /** Per account across every address: six digits are guessable by someone who holds the password and rotates addresses. */
    public const ATTEMPTS_PER_ACCOUNT = 10;

    private const ACCOUNT_WINDOW_SECONDS = 900;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * One field: a six-digit authenticator code or a recovery code.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * Check the submitted code for $account. Every attempt is counted before it is checked and a throttled
     * attempt is never checked, so even the right code is refused until the window passes. A passing
     * code gives the budget back.
     *
     * @throws ValidationException
     */
    public function verify(User|PlatformAdmin $account, TwoFactorService $twoFactor): void
    {
        $this->claimAttempt($account);

        if (! $twoFactor->verifyChallenge($account, $this->string('code')->toString())) {
            throw ValidationException::withMessages([
                'code' => __('The provided two-factor code was invalid.'),
            ]);
        }

        RateLimiter::clear($this->addressKey($account));
        RateLimiter::clear($this->accountKey($account));
    }

    /**
     * Counts this attempt against both limits. hit() is an atomic increment that returns the new count, so of
     * N attempts in flight at once only the first few get past it. Checking first and counting after the
     * verification (which waits on a row lock) would let a whole burst through. The address limit comes first:
     * an address that is already blocked cannot use up the account's budget.
     *
     * @throws ValidationException
     */
    private function claimAttempt(User|PlatformAdmin $account): void
    {
        $limits = [
            [$this->addressKey($account), self::ATTEMPTS_PER_ADDRESS, self::ADDRESS_WINDOW_SECONDS],
            [$this->accountKey($account), self::ATTEMPTS_PER_ACCOUNT, self::ACCOUNT_WINDOW_SECONDS],
        ];

        foreach ($limits as [$key, $maxAttempts, $windowSeconds]) {
            if (RateLimiter::hit($key, $windowSeconds) <= $maxAttempts) {
                continue;
            }

            event(new Lockout($this));

            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'code' => trans('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => ceil($seconds / 60),
                ]),
            ]);
        }
    }

    private function addressKey(User|PlatformAdmin $account): string
    {
        return self::addressKeyFor($account, (string) $this->ip());
    }

    private function accountKey(User|PlatformAdmin $account): string
    {
        return self::accountKeyFor($account);
    }

    /** The budget of one account from one address. The web and the app challenge share it, and support clears it without a request. */
    public static function addressKeyFor(User|PlatformAdmin $account, string $ip): string
    {
        return ($account instanceof PlatformAdmin ? 'platform-two-factor' : 'two-factor').'|'.$account->getKey().'|'.$ip;
    }

    /** The budget of one account across every address. */
    public static function accountKeyFor(User|PlatformAdmin $account): string
    {
        return ($account instanceof PlatformAdmin ? 'platform-two-factor-admin' : 'two-factor-user').'|'.$account->getKey();
    }
}
