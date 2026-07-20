<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.update') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $categoryId = $this->route('category')->id;
        $companyId = \App\Services\Core\CompanyService::currentId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:100', Rule::unique('categories')->where('company_id', $companyId)->ignore($categoryId)],
            'parent_id' => ['nullable', Rule::exists('categories', 'id')->where('company_id', $companyId)],
            'unit_id' => ['required', Rule::exists('units', 'id')->where('company_id', $companyId)],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }
}
