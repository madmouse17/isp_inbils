<?php

namespace Modules\NetworkAsset\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNetworkAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('network_asset.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = \App\Services\Core\CompanyService::currentId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'asset_type' => ['required', 'string', 'in:router,switch,olt,onu_ont,radio,antenna,fiber,odp,odc,rack,power,other'],
            'serial_number' => ['nullable', 'string', 'max:255', Rule::unique('network_assets')->where('company_id', $companyId)],
            'mac_address' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['nullable', 'ip'],
            'management_ip' => ['nullable', 'ip'],
            'location_id' => ['nullable', 'exists:locations,id'],
            'ownership' => ['nullable', 'string', 'in:owned,leased,customer_provided'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'purchase_date' => ['nullable', 'date'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'warranty_expiry' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:available,installed,maintenance,damaged,retired'],
        ];
    }
}
