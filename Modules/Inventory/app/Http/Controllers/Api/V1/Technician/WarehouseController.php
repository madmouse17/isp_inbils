<?php

namespace Modules\Inventory\Http\Controllers\Api\V1\Technician;

use App\Http\Controllers\Controller;
use App\Models\Core\Location;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Modules\Inventory\Http\Resources\Api\V1\TechnicianWarehouseResource;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\Stock;

/** Where a technician may ask stock from: active locations that hold some, as a request is approved against stock. */
class WarehouseController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('create', InventoryRequest::class);

        // shortcut: no paging, a company keeps stock in a handful of places; page it if that stops being true.
        $warehouses = Location::query()
            ->active()
            ->whereIn('id', Stock::query()->select('location_id'))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'code']);

        return TechnicianWarehouseResource::collection($warehouses);
    }
}
