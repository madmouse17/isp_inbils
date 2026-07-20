<?php

namespace Modules\NetworkAsset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\LocationResource;
use App\Models\Core\Location;
use App\Services\Core\CompanyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Inventory\Http\Resources\ProductResource;
use Modules\Inventory\Models\Product;
use Modules\NetworkAsset\Http\Requests\StoreNetworkAssetRequest;
use Modules\NetworkAsset\Http\Requests\UpdateNetworkAssetRequest;
use Modules\NetworkAsset\Http\Resources\NetworkAssetResource;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Services\NetworkAssetService;

class NetworkAssetController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', NetworkAsset::class);

        $assets = NetworkAsset::query()
            ->with(['location', 'customer', 'subscription'])
            ->when($request->input('asset_type'), fn ($q, $v) => $q->where('asset_type', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('location_id'), fn ($q, $v) => $q->where('location_id', $v))
            ->when($request->input('search'), fn ($q, $v) => $q->where(fn ($sq) => $sq
                ->where('serial_number', 'like', "%{$v}%")
                ->orWhere('mac_address', 'like', "%{$v}%")
                ->orWhere('ip_address', 'like', "%{$v}%")
                ->orWhere('code', 'like', "%{$v}%")
                ->orWhere('name', 'like', "%{$v}%")))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Admin/NetworkAssets/Index', [
            'assets' => NetworkAssetResource::collection($assets),
            'locations' => LocationResource::collection(Location::query()->where('is_active', true)->orderBy('code')->get()),
            'filters' => $request->only(['asset_type', 'status', 'location_id', 'search']),
            'can' => ['create' => $request->user()?->can('network_asset.create') ?? false],
        ]);
    }

    public function create(): InertiaResponse
    {
        Gate::authorize('create', NetworkAsset::class);

        return Inertia::render('Admin/NetworkAssets/Create', [
            'locations' => LocationResource::collection(Location::query()->where('is_active', true)->orderBy('code')->get()),
            'products' => ProductResource::collection(Product::query()->where('is_active', true)->orderBy('name')->get()),
        ]);
    }

    public function store(StoreNetworkAssetRequest $request): RedirectResponse
    {
        Gate::authorize('store', NetworkAsset::class);

        $data = $request->validated();
        $data['code'] = NetworkAssetService::generateCode();
        $data['status'] = $data['status'] ?? 'available';

        NetworkAsset::create($data);

        return redirect()->route('admin.network-assets.index')->with('success', 'Network asset created.');
    }

    public function show(NetworkAsset $asset): InertiaResponse
    {
        Gate::authorize('view', $asset);
        $asset->load(['product', 'location', 'customer', 'subscription', 'installations.location']);

        return Inertia::render('Admin/NetworkAssets/Show', [
            'asset' => new NetworkAssetResource($asset),
            'locations' => LocationResource::collection(Location::query()->where('is_active', true)->orderBy('code')->get()),
        ]);
    }

    public function edit(NetworkAsset $asset): InertiaResponse
    {
        Gate::authorize('edit', $asset);
        $asset->load(['location', 'customer', 'subscription']);

        return Inertia::render('Admin/NetworkAssets/Edit', [
            'asset' => new NetworkAssetResource($asset),
            'locations' => LocationResource::collection(Location::query()->where('is_active', true)->orderBy('code')->get()),
            'products' => ProductResource::collection(Product::query()->where('is_active', true)->orderBy('name')->get()),
        ]);
    }

    public function update(UpdateNetworkAssetRequest $request, NetworkAsset $asset): RedirectResponse
    {
        Gate::authorize('update', $asset);

        $data = $request->validated();
        unset($data['status'], $data['code']);

        $asset->update($data);

        return redirect()->route('admin.network-assets.index')->with('success', 'Network asset updated.');
    }

    public function destroy(NetworkAsset $asset): RedirectResponse
    {
        Gate::authorize('delete', $asset);

        abort_if(in_array($asset->status, ['installed', 'maintenance']), 422, 'Cannot delete installed/maintenance asset. Remove first.');

        $asset->delete();

        return back()->with('success', 'Network asset deleted.');
    }

    public function install(Request $request, NetworkAsset $asset): RedirectResponse
    {
        Gate::authorize('network_asset.install');

        $request->validate([
            'location_id' => ['required', Rule::exists('locations', 'id')->where('company_id', CompanyService::currentId())],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'subscription_id' => ['nullable', 'exists:service_subscriptions,id'],
            'spk_id' => ['nullable', 'integer'],
        ]);

        NetworkAssetService::install(
            $asset,
            $request->integer('location_id'),
            $request->integer('customer_id'),
            $request->integer('subscription_id'),
            $request->integer('spk_id'),
        );

        return back()->with('success', 'Asset installed.');
    }

    public function remove(Request $request, NetworkAsset $asset): RedirectResponse
    {
        Gate::authorize('network_asset.remove');

        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        NetworkAssetService::remove($asset, $request->input('reason'));

        return back()->with('success', 'Asset removed.');
    }

    public function maintenance(Request $request, NetworkAsset $asset): RedirectResponse
    {
        Gate::authorize('network_asset.maintenance');

        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        NetworkAssetService::setMaintenance($asset, $request->input('reason'));

        return back()->with('success', 'Asset set to maintenance.');
    }

    public function resume(Request $request, NetworkAsset $asset): RedirectResponse
    {
        Gate::authorize('network_asset.maintenance');

        NetworkAssetService::resume($asset);

        return back()->with('success', 'Asset resumed.');
    }

    public function damage(Request $request, NetworkAsset $asset): RedirectResponse
    {
        Gate::authorize('network_asset.repair');

        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        NetworkAssetService::setDamaged($asset, $request->input('reason'));

        return back()->with('success', 'Asset marked damaged.');
    }

    public function repair(Request $request, NetworkAsset $asset): RedirectResponse
    {
        Gate::authorize('network_asset.repair');

        NetworkAssetService::repair($asset);

        return back()->with('success', 'Asset repaired.');
    }

    public function retire(Request $request, NetworkAsset $asset): RedirectResponse
    {
        Gate::authorize('network_asset.retire');

        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        NetworkAssetService::retire($asset, $request->input('reason'));

        return back()->with('success', 'Asset retired.');
    }

    public function trace(Request $request): InertiaResponse
    {
        Gate::authorize('network_asset.view');

        $results = collect();
        if ($request->filled('search')) {
            $search = $request->input('search');
            $results = NetworkAsset::query()
                ->with(['location', 'customer', 'subscription'])
                ->where(fn ($q) => $q
                    ->where('serial_number', 'like', "%{$search}%")
                    ->orWhere('mac_address', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%"))
                ->get();
        }

        if ($request->filled('customer_id')) {
            $results = NetworkAsset::query()
                ->with(['location', 'customer', 'subscription'])
                ->where('customer_id', $request->integer('customer_id'))
                ->get();
        }

        return Inertia::render('Admin/NetworkAssets/Trace', [
            'results' => NetworkAssetResource::collection($results)->resolve(),
            'search' => $request->input('search', ''),
            'customer_id' => $request->input('customer_id', ''),
        ]);
    }
}
