<?php

namespace Modules\Service\Http\Controllers;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Service\Http\Requests\StoreServicePackageCategoryRequest;
use Modules\Service\Http\Requests\UpdateServicePackageCategoryRequest;
use Modules\Service\Http\Resources\ServicePackageCategoryResource;
use Modules\Service\Models\ServicePackageCategory;

class ServicePackageCategoryController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['code', 'name', 'sort_order', 'is_active', 'created_at'];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', ServicePackageCategory::class);

        $categories = ServicePackageCategory::query()
            ->withCount(['servicePackages' => fn (Builder $query) => $query->withTrashed()])
            ->when($request->filled('is_active'), fn (Builder $query) => $query->where('is_active', $request->boolean('is_active')))
            ->when(trim((string) $request->input('search')) !== '', function (Builder $query) use ($request): void {
                $term = '%'.addcslashes(trim((string) $request->input('search')), '\\%_').'%';
                $query->where(fn (Builder $sub) => $sub->where('code', 'like', $term)->orWhere('name', 'like', $term));
            })
            ->tap(fn (Builder $query) => $this->applySort($query, $request, 'sort_order'))
            ->orderBy('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return Inertia::render('Admin/Service/PackageCategories/Index', [
            'packageCategories' => ServicePackageCategoryResource::collection($categories),
            'filters' => $request->only(['is_active', 'search', 'sort', 'direction', 'per_page']),
            'can' => [
                'create' => (bool) ($request->user()?->can('service.create') || $request->user()?->can('service.manage')),
                'update' => (bool) ($request->user()?->can('service.update') || $request->user()?->can('service.manage')),
                'delete' => (bool) ($request->user()?->can('service.delete') || $request->user()?->can('service.manage')),
            ],
        ]);
    }

    public function store(StoreServicePackageCategoryRequest $request): RedirectResponse
    {
        Gate::authorize('create', ServicePackageCategory::class);
        ServicePackageCategory::query()->create($request->validated());

        return back()->with('success', 'Kategori paket dibuat.');
    }

    public function update(UpdateServicePackageCategoryRequest $request, ServicePackageCategory $servicePackageCategory): RedirectResponse
    {
        Gate::authorize('update', $servicePackageCategory);
        $servicePackageCategory->update($request->validated());

        return back()->with('success', 'Kategori paket diperbarui.');
    }

    public function destroy(ServicePackageCategory $servicePackageCategory): RedirectResponse
    {
        Gate::authorize('delete', $servicePackageCategory);

        // An old version keeps the category it was sold in, and its foreign key would refuse the delete, so the versions
        // tell which packages ever used the category. Every package has a version with its current one.
        $used = DB::table('service_package_versions')->where('service_package_category_id', $servicePackageCategory->id)->distinct()->count('service_package_id');

        if ($used > 0) {
            throw ValidationException::withMessages([
                'category' => "Kategori masih dipakai {$used} paket. Nonaktifkan saja.",
            ]);
        }

        $servicePackageCategory->delete();

        return back()->with('success', 'Kategori paket dihapus.');
    }
}
