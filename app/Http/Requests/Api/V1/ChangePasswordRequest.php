<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Changing the password from the app. The current password is the only step-up a bearer token has, so a stolen
 * token must not be a free place to guess it: wrong guesses are limited per user and address with the key and
 * budget of the web's confirm-password step (ConfirmablePasswordController), counted before the password is
 * checked. The check is therefore not a validation rule: rules run before the controller can claim an attempt.
 */
class ChangePasswordRequest extends FormRequest
{
    private const ATTEMPTS = 5;

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
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ];
    }

    /**
     * @throws ThrottleRequestsException
     * @throws ValidationException
     */
    public function verifyCurrentPassword(User $user): void
    {
        $key = 'password-confirm|'.$user->getAuthIdentifier().'|'.$this->ip();

        if (RateLimiter::hit($key) > self::ATTEMPTS) {
            event(new Lockout($this));

            $seconds = RateLimiter::availableIn($key);

            throw new ThrottleRequestsException(
                trans('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
                headers: ['Retry-After' => $seconds],
            );
        }

        if (! Hash::check($this->string('current_password')->toString(), $user->getAuthPassword())) {
            throw ValidationException::withMessages(['current_password' => __('The provided password does not match your current password.')]);
        }

        RateLimiter::clear($key);
    }
}
