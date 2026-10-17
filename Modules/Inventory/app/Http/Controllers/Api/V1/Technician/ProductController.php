<?php

namespace Modules\Inventory\Http\Controllers\Api\V1\Technician;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Inventory\Http\Requests\Api\V1\ListTechnicianProductsRequest;
use Modules\Inventory\Http\Resources\Api\V1\TechnicianProductResource;
use Modules\Inventory\Models\Product;

/** The picker behind "add material" and "new request": active products the app can send back by id. */
class ProductController extends Controller
{
    public function index(ListTechnicianProductsRequest $request): AnonymousResourceCollection
    {
        $products = Product::query()
            ->where('is_active', true)
            // A serialized asset needs a chosen unit, which neither flow in the app can pick.
            ->where('type', '!=', 'asset')
            ->when($request->search(), function (Builder $query, string $term): void {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(fn (Builder $match) => $match->where('name', 'like', $like)->orWhere('sku', 'like', $like));
            })
            ->with('unit:id,name,symbol')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->perPage(), ['id', 'public_id', 'sku', 'name', 'unit_id']);

        return TechnicianProductResource::collection($products);
    }
}
