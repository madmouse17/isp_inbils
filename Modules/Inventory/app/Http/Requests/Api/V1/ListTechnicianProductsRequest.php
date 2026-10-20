<?php

namespace Modules\Inventory\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\PageRequest;
use App\Http\Requests\Concerns\ResolvesPublicIds;
use App\Models\Core\Location;
use App\Services\Core\CompanyService;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\Product;

class ListTechnicianProductsRequest extends PageRequest
{
    use ResolvesPublicIds;

    /** @return array<string, class-string> */
    protected function publicIdFields(): array
    {
        return ['warehouse_id' => Location::class];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Product::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = CompanyService::currentId();

        return [
            ...parent::rules(),
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            /**
             * A place `GET technician/warehouses` offers (active, holding stock), by its public id. With it every product
             * says how much is free there in `available`; without it `available` is null.
             *
             * @var string|null
             */
            'warehouse_id' => ['sometimes', 'nullable', 'integer', Rule::exists('locations', 'id')->where(fn (Builder $location) => $location
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->whereIn('id', fn (Builder $stock) => $stock->select('location_id')->from('stocks')->where('company_id', $companyId)))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        // One text for every refusal, so the answer never says which warehouses exist.
        return ['warehouse_id.exists' => 'Gudang tidak tersedia.'];
    }

    public function search(): ?string
    {
        return $this->string('search')->trim()->value() ?: null;
    }

    public function warehouseId(): ?int
    {
        return $this->filled('warehouse_id') ? (int) $this->validated('warehouse_id') : null;
    }
}
