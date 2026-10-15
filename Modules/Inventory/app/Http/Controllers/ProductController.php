<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Services\Core\NumberSequenceService;
use App\Support\ExportQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
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
            'codeSequence' => $this->codeSequence(),
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        Gate::authorize('store', Product::class);
        DB::transaction(function () use ($request): void {
            $data = $request->validated();
            $data['sku'] = NumberSequenceService::resolve('product', $data['sku'] ?? null, 'sku');
            Product::create($data);
        });

        return redirect()->route('admin.products.index')->with('success', 'Barang berhasil dibuat.');
    }

    public function show(Product $product): InertiaResponse
    {
        Gate::authorize('view', $product);
        $product->load(['category', 'unit', 'stocks.location', 'movements' => fn ($q) => $q->latest('id')->limit(20)]);

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
            'codeSequence' => $this->codeSequence(),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);
        $product->update($request->validated());

        return redirect()->route('admin.products.index')->with('success', 'Barang berhasil diubah.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        Gate::authorize('delete', $product);

        if ($product->stocks()->where('quantity', '>', 0)->exists()) {
            return back()->withErrors(['product' => 'Barang tidak dapat dihapus karena masih memiliki stok.']);
        }

        $product->delete();

        return back()->with('success', 'Barang berhasil dihapus.');
    }

    /** @return array{manual: bool, preview: string} */
    private function codeSequence(): array
    {
        return [
            'manual' => NumberSequenceService::allowsManual('product'),
            'preview' => NumberSequenceService::peek('product'),
        ];
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
            'name' => 'Nama',
            'category' => 'Kategori',
            'unit' => 'Satuan',
            'sell_price' => 'Harga Jual',
            'cost_price' => 'Harga Pokok',
            'min_stock' => 'Stok Minimum',
            'is_active' => 'Aktif',
        ];

        $map = static fn (Product $p): array => [
            'sku' => $p->sku,
            'name' => $p->name,
            'category' => $p->category?->name ?? '',
            'unit' => $p->unit?->symbol ?? '',
            'sell_price' => $p->sell_price ?? '',
            'cost_price' => $p->cost_price ?? '',
            'min_stock' => $p->min_stock,
            'is_active' => $p->is_active ? 'Ya' : 'Tidak',
        ];

        if ($format === 'pdf') {
            return $export->streamPdf('Barang', $columns, $map, "products-export-{$stamp}.pdf");
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
