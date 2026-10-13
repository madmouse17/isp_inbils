<?php

namespace Modules\SPK\Http\Requests\Api\V1;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SPK\Models\WorkOrder;

class AddWorkOrderItemRequest extends FormRequest
{
    /**
     * The technician who may submit this SPK, so only the assigned one while it is in progress. Asked before the
     * body is validated: a colleague gets 403, never a 422 about the product.
     */
    public function authorize(): bool
    {
        $workOrder = $this->route('wo');

        return $workOrder instanceof WorkOrder && ($this->user()?->can('submit', $workOrder) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // A serialized asset needs a chosen unit, which the app cannot pick: the web adds those.
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')
                ->where('company_id', CompanyService::currentId())
                ->where('is_active', true)
                ->whereNot('type', 'asset')
                ->whereNull('deleted_at')],
            // The column is decimal(15,2).
            'quantity' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
        ];
    }
}
