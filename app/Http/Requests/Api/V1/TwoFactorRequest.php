<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use App\Services\Core\TwoFactorService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Step two of an app login: the challenge from the password step and one code (TOTP or recovery code).
 *
 * The brute-force budgets are those of the web challenge, TwoFactorChallengeRequest, and use the same
 * limiter keys, so wrong codes sent from the app and from the browser add up. The web request keeps its
 * limits private; they are repeated here and pinned by tests/Feature/Api/V1/Auth/TwoFactorLoginTest.
 */
class TwoFactorRequest extends FormRequest
{
    private const ATTEMPTS_PER_ADDRESS = 5;

    private const ADDRESS_WINDOW_SECONDS = 60;

    private const ATTEMPTS_PER_USER = 10;

    private const USER_WINDOW_SECONDS = 900;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'challenge_id' => ['required', 'string', 'max:128'],
            'code' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * Counts this attempt against both budgets before the code is looked at, so a burst cannot get past the
     * limit while the first attempts wait on a row lock, and a throttled attempt is never checked: even the
     * right code is refused until the window passes. The address budget comes first: an address that is already
     * blocked cannot use up the account's.
     *
     * @throws ThrottleRequestsException
     */
    public function claimAttempt(User $user): void
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

            throw new ThrottleRequestsException(
                trans('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
                headers: ['Retry-After' => $seconds],
            );
        }
    }

    /**
     * A passing code gives both budgets back.
     *
     * @throws ValidationException
     */
    public function verifyCode(User $user, TwoFactorService $twoFactor): void
    {
        if (! $twoFactor->verifyChallenge($user, $this->string('code')->toString())) {
            throw ValidationException::withMessages(['code' => __('The provided two-factor code was invalid.')]);
        }

        RateLimiter::clear($this->addressKey($user));
        RateLimiter::clear($this->userKey($user));
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
