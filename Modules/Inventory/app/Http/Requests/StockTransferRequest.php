<?php

namespace Modules\Inventory\Http\Requests;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\Core\Location;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\Product;

class StockTransferRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['product_id' => Product::class, 'from_location_id' => Location::class, 'to_location_id' => Location::class];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('inventory.stock.transfer') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            'product_id' => ['required', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'from_location_id' => ['required', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            'to_location_id' => ['required', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
