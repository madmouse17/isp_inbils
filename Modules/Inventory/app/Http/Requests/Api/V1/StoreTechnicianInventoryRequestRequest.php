<?php

namespace Modules\Inventory\Http\Requests\Api\V1;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\InventoryRequest;

class StoreTechnicianInventoryRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', InventoryRequest::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();
        $quantity = ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999999.99']; // decimal(15,2)

        return [
            'warehouse_location_id' => ['required', 'integer', Rule::exists('locations', 'id')
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->whereNull('deleted_at')],
            // Left blank, the service writes its own reason.
            'reason' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')
                ->where('company_id', $companyId)
                ->where('is_active', true)
                // The service refuses serialized assets in a bulk request.
                ->whereNot('type', 'asset')
                ->whereNull('deleted_at')],
            'items.*.quantity_requested' => $quantity,
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
