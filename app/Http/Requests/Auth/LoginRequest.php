<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\Core\CompanyService;
use App\Support\SecurityTrail;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /** Failures one account may make from one address before it is locked. The unlock tool and the lock badge read it too. */
    public const ATTEMPTS_PER_ACCOUNT = 5;

    /** Failed sign-ins one address may make per minute whatever the typed email: a rotated email starts no fresh budget. */
    private const FAILURES_PER_ADDRESS = 20;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
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
     * Check the request's credentials and return the user without opening a session, so the caller
     * can ask for a second factor first. Wrong credentials count against the login throttle.
     *
     * @throws ValidationException
     */
    public function validateCredentials(): User
    {
        $this->ensureIsNotRateLimited();

        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');

        // is_active is part of the lookup: an inactive user fails exactly like a wrong password, with the
        // same message, so the form does not say which accounts exist or which are switched off.
        if (! $guard->validate([...$this->only('email', 'password'), 'is_active' => true])) {
            // The account the typed address named, when it is active. An inactive one is not looked up, so it counts as unknown.
            SecurityTrail::failedLogin((string) $this->input('email'), $this->is('api/*') ? 'api' : 'web', $guard->getLastAttempted());
            RateLimiter::hit($this->throttleKey());
            RateLimiter::hit($this->addressKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        /** @var User $user */
        $user = $guard->getLastAttempted();

        // Only after the credentials proved right, so a wrong guess never learns that a company is suspended.
        if (! CompanyService::canEnter($user)) {
            throw ValidationException::withMessages(['email' => CompanyService::SUSPENDED_MESSAGE]);
        }

        return $user;
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $key = $this->exhaustedKey();

        if ($key === null) {
            return;
        }

        // Only an account lockout is an event: an address-wide refusal would write one row per rotated email.
        if ($key === $this->throttleKey()) {
            event(new Lockout($this));
        }

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /** The failure budget this request has used up, if any. Successes never count, so a busy office NAT signs in freely. */
    protected function exhaustedKey(): ?string
    {
        return match (true) {
            RateLimiter::tooManyAttempts($this->throttleKey(), self::ATTEMPTS_PER_ACCOUNT) => $this->throttleKey(),
            RateLimiter::tooManyAttempts($this->addressKey(), self::FAILURES_PER_ADDRESS) => $this->addressKey(),
            default => null,
        };
    }

    private function addressKey(): string
    {
        return 'login-address|'.$this->ip();
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return self::throttleKeyFor($this->string('email')->toString(), (string) $this->ip());
    }

    /** The failure budget of one typed email from one address. Support clears it without a request, so the key lives in one place. */
    public static function throttleKeyFor(string $email, string $ip): string
    {
        return Str::transliterate(Str::lower($email).'|'.$ip);
    }
}
