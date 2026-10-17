<?php

namespace Modules\Inventory\Support;

use App\Models\Core\Location;
use App\Support\SearchOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\OptionalProp;
use Modules\Inventory\Models\Product;

/**
 * The product and location pickers of the inventory pages. `products()` and `locations()` are the optional props
 * `productOptions` and `locationOptions` that `RemoteSearchSelect` reloads while a person types; `selectedProduct()`
 * and `selectedLocation()` give the label of a value the page already holds (a filter in the address bar) before any
 * search. Product and Location are `BelongsToCompany` models, so every query here is already company-scoped.
 */
final class InventoryOptions
{
    private const PRODUCT_RELATIONS = ['unit:id,name,symbol', 'category:id,name'];

    /**
     * @param  Builder<Product>  $base  which products may be picked; the unit and category are loaded for the label
     */
    public static function products(Request $request, Builder $base): OptionalProp
    {
        return SearchOptions::for(
            $request,
            'product',
            $base->with(self::PRODUCT_RELATIONS),
            ['name', 'sku'],
            fn (Product $product): array => self::product($product),
        );
    }

    public static function locations(Request $request): OptionalProp
    {
        return SearchOptions::for(
            $request,
            'location',
            Location::query()->where('is_active', true),
            ['name', 'code'],
            fn (Location $location): array => self::location($location),
        );
    }

    /** @return array{value: string, label: string, description: string|null}|null */
    public static function selectedProduct(mixed $key): ?array
    {
        $product = Product::query()->withTrashed()->with(self::PRODUCT_RELATIONS)->wherePublicId($key)->first();

        return $product ? self::product($product) : null;
    }

    /** @return array{value: string, label: string, description: string|null}|null */
    public static function selectedLocation(mixed $key): ?array
    {
        $location = Location::query()->withTrashed()->wherePublicId($key)->first();

        return $location ? self::location($location) : null;
    }

    /** @return array{value: string, label: string, description: string|null} */
    public static function product(Product $product): array
    {
        $unit = $product->unit?->symbol;

        return [
            'value' => $product->public_id,
            'label' => $product->sku.' - '.$product->name.($unit ? " ({$unit})" : ''),
            'description' => $product->category?->name,
        ];
    }

    /** @return array{value: string, label: string, description: string|null} */
    public static function location(Location $location): array
    {
        return [
            'value' => $location->public_id,
            'label' => $location->code.' - '.$location->name,
            'description' => $location->path,
        ];
    }
}
