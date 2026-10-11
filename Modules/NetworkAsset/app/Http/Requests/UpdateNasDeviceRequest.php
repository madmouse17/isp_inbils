<?php

namespace Modules\NetworkAsset\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The router asset itself cannot change. A blank API password keeps the stored one. */
class UpdateNasDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('network_asset.nas.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'api_port' => ['sometimes', 'required', 'integer', 'between:1,65535'],
            'api_username' => ['sometimes', 'required', 'string', 'max:64'],
            'api_password' => ['nullable', 'string', 'max:128'],
            'isolir_profile' => ['sometimes', 'required', 'string', 'regex:/^[A-Za-z0-9._-]{1,64}$/'],
            'provisioning_enabled' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'isolir_profile.regex' => 'Use only letters, digits, dots, dashes and underscores (up to 64 characters).',
        ];
    }
}
