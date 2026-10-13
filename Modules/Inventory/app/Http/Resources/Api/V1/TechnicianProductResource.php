<?php

namespace Modules\Inventory\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\Product;

/**
 * A product in the technician app's picker: what to show and what to send back, no prices or stock.
 *
 * @property Product $resource
 */
class TechnicianProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $product = $this->resource;

        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            /** @var string|null */
            'unit' => data_get($product, 'unit.symbol') ?? data_get($product, 'unit.name'),
        ];
    }
}
