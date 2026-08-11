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
use Modules\Service\Http\Requests\StoreSpeedProfileRequest;
use Modules\Service\Http\Requests\UpdateSpeedProfileRequest;
use Modules\Service\Http\Resources\SpeedProfileResource;
use Modules\Service\Models\SpeedProfile;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SpeedProfileController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['name', 'download_max_mbps', 'upload_max_mbps', 'burst_download_mbps', 'burst_upload_mbps', 'radius_profile_name', 'created_at'];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', SpeedProfile::class);

        $speedProfiles = $this->filteredQuery($request)->paginate(10)->withQueryString();

        return Inertia::render('Admin/Service/SpeedProfiles/Index', [
            'speedProfiles' => SpeedProfileResource::collection($speedProfiles),
            'filters' => $request->only(['search', 'sort', 'direction']),
            'can' => [
                'create' => (bool) ($request->user()?->can('service.create') || $request->user()?->can('service.manage')),
                'export' => (bool) $request->user()?->can('service.export'),
            ],
        ]);
    }

    public function create(): InertiaResponse
    {
        Gate::authorize('create', SpeedProfile::class);

        return Inertia::render('Admin/Service/SpeedProfiles/Create');
    }

    public function store(StoreSpeedProfileRequest $request): RedirectResponse
    {
        Gate::authorize('store', SpeedProfile::class);
        SpeedProfile::query()->create($request->validated());

        return redirect()->route('admin.speed-profiles.index')->with('success', 'Speed profile created.');
    }

    public function edit(SpeedProfile $speedProfile): InertiaResponse
    {
        Gate::authorize('edit', $speedProfile);

        return Inertia::render('Admin/Service/SpeedProfiles/Edit', ['speedProfile' => new SpeedProfileResource($speedProfile)]);
    }

    public function update(UpdateSpeedProfileRequest $request, SpeedProfile $speedProfile): RedirectResponse
    {
        Gate::authorize('update', $speedProfile);
        $speedProfile->update($request->validated());

        return redirect()->route('admin.speed-profiles.index')->with('success', 'Speed profile updated.');
    }

    public function destroy(SpeedProfile $speedProfile): RedirectResponse
    {
        Gate::authorize('delete', $speedProfile);
        $speedProfile->delete();

        return back()->with('success', 'Speed profile deleted.');
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
            'download_max_mbps' => 'Download Max',
            'upload_max_mbps' => 'Upload Max',
            'burst_download_mbps' => 'Burst Download',
            'burst_upload_mbps' => 'Burst Upload',
            'radius_profile_name' => 'RADIUS Profile',
            'is_active' => 'Status',
        ];

        $map = static fn (SpeedProfile $profile): array => [
            'name' => $profile->name,
            'download_max_mbps' => $profile->download_max_mbps,
            'upload_max_mbps' => $profile->upload_max_mbps,
            'burst_download_mbps' => $profile->burst_download_mbps ?? '-',
            'burst_upload_mbps' => $profile->burst_upload_mbps ?? '-',
            'radius_profile_name' => $profile->radius_profile_name ?? '-',
            'is_active' => $profile->is_active ? 'Active' : 'Inactive',
        ];

        $filename = 'speed-profiles-export-'.now()->format('Ymd-His');

        return strtolower((string) $request->input('format', 'csv')) === 'pdf'
            ? $export->streamPdf('Speed Profiles', $columns, $map, $filename.'.pdf')
            : $export->streamCsv($columns, $map, $filename.'.csv');
    }

    /** @return Builder<SpeedProfile> */
    private function filteredQuery(Request $request): Builder
    {
        return SpeedProfile::query()
            ->when(trim((string) $request->input('search')) !== '', function (Builder $query) use ($request): void {
                $term = '%'.trim((string) $request->input('search')).'%';
                $query->where(function (Builder $sub) use ($term): void {
                    $sub->where('name', 'like', $term)->orWhere('radius_profile_name', 'like', $term);
                });
            })
            ->tap(fn (Builder $query) => $this->applySort($query, $request, 'name'));
    }
}
