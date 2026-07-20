<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\LocationResource;
use App\Models\Core\Location;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Inventory\Http\Requests\StockAdjustRequest;
use Modules\Inventory\Http\Requests\StockIssueRequest;
use Modules\Inventory\Http\Requests\StockReceiveRequest;
use Modules\Inventory\Http\Requests\StockTransferRequest;
use Modules\Inventory\Http\Resources\ProductResource;
use Modules\Inventory\Http\Resources\StockMovementResource;
use Modules\Inventory\Http\Resources\StockResource;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\StockService;

class StockController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('inventory.view');

        $stocks = Stock::query()
            ->with(['product', 'location'])
            ->when($request->input('location_id'), fn ($q, $v) => $q->where('location_id', $v))
            ->when($request->input('product_id'), fn ($q, $v) => $q->where('product_id', $v))
            ->when($request->boolean('low_stock'), fn ($q) => $q->whereRaw('quantity <= (SELECT min_stock FROM products WHERE products.id = stocks.product_id)'))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Admin/Inventory/Stocks/Index', [
            'stocks' => StockResource::collection($stocks),
            'products' => ProductResource::collection(Product::query()->where('is_active', true)->orderBy('name')->get()),
            'locations' => LocationResource::collection(Location::query()->where('is_active', true)->orderBy('code')->get()),
            'filters' => $request->only(['location_id', 'product_id', 'low_stock']),
        ]);
    }

    public function movements(Request $request): InertiaResponse
    {
        Gate::authorize('inventory.view');

        $movements = StockMovement::query()
            ->with(['product', 'fromLocation', 'toLocation'])
            ->when($request->input('product_id'), fn ($q, $v) => $q->where('product_id', $v))
            ->when($request->input('movement_type'), fn ($q, $v) => $q->where('movement_type', $v))
            ->when($request->input('location_id'), fn ($q, $v) => $q->where(fn ($sq) => $sq
                ->where('from_location_id', $v)->orWhere('to_location_id', $v)))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Admin/Inventory/Movements/Index', [
            'movements' => StockMovementResource::collection($movements),
            'filters' => $request->only(['product_id', 'movement_type', 'location_id']),
        ]);
    }

    public function receive(StockReceiveRequest $request): RedirectResponse
    {
        Gate::authorize('inventory.stock.receive');
        StockService::receive(
            $request->integer('product_id'),
            $request->integer('location_id'),
            $request->float('quantity'),
            $request->input('note'),
            $request->input('reference_type'),
            $request->integer('reference_id'),
        );

        return back()->with('success', 'Stock received.');
    }

    public function issue(StockIssueRequest $request): RedirectResponse
    {
        Gate::authorize('inventory.stock.issue');
        StockService::issue(
            $request->integer('product_id'),
            $request->integer('location_id'),
            $request->float('quantity'),
            $request->input('note'),
            $request->input('reference_type'),
            $request->integer('reference_id'),
        );

        return back()->with('success', 'Stock issued.');
    }

    public function transfer(StockTransferRequest $request): RedirectResponse
    {
        Gate::authorize('inventory.stock.transfer');
        StockService::transfer(
            $request->integer('product_id'),
            $request->integer('from_location_id'),
            $request->integer('to_location_id'),
            $request->float('quantity'),
            $request->input('note'),
        );

        return back()->with('success', 'Stock transferred.');
    }

    public function adjust(StockAdjustRequest $request): RedirectResponse
    {
        Gate::authorize('inventory.stock.adjust');
        StockService::adjust(
            $request->integer('product_id'),
            $request->integer('location_id'),
            $request->float('new_quantity'),
            $request->input('note'),
        );

        return back()->with('success', 'Stock adjusted.');
    }

    public function find(Request $request): InertiaResponse
    {
        Gate::authorize('inventory.view');

        $results = collect();
        if ($request->filled('search')) {
            $search = $request->input('search');
            $products = Product::query()
                ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"))
                ->with(['stocks.location'])
                ->get();

            $results = $products->map(fn ($p) => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'stocks' => $p->stocks->map(fn ($s) => [
                    'location_name' => $s->location?->name ?? '-',
                    'location_path' => $s->location?->path ?? '-',
                    'quantity' => $s->quantity,
                    'reserved' => $s->reserved_quantity,
                    'available' => $s->available,
                ]),
            ]);
        }

        return Inertia::render('Admin/Inventory/Find', [
            'results' => $results->values(),
            'search' => $request->input('search', ''),
        ]);
    }
}
