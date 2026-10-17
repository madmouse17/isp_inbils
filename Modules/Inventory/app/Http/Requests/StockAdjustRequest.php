<?php

namespace Modules\Inventory\Http\Requests;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\Core\Location;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\Product;

class StockAdjustRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['product_id' => Product::class, 'location_id' => Location::class];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('inventory.stock.adjust.request') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            'product_id' => ['required', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'location_id' => ['required', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            'new_quantity' => ['required', 'numeric', 'min:0'],
            'note' => ['required', 'string', 'max:500'],
        ];
    }
}
