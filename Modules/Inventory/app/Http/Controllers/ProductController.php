<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
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
use Modules\Inventory\Http\Resources\UnitResource;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;

class ProductController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', Product::class);

        $products = Product::query()
            ->with(['category', 'unit'])
            ->when($request->input('category_id'), fn ($q, $v) => $q->where('category_id', $v))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->input('search'), fn ($q, $v) => $q->where(fn ($sq) => $sq
                ->where('name', 'like', "%{$v}%")
                ->orWhere('sku', 'like', "%{$v}%")))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Admin/Inventory/Products/Index', [
            'products' => ProductResource::collection($products),
            'categories' => CategoryResource::collection(Category::query()->with('unit')->where('is_active', true)->orderBy('name')->get()),
            'units' => UnitResource::collection(Unit::query()->orderBy('name')->get()),
            'filters' => $request->only(['category_id', 'is_active', 'search']),
            'can' => ['create' => $request->user()?->can('inventory.create') ?? false],
        ]);
    }

    public function create(Request $request): InertiaResponse
    {
        Gate::authorize('create', Product::class);

        return Inertia::render('Admin/Inventory/Products/Create', [
            'categories' => CategoryResource::collection(Category::query()->with('unit')->where('is_active', true)->orderBy('name')->get()),
            'units' => UnitResource::collection(Unit::query()->orderBy('name')->get()),
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
            'units' => UnitResource::collection(Unit::query()->orderBy('name')->get()),
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

    public function export(Request $request): Response
    {
        Gate::authorize('inventory.export');

        $products = Product::query()->with(['category', 'unit'])->latest()->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=products.csv',
        ];

        $csv = "SKU,Name,Category,Unit,SellPrice,CostPrice,MinStock,IsActive\n";
        foreach ($products as $p) {
            $csv .= implode(',', [
                $p->sku, $p->name,
                $p->category?->name ?? '',
                $p->unit?->symbol ?? '',
                $p->sell_price ?? '', $p->cost_price ?? '',
                $p->min_stock, $p->is_active ? 'Yes' : 'No',
            ])."\n";
        }

        return response($csv, 200, $headers);
    }
}
