<?php

namespace Modules\Service\Http\Controllers;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Support\ExportQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Service\Http\Requests\StoreServicePackageRequest;
use Modules\Service\Http\Requests\UpdateServicePackageRequest;
use Modules\Service\Http\Resources\BandwidthProfileResource;
use Modules\Service\Http\Resources\ServicePackageResource;
use Modules\Service\Http\Resources\SLATierResource;
use Modules\Service\Http\Resources\SpeedProfileResource;
use Modules\Service\Models\BandwidthProfile;
use Modules\Service\Models\ServicePackage;
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

        $packages = $this->filteredQuery($request)->paginate(10)->withQueryString();

        return Inertia::render('Admin/Service/Packages/Index', [
            'servicePackages' => ServicePackageResource::collection($packages),
            'slaTiers' => SLATierResource::collection(SLATier::query()->orderBy('name')->get()),
            'filters' => $request->only(['is_active', 'sla_tier_id', 'search', 'sort', 'direction']),
            'can' => [
                'create' => (bool) ($request->user()?->can('service.create') || $request->user()?->can('service.manage')),
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
        ServicePackage::query()->create($request->validated());

        return redirect()->route('admin.service-packages.index')->with('success', 'Service package created.');
    }

    public function show(ServicePackage $servicePackage): InertiaResponse
    {
        Gate::authorize('view', $servicePackage);

        return Inertia::render('Admin/Service/Packages/Show', [
            'servicePackage' => new ServicePackageResource($servicePackage->load(['bandwidthProfile', 'speedProfile', 'slaTier'])),
        ]);
    }

    public function edit(ServicePackage $servicePackage): InertiaResponse
    {
        Gate::authorize('edit', $servicePackage);

        return Inertia::render('Admin/Service/Packages/Edit', [
            'servicePackage' => new ServicePackageResource($servicePackage->load(['bandwidthProfile', 'speedProfile', 'slaTier'])),
            ...$this->formOptions(),
        ]);
    }

    public function update(UpdateServicePackageRequest $request, ServicePackage $servicePackage): RedirectResponse
    {
        Gate::authorize('update', $servicePackage);
        $servicePackage->update($request->validated());

        return redirect()->route('admin.service-packages.index')->with('success', 'Service package updated.');
    }

    public function destroy(ServicePackage $servicePackage): RedirectResponse
    {
        Gate::authorize('delete', $servicePackage);

        if ($this->hasActiveSubscriptions($servicePackage)) {
            return back()->withErrors(['service_package' => 'Service package has active subscriptions.']);
        }

        $servicePackage->delete();

        return back()->with('success', 'Service package deleted.');
    }

    public function deactivate(ServicePackage $pkg): RedirectResponse
    {
        Gate::authorize('update', $pkg);
        $pkg->update(['is_active' => false]);

        return back()->with('success', 'Service package deactivated.');
    }

    public function export(Request $request): HttpResponse|StreamedResponse
    {
        Gate::authorize('service.export');

        $export = ExportQuery::make($this->filteredQuery($request))
            ->defaultSort('name', 'asc')
            ->maxRows(ExportQuery::resolveMaxRows(config('exports.max_rows', ExportQuery::DEFAULT_MAX_ROWS)))
            ->fromRequest($request);

        $columns = [
            'code' => 'Code',
            'name' => 'Name',
            'bandwidth_profile' => 'Bandwidth',
            'speed_profile' => 'Speed',
            'sla_tier' => 'SLA',
            'price_mrc' => 'MRC',
            'price_otc' => 'OTC',
            'is_active' => 'Status',
        ];

        $map = static fn (ServicePackage $package): array => [
            'code' => $package->code,
            'name' => $package->name,
            'bandwidth_profile' => $package->bandwidthProfile?->name ?? '-',
            'speed_profile' => $package->speedProfile?->name ?? '-',
            'sla_tier' => $package->slaTier?->name ?? '-',
            'price_mrc' => $package->price_mrc,
            'price_otc' => $package->price_otc,
            'is_active' => $package->is_active ? 'Active' : 'Inactive',
        ];

        $filename = 'service-packages-export-'.now()->format('Ymd-His');

        if (strtolower((string) $request->input('format', 'csv')) === 'pdf') {
            return $export->streamPdf('Service Packages', $columns, $map, $filename.'.pdf');
        }

        return $export->streamCsv($columns, $map, $filename.'.csv');
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'bandwidthProfiles' => BandwidthProfileResource::collection(BandwidthProfile::query()->where('is_active', true)->orderBy('name')->get()),
            'speedProfiles' => SpeedProfileResource::collection(SpeedProfile::query()->where('is_active', true)->orderBy('name')->get()),
            'slaTiers' => SLATierResource::collection(SLATier::query()->where('is_active', true)->orderBy('name')->get()),
        ];
    }

    /** @return Builder<ServicePackage> */
    private function filteredQuery(Request $request): Builder
    {
        return ServicePackage::query()
            ->with(['bandwidthProfile', 'speedProfile', 'slaTier'])
            ->when($request->filled('is_active'), fn (Builder $query) => $query->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('sla_tier_id'), fn (Builder $query) => $query->where('sla_tier_id', $request->integer('sla_tier_id')))
            ->when(trim((string) $request->input('search')) !== '', function (Builder $query) use ($request): void {
                $term = '%'.trim((string) $request->input('search')).'%';
                $query->where(function (Builder $sub) use ($term): void {
                    $sub->where('code', 'like', $term)->orWhere('name', 'like', $term);
                });
            })
            ->tap(fn (Builder $query) => $this->applySort($query, $request, 'name'));
    }

    private function hasActiveSubscriptions(ServicePackage $servicePackage): bool
    {
        if (! Schema::hasTable('service_subscriptions')) {
            return false;
        }

        return $servicePackage->subscriptions()->whereIn('status', ['active', 'pending'])->exists();
    }
}
