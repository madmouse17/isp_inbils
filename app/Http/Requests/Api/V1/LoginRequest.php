<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Auth\LoginRequest as WebLoginRequest;
use App\Services\Core\ApiTokenService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The web login's credential check, user lookup (inactive users fail like a wrong password) and failed-attempt
 * budget, shared with the web through the same throttle key, plus the device and the app the token is for.
 * Only the answer to the budget differs: the app gets a 429 with Retry-After, not a form error.
 */
class LoginRequest extends WebLoginRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'device_name' => ['required', 'string', 'max:100'],
            'app' => ['required', Rule::in(ApiTokenService::APPS)],
        ];
    }

    /** Control characters have no business in a device name that is shown in a list and written to the audit log. */
    protected function prepareForValidation(): void
    {
        if (is_string($name = $this->input('device_name'))) {
            $this->merge(['device_name' => trim(preg_replace('/\p{Cc}/u', '', $name) ?? '')]);
        }
    }

    public function ensureIsNotRateLimited(): void
    {
        try {
            parent::ensureIsNotRateLimited();
        } catch (ValidationException) {
            $seconds = RateLimiter::availableIn($this->throttleKey());

            throw new ThrottleRequestsException(
                trans('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
                headers: ['Retry-After' => $seconds],
            );
        }
    }
}
