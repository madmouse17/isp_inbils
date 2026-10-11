<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\Core\TwoFactorService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class TwoFactorChallengeRequest extends FormRequest
{
    /** Per user and address, as the password step is per email and address. */
    private const ATTEMPTS_PER_ADDRESS = 5;

    private const ADDRESS_WINDOW_SECONDS = 60;

    /** Per user across every address: six digits are guessable by someone who holds the password and rotates addresses. */
    private const ATTEMPTS_PER_USER = 10;

    private const USER_WINDOW_SECONDS = 900;

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
     * Check the submitted code for $user. Every attempt is counted before it is checked and a throttled
     * attempt is never checked, so even the right code is refused until the window passes. A passing
     * code gives the budget back.
     *
     * @throws ValidationException
     */
    public function verify(User $user, TwoFactorService $twoFactor): void
    {
        $this->claimAttempt($user);

        if (! $twoFactor->verifyChallenge($user, $this->string('code')->toString())) {
            throw ValidationException::withMessages([
                'code' => __('The provided two-factor code was invalid.'),
            ]);
        }

        RateLimiter::clear($this->addressKey($user));
        RateLimiter::clear($this->userKey($user));
    }

    /**
     * Counts this attempt against both limits. hit() is an atomic increment that returns the new count, so of
     * N attempts in flight at once only the first few get past it. Checking first and counting after the
     * verification (which waits on a row lock) would let a whole burst through. The address limit comes first:
     * an address that is already blocked cannot use up the account's budget.
     *
     * @throws ValidationException
     */
    private function claimAttempt(User $user): void
    {
        $limits = [
            [$this->addressKey($user), self::ATTEMPTS_PER_ADDRESS, self::ADDRESS_WINDOW_SECONDS],
            [$this->userKey($user), self::ATTEMPTS_PER_USER, self::USER_WINDOW_SECONDS],
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

    private function addressKey(User $user): string
    {
        return 'two-factor|'.$user->getKey().'|'.$this->ip();
    }

    private function userKey(User $user): string
    {
        return 'two-factor-user|'.$user->getKey();
    }
}
