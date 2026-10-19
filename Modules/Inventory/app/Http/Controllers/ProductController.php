<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Services\Core\LifecycleService;
use App\Services\Core\NumberSequenceService;
use App\Support\ExportQuery;
use App\Support\OptimisticLock;
use App\Support\PublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Inventory\Http\Requests\StoreProductRequest;
use Modules\Inventory\Http\Requests\UpdateProductRequest;
use Modules\Inventory\Http\Resources\CategoryResource;
use Modules\Inventory\Http\Resources\ProductResource;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['name', 'sku', 'created_at'];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', Product::class);

        $products = $this->filteredQuery($request)
            ->withReferenceCounts()
            ->paginate($this->perPage($request))
            ->withQueryString();

        return Inertia::render('Admin/Inventory/Products/Index', [
            'products' => ProductResource::collection($products),
            'categories' => CategoryResource::collection(Category::query()->with('unit')->where('is_active', true)->orderBy('name')->get()),
            'filters' => $request->only(['category_id', 'status', 'search', 'sort', 'direction', 'per_page']),
            'can' => [
                'create' => $request->user()?->can('inventory.create') ?? false,
                'export' => $request->user()?->can('inventory.export') ?? false,
                'update' => $request->user()?->can('inventory.update') ?? false,
                'delete' => $request->user()?->can('inventory.delete') ?? false,
            ],
        ]);
    }

    public function create(Request $request): InertiaResponse
    {
        Gate::authorize('create', Product::class);

        return Inertia::render('Admin/Inventory/Products/Create', [
            'categories' => CategoryResource::collection($this->categoryOptions()),
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
            'categories' => CategoryResource::collection($this->categoryOptions($product)),
            'codeSequence' => $this->codeSequence(),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);
        DB::transaction(function () use ($request, $product): void {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            OptimisticLock::assert($locked, $request->string(OptimisticLock::FIELD)->toString());
            $locked->update($request->safe()->except(OptimisticLock::FIELD));
        });

        return redirect()->route('admin.products.index')->with('success', 'Barang berhasil diubah.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        Gate::authorize('delete', $product);

        LifecycleService::destroyIfUnreferenced($product, function () use ($product): void {
            if ($product->stocks()->where('quantity', '>', 0)->exists()) {
                throw ValidationException::withMessages(['lifecycle' => 'Barang masih punya stok.']);
            }
        });

        return back()->with('success', 'Barang dihapus permanen.');
    }

    /** @return array{manual: bool, preview: string} */
    private function codeSequence(): array
    {
        return [
            'manual' => NumberSequenceService::allowsManual('product'),
            'preview' => NumberSequenceService::peek('product'),
        ];
    }

    /**
     * The categories a product may be filed under: the active ones whose unit is active, because a product takes the
     * unit of its category, plus the category the product already has.
     *
     * @return Collection<int, Category>
     */
    private function categoryOptions(?Product $product = null): Collection
    {
        return Category::query()
            ->with('unit')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $open) => $open
                    ->where('is_active', true)
                    ->whereIn('unit_id', Unit::query()->where('is_active', true)->select('id')))
                ->orWhere('id', $product?->category_id))
            ->orderBy('name')
            ->get();
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
            ->when($request->input('category_id'), fn (Builder $q, $v) => $q->where('category_id', PublicId::resolveOne(Category::class, $v) ?? 0))
            ->lifecycleStatus($request->input('status'))
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
