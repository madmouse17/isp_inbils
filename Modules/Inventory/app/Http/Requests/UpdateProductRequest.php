<?php

namespace Modules\Inventory\Http\Requests;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Services\Core\CompanyService;
use App\Services\Core\NumberSequenceService;
use App\Support\OptimisticLock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Unit;

class UpdateProductRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['category_id' => Category::class];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('inventory.update') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $category = Category::query()
            ->whereKey($this->input('category_id'))
            ->first();

        $this->merge([
            'unit_id' => $category?->unit_id,
            // An empty form field arrives as null; the column is NOT NULL with a default of 0.
            'min_stock' => $this->filled('min_stock') ? $this->input('min_stock') : 0,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $product = $this->route('product');
        $productId = $product->id;
        $companyId = CompanyService::currentId();

        return [
            OptimisticLock::FIELD => OptimisticLock::rules(),
            'sku' => NumberSequenceService::allowsManual('product')
                ? ['required', 'string', 'max:100', Rule::unique('products')->where('company_id', $companyId)->ignore($productId)]
                : ['exclude'],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['required', Rule::exists('categories', 'id')->where('company_id', $companyId)],
            'unit_id' => ['required', Unit::assignableRule($companyId, $product->unit_id)],
            'description' => ['nullable', 'string'],
            'type' => ['nullable', 'string', 'in:consumable,asset,service'],
            'track_stock' => ['boolean'],
            'sell_price' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['unit_id.exists' => 'Satuan kategori ini sudah tidak aktif. Ganti satuan kategori terlebih dahulu.'];
    }
}
