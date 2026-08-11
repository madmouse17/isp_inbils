<?php

namespace Modules\Billing\Http\Requests;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('billing.update') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            'product_id' => ['nullable', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'description' => ['required', 'string', 'max:500'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
