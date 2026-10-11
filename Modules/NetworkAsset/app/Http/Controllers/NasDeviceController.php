<?php

namespace Modules\NetworkAsset\Http\Controllers;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Services\Core\CompanyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\NetworkAsset\Http\Requests\StoreNasDeviceRequest;
use Modules\NetworkAsset\Http\Requests\UpdateNasDeviceRequest;
use Modules\NetworkAsset\Http\Resources\NasDeviceResource;
use Modules\NetworkAsset\Http\Resources\NetworkAccountResource;
use Modules\NetworkAsset\Jobs\SyncNasDeviceJob;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Services\NasDeviceService;

class NasDeviceController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['routeros_version', 'last_checked_at', 'created_at'];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('network_asset.nas.view');

        $nasDevices = $this->sorted(
            NasDevice::query()
                ->with('networkAsset.location')
                ->withCount(['networkAccounts', 'networkAccounts as accounts_failed_count' => fn (Builder $accounts) => $accounts->where('sync_status', 'failed')])
                ->when($request->string('search')->trim()->value(), function (Builder $query, string $term): void {
                    $like = '%'.addcslashes($term, '%_\\').'%';
                    $query->whereHas('networkAsset', fn (Builder $asset) => $asset->where(fn (Builder $match) => $match
                        ->where('code', 'like', $like)
                        ->orWhere('name', 'like', $like)
                        ->orWhere('management_ip', 'like', $like)));
                }),
            $request,
        )->paginate($this->perPage($request))->withQueryString();

        return Inertia::render('Admin/NasDevices/Index', [
            'nasDevices' => NasDeviceResource::collection($nasDevices),
            'filters' => [
                'search' => $request->input('search'),
                'sort_by' => $request->input('sort_by', $request->input('sort')),
                'sort_dir' => $request->input('sort_dir', $request->input('direction')),
                'per_page' => $request->input('per_page'),
            ],
            'can' => ['create' => $request->user()?->can('network_asset.nas.manage') ?? false],
        ]);
    }

    public function create(): InertiaResponse
    {
        Gate::authorize('network_asset.nas.manage');

        return Inertia::render('Admin/NasDevices/Create', ['routerAssets' => $this->unregisteredRouters()]);
    }

    public function store(StoreNasDeviceRequest $request): RedirectResponse
    {
        $nasDevice = NasDeviceService::create($request->validated());

        return redirect()->route('admin.nas-devices.show', $nasDevice)->with('success', 'NAS router registered.');
    }

    public function show(Request $request, NasDevice $nasDevice): InertiaResponse
    {
        Gate::authorize('network_asset.nas.view');

        $nasDevice->load('networkAsset.location')->loadCount([
            'networkAccounts',
            'networkAccounts as accounts_failed_count' => fn (Builder $accounts) => $accounts->where('sync_status', 'failed'),
        ]);

        $accounts = $nasDevice->networkAccounts()
            ->with('subscription.customer:id,name')
            ->orderBy('username')
            ->paginate($this->perPage($request))
            ->withQueryString();
        $accounts->getCollection()->each(fn ($account) => $account->setRelation('nasDevice', $nasDevice));

        $canManage = $request->user()?->can('network_asset.nas.manage') ?? false;

        return Inertia::render('Admin/NasDevices/Show', [
            'nasDevice' => (new NasDeviceResource($nasDevice))->resolve(),
            'accounts' => NetworkAccountResource::collection($accounts),
            'unmanagedUsernames' => $nasDevice->unmanaged_usernames ?? [],
            'isolirUrl' => route('isolir.show', CompanyService::current()?->code),
            'installEnabled' => (bool) config('services.routeros.enabled'),
            'can' => ['update' => $canManage, 'check' => $canManage],
        ]);
    }

    public function edit(NasDevice $nasDevice): InertiaResponse
    {
        Gate::authorize('network_asset.nas.manage');

        $nasDevice->load('networkAsset.location');
        $asset = $nasDevice->networkAsset;

        return Inertia::render('Admin/NasDevices/Edit', [
            'routerAssets' => [[
                'id' => $asset->id,
                'code' => $asset->code,
                'name' => $asset->name,
                'management_ip' => $asset->getAttribute('management_ip'),
                'location_name' => $asset->location?->getAttribute('name'),
            ]],
            'nasDevice' => (new NasDeviceResource($nasDevice))->resolve(),
        ]);
    }

    public function update(UpdateNasDeviceRequest $request, NasDevice $nasDevice): RedirectResponse
    {
        NasDeviceService::update($nasDevice, $request->validated());

        return redirect()->route('admin.nas-devices.show', $nasDevice)->with('success', 'NAS router updated.');
    }

    public function destroy(NasDevice $nasDevice): RedirectResponse
    {
        Gate::authorize('network_asset.nas.manage');

        NasDeviceService::delete($nasDevice);

        return redirect()->route('admin.nas-devices.index')->with('success', 'NAS router removed.');
    }

    /** "Check now": queues the sync job for this router. */
    public function check(NasDevice $nasDevice): RedirectResponse
    {
        Gate::authorize('network_asset.nas.manage');

        SyncNasDeviceJob::dispatch((int) $nasDevice->company_id, $nasDevice->id);

        return back()->with('success', 'Router check queued.');
    }

    /** @return list<array{id: int, code: string, name: string, management_ip: ?string, location_name: ?string}> */
    private function unregisteredRouters(): array
    {
        return NetworkAsset::query()
            ->with('location:id,name')
            ->where('asset_type', 'router')
            ->whereNotNull('management_ip')
            ->whereNotIn('id', NasDevice::query()->select('network_asset_id'))
            ->orderBy('code')
            ->limit(500)
            ->get()
            ->map(fn (NetworkAsset $asset): array => [
                'id' => $asset->id,
                'code' => $asset->code,
                'name' => $asset->name,
                'management_ip' => $asset->getAttribute('management_ip'),
                'location_name' => $asset->location?->getAttribute('name'),
            ])
            ->all();
    }

    /**
     * @param  Builder<NasDevice>  $query
     * @return Builder<NasDevice>
     */
    private function sorted(Builder $query, Request $request): Builder
    {
        $sort = $request->input('sort_by', $request->input('sort'));

        if (! in_array($sort, ['code', 'name'], true)) {
            return $this->applySort($query, $request, 'created_at', 'desc');
        }

        // The asset's own code and name: ordered through a subquery, so the page keeps its counts.
        $dir = strtolower((string) $request->input('sort_dir', $request->input('direction', 'asc'))) === 'desc' ? 'desc' : 'asc';

        return $query->orderBy(NetworkAsset::query()->select($sort)->whereColumn('network_assets.id', 'nas_devices.network_asset_id'), $dir);
    }
}
