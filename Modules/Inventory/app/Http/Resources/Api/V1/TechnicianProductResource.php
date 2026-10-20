<?php

namespace Modules\Inventory\Http\Resources\Api\V1;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\Product;

/**
 * A product in the technician app's picker: what to show and what to send back, no prices. Stock only as `available`,
 * and only for the warehouse the request named.
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
            'id' => $product->public_id,
            'sku' => $product->sku,
            'name' => $product->name,
            /** @var string|null */
            'unit' => data_get($product, 'unit.symbol') ?? data_get($product, 'unit.name'),
            /**
             * What is free to ask for at the warehouse the request named (stock minus reserved, "0" when it holds none of
             * this product), as a decimal without trailing zeros. Null when the request named no warehouse.
             *
             * @var string|null
             */
            'available' => $this->available($product),
        ];
    }

    private function available(Product $product): ?string
    {
        // The controller selects the column only for a named warehouse, NULL when the product has no stock row there.
        if (! array_key_exists('available', $product->getAttributes())) {
            return null;
        }

        return rtrim(rtrim(Money::round($product->getAttribute('available') ?? 0), '0'), '.');
    }
}
