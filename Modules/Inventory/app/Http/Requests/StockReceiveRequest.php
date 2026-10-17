<?php

namespace Modules\Inventory\Http\Requests;

use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\Core\Location;
use App\Rules\PolymorphicBelongsToCompany;
use App\Services\Core\CompanyService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\Product;
use Modules\SPK\Models\WorkOrderItem;

class StockReceiveRequest extends FormRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string|\Closure> */
    protected function publicIdFields(): array
    {
        return [
            'product_id' => Product::class,
            'location_id' => Location::class,
            'reference_id' => fn (array $input): ?string => is_string($type = $input['reference_type'] ?? null) ? (self::referenceTypes()[$type] ?? null) : null,
        ];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('inventory.stock.receive') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        $referenceTypes = self::referenceTypes();

        return [
            'product_id' => ['required', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'location_id' => ['required', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:500'],
            'reference_type' => ['nullable', 'required_with:reference_id', 'string', Rule::in(array_keys($referenceTypes))],
            'reference_id' => ['nullable', 'required_with:reference_type', 'integer', new PolymorphicBelongsToCompany($referenceTypes, 'reference_type')],
        ];
    }

    /** @return array<string, class-string<Model>> */
    private static function referenceTypes(): array
    {
        $type = (new WorkOrderItem())->getMorphClass();

        return array_unique([
            WorkOrderItem::class => WorkOrderItem::class,
            $type => WorkOrderItem::class,
        ]);
    }
}
