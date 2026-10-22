<?php

namespace Modules\NetworkAsset\Http\Requests;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Services\Core\CompanyService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Services\Radius\Realm;

/**
 * `nasname` defaults to the management IP of the router asset. Instead of picking an asset the form may carry
 * `new_asset {name, management_ip}`: the router asset is then made in the same transaction (NasDeviceService::create).
 */
class StoreNasDeviceRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['network_asset_id' => NetworkAsset::class];
    }

    public function authorize(): bool
    {
        $user = $this->user();

        // The router asset made on the way is a network asset like any other: creating one takes its own permission.
        return ($user?->can('network_asset.nas.manage') ?? false)
            && (! $this->createsRouterAsset() || $user->can('network_asset.create'));
    }

    protected function prepareForValidation(): void
    {
        if (! blank($this->input('nasname'))) {
            return;
        }

        if ($this->createsRouterAsset()) {
            $this->merge(['nasname' => $this->input('new_asset.management_ip')]);
        } elseif (is_numeric($this->input('network_asset_id'))) {
            $this->merge(['nasname' => NetworkAsset::query()->whereKey((int) $this->input('network_asset_id'))->value('management_ip')]);
        }
    }

    private function createsRouterAsset(): bool
    {
        return is_array($this->input('new_asset'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'network_asset_id' => [
                $this->createsRouterAsset() ? 'prohibited' : 'required',
                'integer',
                Rule::exists('network_assets', 'id')
                    ->where('company_id', CompanyService::currentId())
                    ->where('asset_type', 'router')
                    ->whereNull('deleted_at')
                    ->whereNotNull('management_ip'),
                Rule::unique('nas_devices', 'network_asset_id'),
            ],
            'new_asset' => ['nullable', 'array:name,management_ip', 'required_array_keys:name,management_ip'],
            'new_asset.name' => ['required_with:new_asset', 'string', 'max:255'],
            'new_asset.management_ip' => ['required_with:new_asset', 'ipv4'],
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
            'network_asset_id.prohibited' => 'Pilih aset router atau buat yang baru, tidak keduanya.',
            'new_asset.array' => 'Data router baru tidak dikenali.',
            'new_asset.required_array_keys' => 'Isi nama router dan IP manajemen.',
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
            'new_asset.name' => 'nama router',
            'new_asset.management_ip' => 'IP manajemen',
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
