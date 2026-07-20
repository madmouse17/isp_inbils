<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\Category;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.create') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $category = Category::query()
            ->whereKey($this->input('category_id'))
            ->first();

        $this->merge([
            'unit_id' => $category?->unit_id,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = \App\Services\Core\CompanyService::currentId();

        return [
            'sku' => ['required', 'string', 'max:100', Rule::unique('products')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['required', Rule::exists('categories', 'id')->where('company_id', $companyId)],
            'unit_id' => ['required', Rule::exists('units', 'id')->where('company_id', $companyId)],
            'description' => ['nullable', 'string'],
            'type' => ['nullable', 'string', 'in:consumable'],
            'track_stock' => ['boolean'],
            'sell_price' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
