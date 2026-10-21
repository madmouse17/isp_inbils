<?php

namespace Modules\Inventory\Http\Controllers\Api\V1\Technician;

use App\Http\Controllers\Controller;
use App\Models\Core\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Modules\Inventory\Http\Resources\Api\V1\TechnicianWarehouseResource;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;

/** Where a technician may ask stock from: active locations that hold some, as a request is approved against stock. */
class WarehouseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('create', InventoryRequest::class);

        // Where the caller's latest request that named a warehouse went: the picker of the next one starts there.
        $lastUsed = (int) InventoryRequest::query()
            ->where('requested_by', $request->user()?->id)
            ->whereNotNull('warehouse_location_id')
            ->latest('id')
            ->value('warehouse_location_id');

        // shortcut: no paging, a company keeps stock in a handful of places; page it if that stops being true.
        $warehouses = Location::query()
            ->active()
            ->whereIn('id', Stock::query()->select('location_id'))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'public_id', 'name', 'code']);

        // What each place can fill, in one grouped query: the products the picker offers that are free to ask for there.
        $available = Stock::query()
            ->whereIn('location_id', $warehouses->modelKeys())
            ->whereRaw('quantity - reserved_quantity > 0')
            ->whereIn('product_id', Product::query()->select('id')->where('is_active', true)->where('type', '!=', 'asset'))
            ->groupBy('location_id')
            ->selectRaw('location_id, count(*) as products')
            ->pluck('products', 'location_id');

        $warehouses->each(fn (Location $location) => $location
            ->setAttribute('last_used', $location->id === $lastUsed)
            ->setAttribute('available_products', (int) $available->get($location->id, 0)));

        return TechnicianWarehouseResource::collection($warehouses);
    }
}
