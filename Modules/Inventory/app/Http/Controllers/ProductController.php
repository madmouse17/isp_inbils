<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Support\ExportQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Inventory\Http\Requests\StoreProductRequest;
use Modules\Inventory\Http\Requests\UpdateProductRequest;
use Modules\Inventory\Http\Resources\CategoryResource;
use Modules\Inventory\Http\Resources\ProductResource;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['name', 'sku', 'created_at'];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', Product::class);

        $products = $this->filteredQuery($request)
            ->paginate($this->perPage($request))
            ->withQueryString();

        return Inertia::render('Admin/Inventory/Products/Index', [
            'products' => ProductResource::collection($products),
            'categories' => CategoryResource::collection(Category::query()->with('unit')->where('is_active', true)->orderBy('name')->get()),
            'filters' => $request->only(['category_id', 'is_active', 'search', 'sort', 'direction', 'per_page']),
            'can' => [
                'create' => $request->user()?->can('inventory.create') ?? false,
                'export' => $request->user()?->can('inventory.export') ?? false,
            ],
        ]);
    }

    public function create(Request $request): InertiaResponse
    {
        Gate::authorize('create', Product::class);

        return Inertia::render('Admin/Inventory/Products/Create', [
            'categories' => CategoryResource::collection(Category::query()->with('unit')->where('is_active', true)->orderBy('name')->get()),
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        Gate::authorize('store', Product::class);
        Product::create($request->validated());

        return redirect()->route('admin.products.index')->with('success', 'Product created.');
    }

    public function show(Product $product): InertiaResponse
    {
        Gate::authorize('view', $product);
        $product->load(['category', 'unit', 'stocks.location', 'movements' => fn ($q) => $q->latest()->limit(20)]);

        return Inertia::render('Admin/Inventory/Products/Show', [
            'product' => new ProductResource($product),
        ]);
    }

    public function edit(Product $product): InertiaResponse
    {
        Gate::authorize('edit', $product);
        $product->load(['category', 'unit']);

        return Inertia::render('Admin/Inventory/Products/Edit', [
            'product' => new ProductResource($product),
            'categories' => CategoryResource::collection(Category::query()->with('unit')->where('is_active', true)->orderBy('name')->get()),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);
        $product->update($request->validated());

        return redirect()->route('admin.products.index')->with('success', 'Product updated.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        Gate::authorize('delete', $product);

        if ($product->stocks()->where('quantity', '>', 0)->exists()) {
            return back()->withErrors(['product' => 'Cannot delete product with stock.']);
        }

        $product->delete();

        return back()->with('success', 'Product deleted.');
    }

    public function export(Request $request): Response|StreamedResponse
    {
        Gate::authorize('inventory.export');

        $format = strtolower((string) $request->input('format', 'csv'));
        $stamp = now()->format('Ymd-His');
        $export = ExportQuery::make($this->filteredQuery($request))
            ->maxRows((int) config('exports.max_rows', 5000));

        $columns = [
            'sku' => 'SKU',
            'name' => 'Name',
            'category' => 'Category',
            'unit' => 'Unit',
            'sell_price' => 'SellPrice',
            'cost_price' => 'CostPrice',
            'min_stock' => 'MinStock',
            'is_active' => 'IsActive',
        ];

        $map = static fn (Product $p): array => [
            'sku' => $p->sku,
            'name' => $p->name,
            'category' => $p->category?->name ?? '',
            'unit' => $p->unit?->symbol ?? '',
            'sell_price' => $p->sell_price ?? '',
            'cost_price' => $p->cost_price ?? '',
            'min_stock' => $p->min_stock,
            'is_active' => $p->is_active ? 'Yes' : 'No',
        ];

        if ($format === 'pdf') {
            return $export->streamPdf('Products', $columns, $map, "products-export-{$stamp}.pdf");
        }

        return $export->streamCsv($columns, $map, "products-export-{$stamp}.csv");
    }

    /**
     * @return Builder<Product>
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = Product::query()
            ->with(['category', 'unit'])
            ->when($request->input('category_id'), fn (Builder $q, $v) => $q->where('category_id', $v))
            ->when($request->filled('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->input('search'), function (Builder $q, string $v): void {
                $term = trim($v);
                if ($term === '') {
                    return;
                }

                $like = '%'.$term.'%';
                $q->where(function (Builder $sq) use ($like): void {
                    $sq->where('name', 'like', $like)
                        ->orWhere('sku', 'like', $like);
                });
            });

        return $this->applySort($query, $request, 'created_at', 'desc');
    }
}
