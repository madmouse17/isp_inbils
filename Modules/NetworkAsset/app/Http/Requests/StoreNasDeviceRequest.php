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
            'network_asset_id.exists' => 'Pilih aset router milik perusahaan ini yang punya IP manajemen.',
            'network_asset_id.unique' => 'Aset router ini sudah terdaftar sebagai router NAS.',
            // Never says which company owns the address: FreeRADIUS clients are global.
            'nasname.unique' => 'Alamat ini tidak tersedia.',
            'radius_secret.regex' => 'Gunakan karakter yang dapat dicetak, tanpa spasi.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'network_asset_id' => 'aset router',
            'nasname' => 'alamat NAS',
            'coa_port' => 'port CoA',
            'radius_secret' => 'secret RADIUS',
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (Realm::forCompany((int) CompanyService::currentId()) === null) {
                $validator->errors()->add('realm', 'Kode perusahaan tidak dapat dipakai sebagai realm RADIUS: gunakan 1 sampai 24 huruf, angka, atau tanda hubung.');
            }
        }];
    }
}
