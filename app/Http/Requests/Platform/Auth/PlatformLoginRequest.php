<?php

namespace App\Http\Requests\Platform\Auth;

use App\Models\PlatformAdmin;
use App\Support\SecurityTrail;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The password step of the operator login. Three failure budgets, all checked before the credentials are looked
 * at: 5 a minute per email and address, 20 an hour per email across every address, and 20 a minute per address
 * whatever the email. A failure counts against all three; a success clears the two email budgets only, so a known
 * login cannot reset the address budget. An inactive admin fails exactly like a wrong password.
 */
class PlatformLoginRequest extends FormRequest
{
    private const ATTEMPTS_PER_ADDRESS = 5;

    private const ADDRESS_WINDOW_SECONDS = 60;

    private const ATTEMPTS_PER_EMAIL = 20;

    private const EMAIL_WINDOW_SECONDS = 3600;

    private const FAILURES_PER_IP = 20;

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
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Check the credentials and return the admin without opening a session, so the caller can ask for the
     * second factor first.
     *
     * @throws ValidationException
     */
    public function validateCredentials(): PlatformAdmin
    {
        $this->ensureIsNotRateLimited();

        /** @var SessionGuard $guard */
        $guard = Auth::guard('platform');

        // is_active is part of the lookup: an inactive admin fails with the same message as a wrong password.
        if (! $guard->validate([...$this->only('email', 'password'), 'is_active' => true])) {
            SecurityTrail::failedLogin((string) $this->input('email'), 'platform', $guard->getLastAttempted());
            RateLimiter::hit($this->addressKey(), self::ADDRESS_WINDOW_SECONDS);
            RateLimiter::hit($this->emailKey(), self::EMAIL_WINDOW_SECONDS);
            RateLimiter::hit($this->ipKey(), self::ADDRESS_WINDOW_SECONDS);

            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        RateLimiter::clear($this->addressKey());
        RateLimiter::clear($this->emailKey());

        /** @var PlatformAdmin $admin */
        $admin = $guard->getLastAttempted();

        return $admin;
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(): void
    {
        $blocked = array_values(array_filter([
            RateLimiter::tooManyAttempts($this->addressKey(), self::ATTEMPTS_PER_ADDRESS) ? $this->addressKey() : null,
            RateLimiter::tooManyAttempts($this->emailKey(), self::ATTEMPTS_PER_EMAIL) ? $this->emailKey() : null,
            RateLimiter::tooManyAttempts($this->ipKey(), self::FAILURES_PER_IP) ? $this->ipKey() : null,
        ]));

        if ($blocked === []) {
            return;
        }

        // Only an account lockout is an event: an address-wide refusal would write one row per rotated email.
        if ($blocked !== [$this->ipKey()]) {
            event(new Lockout($this));
        }

        $seconds = max(array_map(RateLimiter::availableIn(...), $blocked));

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    private function addressKey(): string
    {
        return 'platform-login|'.$this->normalizedEmail().'|'.$this->ip();
    }

    private function emailKey(): string
    {
        return 'platform-login-email|'.$this->normalizedEmail();
    }

    private function ipKey(): string
    {
        return 'platform-login-ip|'.$this->ip();
    }

    private function normalizedEmail(): string
    {
        return Str::transliterate(Str::lower($this->string('email')->toString()));
    }
}
