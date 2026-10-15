<?php

namespace Modules\NetworkAsset\Http\Requests;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNetworkAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('network_asset.update') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $assetId = $this->route('network_asset')->id ?? $this->route('asset')->id;
        $companyId = CompanyService::currentId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'product_id' => ['nullable', Rule::exists('products', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'asset_type' => ['required', 'string', 'in:router,switch,olt,onu_ont,radio,antenna,fiber,odp,odc,rack,power,other'],
            'serial_number' => ['nullable', 'string', 'max:255', Rule::unique('network_assets')->where('company_id', $companyId)->ignore($assetId)],
            'mac_address' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['nullable', 'ip'],
            'management_ip' => ['nullable', 'ip'],
            'location_id' => ['nullable', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            'ownership' => ['nullable', 'string', 'in:owned,leased,customer_provided'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'purchase_date' => ['nullable', 'date_format:Y-m-d'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'warranty_expiry' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'purchase_date.date_format' => 'Tanggal pembelian tidak valid.',
            'warranty_expiry.date_format' => 'Tanggal garansi berakhir tidak valid.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'nama',
            'product_id' => 'produk',
            'asset_type' => 'tipe aset',
            'serial_number' => 'nomor seri',
            'mac_address' => 'alamat MAC',
            'ip_address' => 'alamat IP',
            'management_ip' => 'IP manajemen',
            'location_id' => 'lokasi',
            'ownership' => 'kepemilikan',
            'vendor' => 'vendor',
            'model' => 'model',
            'purchase_date' => 'tanggal pembelian',
            'purchase_price' => 'harga beli',
            'warranty_expiry' => 'tanggal garansi berakhir',
            'notes' => 'catatan',
        ];
    }
}
