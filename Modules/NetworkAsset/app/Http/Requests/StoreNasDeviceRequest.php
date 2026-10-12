<?php

namespace Modules\NetworkAsset\Http\Requests;

use App\Services\Core\CompanyService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Services\Radius\Realm;

/** `nasname` defaults to the management IP of the router asset. */
class StoreNasDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('network_asset.nas.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (blank($this->input('nasname')) && is_numeric($this->input('network_asset_id'))) {
            $this->merge(['nasname' => NetworkAsset::query()->whereKey((int) $this->input('network_asset_id'))->value('management_ip')]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'network_asset_id' => [
                'required',
                'integer',
                Rule::exists('network_assets', 'id')
                    ->where('company_id', CompanyService::currentId())
                    ->where('asset_type', 'router')
                    ->whereNull('deleted_at')
                    ->whereNotNull('management_ip'),
                Rule::unique('nas_devices', 'network_asset_id'),
            ],
            'nasname' => ['required', 'ipv4', Rule::unique('nas_devices', 'nasname')],
            'coa_port' => ['nullable', 'integer', 'between:1,65535'],
            'radius_secret' => ['required', 'string', 'min:8', 'max:60', 'regex:/^[\x21-\x7E]+$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'network_asset_id.exists' => 'Choose a router asset of this company that has a management IP.',
            'network_asset_id.unique' => 'This router asset is already registered as a NAS router.',
            // Never says which company owns the address: FreeRADIUS clients are global.
            'nasname.unique' => 'This address is not available.',
            'radius_secret.regex' => 'Use printable characters without spaces.',
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (Realm::forCompany((int) CompanyService::currentId()) === null) {
                $validator->errors()->add('realm', 'The company code cannot be used as a RADIUS realm: use 1 to 24 letters, digits or dashes.');
            }
        }];
    }
}
