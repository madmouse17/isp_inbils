<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Core\DeviceToken;
use App\Services\Core\ApiTokenService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * An app install registers the FCM token it just got (or refreshed) for the signed-in user. The app named in the body
 * must be the one this bearer token was issued for: a customer token cannot register a technician install.
 */
class StoreDeviceTokenRequest extends FormRequest
{
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
            'token' => ['required', 'string', 'max:4096'],
            'app' => ['required', 'string', Rule::in(ApiTokenService::APPS)],
            'platform' => ['required', 'string', Rule::in(DeviceToken::PLATFORMS)],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isEmpty() && $this->string('app')->toString() !== app(ApiTokenService::class)->appOf($this->user())) {
                    $validator->errors()->add('app', __('The app must be the one this sign-in was issued for.'));
                }
            },
        ];
    }
}
