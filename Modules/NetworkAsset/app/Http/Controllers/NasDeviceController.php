<?php

namespace Modules\NetworkAsset\Http\Controllers;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Services\Core\CompanyService;
use App\Services\Core\IntegrationConfig;
use App\Support\SearchOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\OptionalProp;
use Inertia\Response as InertiaResponse;
use Modules\NetworkAsset\Http\Requests\StoreNasDeviceRequest;
use Modules\NetworkAsset\Http\Requests\UpdateNasDeviceRequest;
use Modules\NetworkAsset\Http\Resources\NasDeviceResource;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Services\NasDeviceService;
use Modules\NetworkAsset\Services\Radius\RadiusDirectory;
use Modules\NetworkAsset\Services\Radius\RadiusException;
use Modules\NetworkAsset\Services\Radius\Realm;
use Modules\NetworkAsset\Support\AssetPanelProps;

class NasDeviceController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['nasname', 'radius_synced_at', 'created_at'];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('network_asset.nas.view');

        $nasDevices = $this->sorted(
            NasDevice::query()
                ->with('networkAsset.location')
                ->when($request->string('search')->trim()->value(), function (Builder $query, string $term): void {
                    $like = '%'.addcslashes($term, '%_\\').'%';
                    $query->where(fn (Builder $match) => $match
                        ->where('nasname', 'like', $like)
                        ->orWhereHas('networkAsset', fn (Builder $asset) => $asset->where(fn (Builder $assetMatch) => $assetMatch
                            ->where('code', 'like', $like)
                            ->orWhere('name', 'like', $like)
                            ->orWhere('management_ip', 'like', $like))));
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
            'radiusEnabled' => app(IntegrationConfig::class)->isEnabled('radius'),
            'can' => ['create' => $request->user()?->can('network_asset.nas.manage') ?? false],
        ]);
    }

    public function create(Request $request): InertiaResponse
    {
        Gate::authorize('network_asset.nas.manage');

        return Inertia::render('Admin/NasDevices/Create', [
            'routerAssetOptions' => $this->routerAssetOptions($request),
            'hasRouterAssets' => $this->unregisteredRouters()->exists(),
            'realm' => $this->realm(),
        ]);
    }

    public function store(StoreNasDeviceRequest $request): RedirectResponse
    {
        $nasDevice = NasDeviceService::create($request->validated());

        return $this->saved(redirect()->route('admin.nas-devices.show', $nasDevice), $nasDevice, 'Router NAS didaftarkan.');
    }

    public function show(Request $request, NasDevice $nasDevice, RadiusDirectory $radius): InertiaResponse
    {
        Gate::authorize('network_asset.nas.view');

        $nasDevice->load('networkAsset.location');

        return Inertia::render('Admin/NasDevices/Show', [
            'nasDevice' => (new NasDeviceResource($nasDevice))->resolve(),
            'sessions' => $this->sessions($radius, $nasDevice),
            'isolirUrl' => route('isolir.show', CompanyService::current()?->code),
            'radiusEnabled' => app(IntegrationConfig::class)->isEnabled('radius'),
            'can' => ['update' => $request->user()?->can('network_asset.nas.manage') ?? false],
            ...($nasDevice->networkAsset === null ? [] : AssetPanelProps::for($request, $nasDevice->networkAsset)),
        ]);
    }

    public function edit(NasDevice $nasDevice): InertiaResponse
    {
        Gate::authorize('network_asset.nas.manage');

        $nasDevice->load('networkAsset.location');
        $asset = $nasDevice->networkAsset;

        return Inertia::render('Admin/NasDevices/Edit', [
            'routerAssets' => [[
                'id' => $asset->public_id,
                'code' => $asset->code,
                'name' => $asset->name,
                'management_ip' => $asset->getAttribute('management_ip'),
                'location_name' => $asset->location?->getAttribute('name'),
            ]],
            'nasDevice' => (new NasDeviceResource($nasDevice))->resolve(),
            'realm' => $this->realm(),
        ]);
    }

    public function update(UpdateNasDeviceRequest $request, NasDevice $nasDevice): RedirectResponse
    {
        NasDeviceService::update($nasDevice, $request->validated());

        return $this->saved(redirect()->route('admin.nas-devices.show', $nasDevice), $nasDevice, 'Router NAS diperbarui.');
    }

    public function destroy(NasDevice $nasDevice): RedirectResponse
    {
        Gate::authorize('network_asset.nas.manage');

        $removed = NasDeviceService::delete($nasDevice);
        $redirect = redirect()->route('admin.nas-devices.index')->with('success', 'Router NAS dihapus.');

        return $removed ? $redirect : $redirect->with('warning', 'Baris klien RADIUS tidak dapat dihapus (RADIUS belum dikonfigurasi atau tidak terjangkau). Hapus dari tabel nas secara manual.');
    }

    /** A saved row that RADIUS does not know yet is not an error: the sync writes it once RADIUS can be reached. */
    private function saved(RedirectResponse $redirect, NasDevice $nasDevice, string $message): RedirectResponse
    {
        $redirect->with('success', $message);

        if ($nasDevice->last_error === null) {
            return $redirect;
        }

        // The sweep does nothing while the company has no RADIUS, so that case promises no retry.
        return $redirect->with('warning', app(IntegrationConfig::class)->isEnabled('radius')
            ? 'Tersimpan, tetapi RADIUS belum diperbarui. '.$nasDevice->last_error
            : 'Tersimpan, tetapi belum dikirim ke RADIUS karena RADIUS belum dikonfigurasi untuk perusahaan ini.');
    }

    private function realm(): ?string
    {
        return Realm::fromCode(CompanyService::current()?->code);
    }

    /**
     * Open sessions on the router, usernames linked to their subscription. Empty while RADIUS is off or unreachable.
     *
     * @return list<array<string, mixed>>
     */
    private function sessions(RadiusDirectory $radius, NasDevice $nasDevice): array
    {
        try {
            $sessions = $radius->sessionsOnNas((string) $nasDevice->nasname);
        } catch (RadiusException) {
            return [];
        }

        $subscriptions = NetworkAccount::query()
            ->whereIn('username', array_column($sessions, 'username'))
            ->with('subscription:id,public_id,code')
            ->get()
            ->keyBy('username');
        $label = $nasDevice->networkAsset === null ? null : $nasDevice->networkAsset->code.' - '.$nasDevice->networkAsset->name;

        return array_map(fn (array $session): array => [
            'session_id' => $session['session_id'],
            'nas_ip' => $session['nas_ip'],
            'nas_label' => $label,
            'framed_ip' => $session['framed_ip'],
            'started_at' => $session['started_at'],
            'updated_at' => $session['updated_at'],
            'username' => $session['username'],
            'subscription' => ($subscription = $subscriptions->get($session['username'])?->subscription) === null ? null : ['id' => $subscription->public_id, 'code' => $subscription->code],
        ], $sessions);
    }

    /**
     * Router assets with a management IP that are not a NAS router yet.
     *
     * @return Builder<NetworkAsset>
     */
    private function unregisteredRouters(): Builder
    {
        return NetworkAsset::query()
            ->where('asset_type', 'router')
            ->whereNotNull('management_ip')
            ->whereNotIn('id', NasDevice::query()->select('network_asset_id'));
    }

    /** Found by code, name or management IP; the option's description is the management IP, which the form copies into the NAS address. */
    private function routerAssetOptions(Request $request): OptionalProp
    {
        return SearchOptions::for(
            $request,
            'routerAsset',
            $this->unregisteredRouters()->with('location:id,name')->orderBy('code'),
            ['code', 'name', 'management_ip'],
            fn (NetworkAsset $asset): array => [
                'value' => $asset->public_id,
                'label' => collect([$asset->code, $asset->name, $asset->location?->getAttribute('name')])->filter()->implode(' - '),
                'description' => $asset->getAttribute('management_ip'),
            ],
        );
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
