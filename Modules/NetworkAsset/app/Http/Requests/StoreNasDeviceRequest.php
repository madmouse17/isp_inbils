<?php

namespace Modules\NetworkAsset\Http\Requests;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\NetworkAsset\Models\NasDevice;

class StoreNasDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('network_asset.nas.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            'network_asset_id' => [
                'required',
                'integer',
                Rule::exists('network_assets', 'id')
                    ->where('company_id', $companyId)
                    ->where('asset_type', 'router')
                    ->whereNull('deleted_at')
                    ->whereNotNull('management_ip'),
                Rule::unique('nas_devices', 'network_asset_id'),
            ],
            'api_protocol' => ['sometimes', Rule::in(NasDevice::API_PROTOCOLS)],
            'api_port' => ['nullable', 'integer', 'between:1,65535'],
            'api_username' => ['required', 'string', 'max:64'],
            'api_password' => ['required', 'string', 'max:128'],
            'isolir_profile' => ['nullable', 'string', 'regex:/^[A-Za-z0-9._-]{1,64}$/'],
            'provisioning_enabled' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'network_asset_id.exists' => 'Choose a router asset of this company that has a management IP.',
            'network_asset_id.unique' => 'This router asset is already registered as a NAS router.',
            'isolir_profile.regex' => 'Use only letters, digits, dots, dashes and underscores (up to 64 characters).',
        ];
    }
}
