<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Services\Core\LifecycleService;
use App\Support\ExportQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Inventory\Http\Requests\StoreCategoryRequest;
use Modules\Inventory\Http\Requests\UpdateCategoryRequest;
use Modules\Inventory\Http\Resources\CategoryResource;
use Modules\Inventory\Http\Resources\UnitResource;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CategoryController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['name', 'code', 'created_at'];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', Category::class);

        $categories = $this->filteredQuery($request)
            ->withReferenceCounts()
            ->paginate($this->perPage($request))
            ->withQueryString();

        return Inertia::render('Admin/Inventory/Categories/Index', [
            'categories' => CategoryResource::collection($categories),
            // Active units only: a category that holds an inactive one carries it in its own `unit`.
            'units' => UnitResource::collection(Unit::query()->where('is_active', true)->orderBy('name')->get()),
            // Every category, for the parent picker: the page holds only one page of the table.
            'categoryOptions' => Category::query()->orderBy('code')->get(['public_id', 'code', 'name'])
                ->map(fn (Category $category): array => ['id' => $category->public_id, 'code' => $category->code, 'name' => $category->name])
                ->all(),
            'filters' => $request->only(['status', 'search', 'sort', 'direction', 'per_page']),
            'can' => [
                'create' => $request->user()?->can('inventory.create') ?? false,
                'export' => $request->user()?->can('inventory.export') ?? false,
                'update' => $request->user()?->can('inventory.update') ?? false,
                'delete' => $request->user()?->can('inventory.delete') ?? false,
            ],
        ]);
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        Gate::authorize('store', Category::class);
        Category::create($request->validated());

        return back()->with('success', 'Kategori berhasil dibuat.');
    }

    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        Gate::authorize('update', $category);

        $validated = $request->validated();

        DB::transaction(function () use ($category, $validated) {
            $category->update($validated);

            if (array_key_exists('unit_id', $validated)) {
                Product::query()
                    ->where('category_id', $category->id)
                    ->update(['unit_id' => $validated['unit_id']]);
            }
        });

        return back()->with('success', 'Kategori berhasil diubah.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        Gate::authorize('delete', $category);
        LifecycleService::destroyIfUnreferenced($category);

        return back()->with('success', 'Kategori barang dihapus permanen.');
    }

    public function export(Request $request): Response|StreamedResponse
    {
        Gate::authorize('inventory.export');

        $format = strtolower((string) $request->input('format', 'csv'));
        $stamp = now()->format('Ymd-His');
        $export = ExportQuery::make($this->filteredQuery($request))
            ->defaultSort('name', 'asc')
            ->maxRows((int) config('exports.max_rows', 5000));

        $columns = [
            'code' => 'Kode',
            'name' => 'Nama',
            'unit' => 'Satuan',
            'children_count' => 'Sub-kategori',
        ];

        $map = static fn (Category $c): array => [
            'code' => $c->code,
            'name' => $c->name,
            'unit' => $c->unit?->name ?? '',
            'children_count' => $c->children_count ?? 0,
        ];

        if ($format === 'pdf') {
            return $export->streamPdf('Kategori', $columns, $map, "categories-export-{$stamp}.pdf");
        }

        return $export->streamCsv($columns, $map, "categories-export-{$stamp}.csv");
    }

    /**
     * @return Builder<Category>
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = Category::query()
            ->with('unit')
            ->withCount('children')
            ->lifecycleStatus($request->input('status'))
            ->when($request->input('search'), function (Builder $q, string $v): void {
                $term = trim($v);
                if ($term === '') {
                    return;
                }

                $like = '%'.$term.'%';
                $q->where(function (Builder $sq) use ($like): void {
                    $sq->where('name', 'like', $like)
                        ->orWhere('code', 'like', $like);
                });
            });

        return $this->applySort($query, $request, 'name');
    }
}
