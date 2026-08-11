<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\LocationResource;
use App\Models\Core\Location;
use App\Support\ExportQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('inventory.view');

        $stocks = $this->filteredQuery($request)
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Admin/Inventory/Stocks/Index', [
            'stocks' => StockResource::collection($stocks),
            'products' => ProductResource::collection(Product::query()->where('is_active', true)->orderBy('name')->get()),
            'locations' => LocationResource::collection(Location::query()->where('is_active', true)->orderBy('code')->get()),
            'filters' => $request->only(['location_id', 'product_id', 'low_stock', 'search']),
            'can' => ['export' => $request->user()?->can('inventory.export') ?? false],
        ]);
    }

    public function export(Request $request): Response|StreamedResponse
    {
        Gate::authorize('inventory.export');

        $format = strtolower((string) $request->input('format', 'csv'));
        $stamp = now()->format('Ymd-His');
        $export = ExportQuery::make($this->filteredQuery($request))
            ->defaultSort('id', 'desc')
            ->maxRows((int) config('exports.max_rows', 5000));

        $columns = [
            'product' => 'Product',
            'location' => 'Location',
            'quantity' => 'Quantity',
            'reserved_quantity' => 'Reserved',
            'available' => 'Available',
        ];

        $map = static fn (Stock $stock): array => [
            'product' => $stock->product?->name ?? '-',
            'location' => $stock->location?->code ?? '-',
            'quantity' => $stock->quantity,
            'reserved_quantity' => $stock->reserved_quantity,
            'available' => $stock->available,
        ];

        if ($format === 'pdf') {
            return $export->streamPdf('Stocks', $columns, $map, "stocks-export-{$stamp}.pdf");
        }

        return $export->streamCsv($columns, $map, "stocks-export-{$stamp}.csv");
    }

    public function movements(Request $request): InertiaResponse
    {
        Gate::authorize('inventory.view');

        $movements = $this->filteredMovementsQuery($request)
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Admin/Inventory/Movements/Index', [
            'movements' => StockMovementResource::collection($movements),
            'products' => ProductResource::collection(Product::query()->where('is_active', true)->orderBy('name')->get()),
            'locations' => LocationResource::collection(Location::query()->where('is_active', true)->orderBy('code')->get()),
            'filters' => $request->only(['product_id', 'movement_type', 'location_id', 'search']),
            'can' => ['export' => $request->user()?->can('inventory.export') ?? false],
        ]);
    }

    public function movementsExport(Request $request): Response|StreamedResponse
    {
        Gate::authorize('inventory.export');

        $format = strtolower((string) $request->input('format', 'csv'));
        $stamp = now()->format('Ymd-His');
        $export = ExportQuery::make($this->filteredMovementsQuery($request))
            ->defaultSort('created_at', 'desc')
            ->maxRows((int) config('exports.max_rows', 5000));

        $columns = [
            'movement_type' => 'Type',
            'product' => 'Product',
            'from_location' => 'From',
            'to_location' => 'To',
            'quantity' => 'Quantity',
            'balance_after' => 'Balance',
            'note' => 'Note',
            'created_at' => 'Date',
        ];

        $map = static fn (StockMovement $movement): array => [
            'movement_type' => $movement->movement_type,
            'product' => $movement->product?->name ?? '-',
            'from_location' => $movement->fromLocation?->name ?? '-',
            'to_location' => $movement->toLocation?->name ?? '-',
            'quantity' => $movement->quantity,
            'balance_after' => $movement->balance_after,
            'note' => $movement->note ?? '',
            'created_at' => $movement->created_at,
        ];

        if ($format === 'pdf') {
            return $export->streamPdf('Stock Movements', $columns, $map, "stock-movements-export-{$stamp}.pdf");
        }

        return $export->streamCsv($columns, $map, "stock-movements-export-{$stamp}.csv");
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

    /** @return Builder<Stock> */
    private function filteredQuery(Request $request): Builder
    {
        return Stock::query()
            ->with(['product', 'location'])
            ->when($request->input('location_id'), fn (Builder $q, $v) => $q->where('location_id', $v))
            ->when($request->input('product_id'), fn (Builder $q, $v) => $q->where('product_id', $v))
            ->when($request->boolean('low_stock'), fn (Builder $q) => $q->whereRaw('quantity <= (SELECT min_stock FROM products WHERE products.id = stocks.product_id)'))
            ->when(trim((string) $request->input('search')) !== '', function (Builder $q) use ($request): void {
                $term = '%'.trim((string) $request->input('search')).'%';
                $q->where(function (Builder $sq) use ($term): void {
                    $sq->whereHas('product', fn (Builder $pq) => $pq->where('name', 'like', $term)->orWhere('sku', 'like', $term))
                        ->orWhereHas('location', fn (Builder $lq) => $lq->where('name', 'like', $term)->orWhere('code', 'like', $term));
                });
            })
            ->latest();
    }

    /** @return Builder<StockMovement> */
    private function filteredMovementsQuery(Request $request): Builder
    {
        return StockMovement::query()
            ->with(['product', 'fromLocation', 'toLocation'])
            ->when($request->input('product_id'), fn (Builder $q, $v) => $q->where('product_id', $v))
            ->when($request->input('movement_type'), fn (Builder $q, $v) => $q->where('movement_type', $v))
            ->when($request->input('location_id'), fn (Builder $q, $v) => $q->where(function (Builder $sq) use ($v): void {
                $sq->where('from_location_id', $v)->orWhere('to_location_id', $v);
            }))
            ->when(trim((string) $request->input('search')) !== '', function (Builder $q) use ($request): void {
                $term = '%'.trim((string) $request->input('search')).'%';
                $q->where(function (Builder $sq) use ($term): void {
                    $sq->where('movement_type', 'like', $term)
                        ->orWhere('note', 'like', $term)
                        ->orWhereHas('product', fn (Builder $pq) => $pq->where('name', 'like', $term)->orWhere('sku', 'like', $term))
                        ->orWhereHas('fromLocation', fn (Builder $lq) => $lq->where('name', 'like', $term)->orWhere('code', 'like', $term))
                        ->orWhereHas('toLocation', fn (Builder $lq) => $lq->where('name', 'like', $term)->orWhere('code', 'like', $term));
                });
            })
            ->latest();
    }
}
