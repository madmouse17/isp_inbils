<?php

namespace Modules\Inventory\Http\Requests;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Unit;

class UpdateCategoryRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['parent_id' => Category::class, 'unit_id' => Unit::class];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('inventory.update') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $category = $this->route('category');
        $categoryId = $category->id;
        $companyId = CompanyService::currentId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:100', Rule::unique('categories')->where('company_id', $companyId)->ignore($categoryId)],
            'parent_id' => ['nullable', Rule::exists('categories', 'id')->where('company_id', $companyId)],
            'unit_id' => ['required', Unit::assignableRule($companyId, $category->unit_id)],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['unit_id.exists' => 'Satuan yang dipilih tidak aktif atau tidak ditemukan.'];
    }
}
