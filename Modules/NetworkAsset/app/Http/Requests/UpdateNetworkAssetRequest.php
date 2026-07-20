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
            'purchase_date' => ['nullable', 'date'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'warranty_expiry' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
