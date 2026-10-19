<?php

namespace Modules\Service\Http\Controllers;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Services\Core\LifecycleService;
use App\Support\ExportQuery;
use App\Support\OptimisticLock;
use App\Support\PublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Service\Http\Requests\StoreServicePackageRequest;
use Modules\Service\Http\Requests\UpdateServicePackageRequest;
use Modules\Service\Http\Resources\BandwidthProfileResource;
use Modules\Service\Http\Resources\ServicePackageResource;
use Modules\Service\Http\Resources\ServicePackageVersionResource;
use Modules\Service\Http\Resources\SLATierResource;
use Modules\Service\Http\Resources\SpeedProfileResource;
use Modules\Service\Models\BandwidthProfile;
use Modules\Service\Models\ServicePackage;
use Modules\Service\Models\ServicePackageCategory;
use Modules\Service\Models\SLATier;
use Modules\Service\Models\SpeedProfile;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ServicePackageController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['code', 'name', 'price_mrc', 'price_otc', 'is_active', 'created_at'];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', ServicePackage::class);

        $packages = $this->filteredQuery($request)->withReferenceCounts()->paginate($this->perPage($request))->withQueryString();

        return Inertia::render('Admin/Service/Packages/Index', [
            'servicePackages' => ServicePackageResource::collection($packages),
            'slaTiers' => SLATierResource::collection(SLATier::query()->orderBy('name')->get()),
            'packageCategories' => $this->categoryOptions(ServicePackageCategory::query()),
            'filters' => $request->only(['status', 'sla_tier_id', 'service_package_category_id', 'search', 'sort', 'direction', 'per_page']),
            'can' => [
                'create' => (bool) ($request->user()?->can('service.create') || $request->user()?->can('service.manage')),
                'update' => (bool) ($request->user()?->can('service.update') || $request->user()?->can('service.manage')),
                'delete' => (bool) ($request->user()?->can('service.delete') || $request->user()?->can('service.manage')),
                'export' => (bool) $request->user()?->can('service.export'),
            ],
        ]);
    }

    public function create(): InertiaResponse
    {
        Gate::authorize('create', ServicePackage::class);

        return Inertia::render('Admin/Service/Packages/Create', $this->formOptions());
    }

    public function store(StoreServicePackageRequest $request): RedirectResponse
    {
        Gate::authorize('store', ServicePackage::class);
        DB::transaction(fn () => ServicePackage::query()->create($request->validated()));

        return redirect()->route('admin.service-packages.index')->with('success', 'Paket layanan dibuat.');
    }

    public function show(ServicePackage $servicePackage): InertiaResponse
    {
        Gate::authorize('view', $servicePackage);

        return Inertia::render('Admin/Service/Packages/Show', [
            'servicePackage' => new ServicePackageResource($servicePackage->load(['bandwidthProfile', 'speedProfile', 'slaTier', 'category'])),
            'versions' => ServicePackageVersionResource::collection(
                $servicePackage->versions()->reorder('version', 'desc')->with(['bandwidthProfile', 'speedProfile', 'slaTier', 'category'])->withCount('subscriptions')->get(),
            ),
        ]);
    }

    public function edit(ServicePackage $servicePackage): InertiaResponse
    {
        Gate::authorize('edit', $servicePackage);

        return Inertia::render('Admin/Service/Packages/Edit', [
            'servicePackage' => new ServicePackageResource($servicePackage->load(['bandwidthProfile', 'speedProfile', 'slaTier', 'category'])),
            ...$this->formOptions($servicePackage),
        ]);
    }

    public function update(UpdateServicePackageRequest $request, ServicePackage $servicePackage): RedirectResponse
    {
        Gate::authorize('update', $servicePackage);
        DB::transaction(function () use ($request, $servicePackage): void {
            $locked = ServicePackage::query()->lockForUpdate()->findOrFail($servicePackage->id);
            OptimisticLock::assert($locked, $request->string(OptimisticLock::FIELD)->toString());
            $locked->update($request->safe()->except(OptimisticLock::FIELD));
        });

        return redirect()->route('admin.service-packages.index')->with('success', 'Paket layanan diperbarui.');
    }

    public function destroy(ServicePackage $servicePackage): RedirectResponse
    {
        Gate::authorize('delete', $servicePackage);
        LifecycleService::destroyIfUnreferenced($servicePackage);

        return back()->with('success', 'Paket layanan dihapus permanen.');
    }

    public function export(Request $request): HttpResponse|StreamedResponse
    {
        Gate::authorize('service.export');

        $export = ExportQuery::make($this->filteredQuery($request))
            ->defaultSort('name', 'asc')
            ->maxRows(ExportQuery::resolveMaxRows(config('exports.max_rows', ExportQuery::DEFAULT_MAX_ROWS)))
            ->fromRequest($request);

        $columns = [
            'code' => 'Kode',
            'name' => 'Nama',
            'bandwidth_profile' => 'Bandwidth',
            'speed_profile' => 'Kecepatan',
            'sla_tier' => 'SLA',
            'category' => 'Kategori',
            'price_mrc' => 'Biaya bulanan',
            'price_otc' => 'Biaya pemasangan',
            'is_active' => 'Status',
        ];

        $map = static fn (ServicePackage $package): array => [
            'code' => $package->code,
            'name' => $package->name,
            'bandwidth_profile' => $package->bandwidthProfile?->name ?? '-',
            'speed_profile' => $package->speedProfile?->name ?? '-',
            'sla_tier' => $package->slaTier?->name ?? '-',
            'category' => $package->category?->name ?? '-',
            'price_mrc' => $package->price_mrc,
            'price_otc' => $package->price_otc,
            'is_active' => $package->is_active ? 'Aktif' : 'Nonaktif',
        ];

        $filename = 'service-packages-export-'.now()->format('Ymd-His');

        if (strtolower((string) $request->input('format', 'csv')) === 'pdf') {
            return $export->streamPdf('Paket Layanan', $columns, $map, $filename.'.pdf');
        }

        return $export->streamCsv($columns, $map, $filename.'.csv');
    }

    /** @return array<string, mixed> */
    private function formOptions(?ServicePackage $package = null): array
    {
        return [
            // A profile or tier that was deactivated stays selectable only for the package that already holds it.
            'bandwidthProfiles' => BandwidthProfileResource::collection($this->activeOrHeld(BandwidthProfile::query(), $package?->bandwidth_profile_id)),
            'speedProfiles' => SpeedProfileResource::collection($this->activeOrHeld(SpeedProfile::query(), $package?->speed_profile_id)),
            'slaTiers' => SLATierResource::collection($this->activeOrHeld(SLATier::query(), $package?->sla_tier_id)),
            // An inactive category stays selectable only for the package that already has it.
            'packageCategories' => $this->categoryOptions(ServicePackageCategory::query()
                ->where(fn (Builder $query) => $query->where('is_active', true)->orWhere('id', $package?->service_package_category_id))),
        ];
    }

    /** @return Collection<int, Model> */
    private function activeOrHeld(Builder $query, ?int $heldId): Collection
    {
        return $query->where(fn (Builder $active) => $active->where('is_active', true)->orWhere('id', $heldId))->orderBy('name')->get();
    }

    /**
     * Picker rows keyed by public id. Not `get(['public_id as id'])`: a model casts `id` to int, which turns a ULID into 1.
     *
     * @param  Builder<ServicePackageCategory>  $query
     * @return list<array{id: string, code: string, name: string, is_active: bool}>
     */
    private function categoryOptions(Builder $query): array
    {
        $columns = ['code', 'name', 'is_active'];

        return $query->orderBy('sort_order')->orderBy('id')->get(['public_id', ...$columns])
            ->map(fn (ServicePackageCategory $category): array => ['id' => $category->public_id, ...$category->only($columns)])
            ->all();
    }

    /** @return Builder<ServicePackage> */
    private function filteredQuery(Request $request): Builder
    {
        return ServicePackage::query()
            ->with(['bandwidthProfile', 'speedProfile', 'slaTier', 'category'])
            ->lifecycleStatus($request->input('status'))
            ->when($request->filled('sla_tier_id'), fn (Builder $query) => $query->where('sla_tier_id', PublicId::resolveOne(SLATier::class, $request->input('sla_tier_id')) ?? 0))
            ->when($request->filled('service_package_category_id'), fn (Builder $query) => $query->where('service_package_category_id', PublicId::resolveOne(ServicePackageCategory::class, $request->input('service_package_category_id')) ?? 0))
            ->when(trim((string) $request->input('search')) !== '', function (Builder $query) use ($request): void {
                $term = '%'.trim((string) $request->input('search')).'%';
                $query->where(function (Builder $sub) use ($term): void {
                    $sub->where('code', 'like', $term)->orWhere('name', 'like', $term);
                });
            })
            ->tap(fn (Builder $query) => $this->applySort($query, $request, 'name'));
    }
}
