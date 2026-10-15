<?php

namespace Modules\NetworkAsset\Http\Requests;

use App\Services\Core\CompanyService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Services\Radius\Realm;

/** The router asset itself cannot change. A blank RADIUS secret keeps the stored one. */
class UpdateNasDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('network_asset.nas.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var NasDevice $nas */
        $nas = $this->route('nasDevice');

        return [
            'nasname' => ['sometimes', 'required', 'ipv4', Rule::unique('nas_devices', 'nasname')->ignore($nas->id)],
            'coa_port' => ['sometimes', 'required', 'integer', 'between:1,65535'],
            'radius_secret' => ['nullable', 'string', 'min:8', 'max:60', 'regex:/^[\x21-\x7E]+$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nasname.unique' => 'Alamat ini tidak tersedia.',
            'radius_secret.regex' => 'Gunakan karakter yang dapat dicetak, tanpa spasi.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
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
