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
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Service\Http\Requests\StoreBandwidthProfileRequest;
use Modules\Service\Http\Requests\UpdateBandwidthProfileRequest;
use Modules\Service\Http\Resources\BandwidthProfileResource;
use Modules\Service\Models\BandwidthProfile;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BandwidthProfileController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['name', 'download_mbps', 'upload_mbps', 'type', 'contention_ratio', 'created_at'];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', BandwidthProfile::class);

        $bandwidthProfiles = $this->filteredQuery($request)->paginate(10)->withQueryString();

        return Inertia::render('Admin/Service/BandwidthProfiles/Index', [
            'bandwidthProfiles' => BandwidthProfileResource::collection($bandwidthProfiles),
            'filters' => $request->only(['search', 'sort', 'direction']),
            'can' => [
                'create' => (bool) ($request->user()?->can('service.create') || $request->user()?->can('service.manage')),
                'export' => (bool) $request->user()?->can('service.export'),
            ],
        ]);
    }

    public function create(): InertiaResponse
    {
        Gate::authorize('create', BandwidthProfile::class);

        return Inertia::render('Admin/Service/BandwidthProfiles/Create');
    }

    public function store(StoreBandwidthProfileRequest $request): RedirectResponse
    {
        Gate::authorize('store', BandwidthProfile::class);
        BandwidthProfile::query()->create($request->validated());

        return redirect()->route('admin.bandwidth-profiles.index')->with('success', 'Bandwidth profile created.');
    }

    public function edit(BandwidthProfile $bandwidthProfile): InertiaResponse
    {
        Gate::authorize('edit', $bandwidthProfile);

        return Inertia::render('Admin/Service/BandwidthProfiles/Edit', ['bandwidthProfile' => new BandwidthProfileResource($bandwidthProfile)]);
    }

    public function update(UpdateBandwidthProfileRequest $request, BandwidthProfile $bandwidthProfile): RedirectResponse
    {
        Gate::authorize('update', $bandwidthProfile);
        $bandwidthProfile->update($request->validated());

        return redirect()->route('admin.bandwidth-profiles.index')->with('success', 'Bandwidth profile updated.');
    }

    public function destroy(BandwidthProfile $bandwidthProfile): RedirectResponse
    {
        Gate::authorize('delete', $bandwidthProfile);
        $bandwidthProfile->delete();

        return back()->with('success', 'Bandwidth profile deleted.');
    }

    public function export(Request $request): HttpResponse|StreamedResponse
    {
        Gate::authorize('service.export');

        $export = ExportQuery::make($this->filteredQuery($request))
            ->defaultSort('name', 'asc')
            ->fromRequest($request)
            ->maxRows((int) config('exports.max_rows', 5000));

        $columns = [
            'name' => 'Name',
            'download_mbps' => 'Download',
            'upload_mbps' => 'Upload',
            'type' => 'Type',
            'contention_ratio' => 'Contention',
            'is_active' => 'Status',
        ];

        $map = static fn (BandwidthProfile $profile): array => [
            'name' => $profile->name,
            'download_mbps' => $profile->download_mbps,
            'upload_mbps' => $profile->upload_mbps,
            'type' => $profile->type,
            'contention_ratio' => $profile->contention_ratio,
            'is_active' => $profile->is_active ? 'Active' : 'Inactive',
        ];

        $filename = 'bandwidth-profiles-export-'.now()->format('Ymd-His');

        return strtolower((string) $request->input('format', 'csv')) === 'pdf'
            ? $export->streamPdf('Bandwidth Profiles', $columns, $map, $filename.'.pdf')
            : $export->streamCsv($columns, $map, $filename.'.csv');
    }

    /** @return Builder<BandwidthProfile> */
    private function filteredQuery(Request $request): Builder
    {
        return BandwidthProfile::query()
            ->when(trim((string) $request->input('search')) !== '', function (Builder $query) use ($request): void {
                $term = '%'.trim((string) $request->input('search')).'%';
                $query->where(function (Builder $sub) use ($term): void {
                    $sub->where('name', 'like', $term)->orWhere('type', 'like', $term);
                });
            })
            ->tap(fn (Builder $query) => $this->applySort($query, $request, 'name'));
    }
}
