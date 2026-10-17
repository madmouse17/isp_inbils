<?php

namespace Modules\NetworkAsset\Http\Controllers;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Support\ExportQuery;
use App\Support\PublicId;
use App\Support\SearchOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\OptionalProp;
use Inertia\Response as InertiaResponse;
use Modules\Inventory\Models\Product;
use Modules\Ipam\Services\IpAssignmentService;
use Modules\NetworkAsset\Http\Requests\InstallNetworkAssetRequest;
use Modules\NetworkAsset\Http\Requests\StoreNetworkAssetRequest;
use Modules\NetworkAsset\Http\Requests\UpdateNetworkAssetRequest;
use Modules\NetworkAsset\Http\Resources\NetworkAssetResource;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Services\NetworkAssetService;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NetworkAssetController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = [
        'code',
        'name',
        'asset_type',
        'serial_number',
        'status',
        'ownership',
        'created_at',
    ];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', NetworkAsset::class);

        /** @var Builder<Model> $assetQuery */
        $assetQuery = $this->filteredQuery($request)->with(['company:id,name']);

        $assets = $this->applySort(
            $assetQuery,
            $request,
            'created_at',
            'desc',
        )->paginate($this->perPage($request))->withQueryString();

        return Inertia::render('Admin/NetworkAssets/Index', [
            'assets' => NetworkAssetResource::collection($assets),
            'filters' => [
                'search' => $request->input('search'),
                'status' => $request->input('status'),
                'asset_type' => $request->input('asset_type'),
                'sort_by' => $request->input('sort_by', $request->input('sort')),
                'sort_dir' => $request->input('sort_dir', $request->input('direction')),
                'sort' => $request->input('sort_by', $request->input('sort')),
                'direction' => $request->input('sort_dir', $request->input('direction')),
                'per_page' => $request->input('per_page'),
            ],
            'can' => [
                'create' => $request->user()?->can('network_asset.create') ?? false,
                'export' => $request->user()?->can('network_asset.export')
                    ?? ($request->user()?->can('network_asset.view') ?? false),
            ],
        ]);
    }

    public function export(Request $request): Response|StreamedResponse
    {
        Gate::authorize('viewAny', NetworkAsset::class);

        if ($request->user() && ! $request->user()->can('network_asset.export')) {
            $exportPermExists = Permission::query()
                ->where('name', 'network_asset.export')
                ->where('guard_name', 'web')
                ->exists();
            if ($exportPermExists) {
                abort(403);
            }
        }

        $format = strtolower((string) $request->input('format', 'csv'));
        $stamp = now()->format('Ymd-His');

        /** @var Builder<Model> $exportQuery */
        $exportQuery = $this->filteredQuery($request);

        $export = ExportQuery::make(
            $this->applySort($exportQuery, $request, 'created_at', 'desc')
        )
            ->sortable(self::SORTABLE)
            ->defaultSort('created_at', 'desc')
            ->maxRows(ExportQuery::resolveMaxRows(config('exports.max_rows', ExportQuery::DEFAULT_MAX_ROWS)))
            ->fromRequest($request);

        $columns = [
            'code' => 'Kode',
            'name' => 'Nama',
            'asset_type' => 'Tipe',
            'serial_number' => 'Nomor seri',
            'status' => 'Status',
            'ownership' => 'Kepemilikan',
        ];

        $map = static fn (NetworkAsset $asset): array => [
            'code' => $asset->code,
            'name' => $asset->name,
            'asset_type' => $asset->asset_type,
            'serial_number' => $asset->serial_number ?? '',
            'status' => $asset->status,
            'ownership' => $asset->getAttribute('ownership'),
        ];

        return $format === 'pdf'
            ? $export->streamPdf('Aset Jaringan', $columns, $map, "network-assets-export-{$stamp}.pdf")
            : $export->streamCsv($columns, $map, "network-assets-export-{$stamp}.csv");
    }

    /**
     * @return Builder<NetworkAsset>
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = NetworkAsset::query()
            ->with(['location', 'customer', 'subscription'])
            ->when($request->input('asset_type'), fn (Builder $q, string $v) => $q->where('asset_type', $v))
            ->when($request->input('status'), fn (Builder $q, string $v) => $q->where('status', $v))
            ->when($request->input('location_id'), fn (Builder $q, string $v) => $q->where('location_id', PublicId::resolveOne(Location::class, $v, fn (Builder $location) => $location->withTrashed()) ?? 0))
            ->when($request->input('search'), function (Builder $q, string $v): void {
                $term = trim($v);

                if ($term === '') {
                    return;
                }

                $like = '%'.$term.'%';
                $q->where(function (Builder $sq) use ($like): void {
                    $sq->where('serial_number', 'like', $like)
                        ->orWhere('mac_address', 'like', $like)
                        ->orWhere('ip_address', 'like', $like)
                        ->orWhere('code', 'like', $like)
                        ->orWhere('name', 'like', $like);
                });
            });

        return $query;
    }

    public function create(Request $request): InertiaResponse
    {
        Gate::authorize('create', NetworkAsset::class);

        return Inertia::render('Admin/NetworkAssets/Create', [
            'productOptions' => $this->productOptions($request),
            'locationOptions' => $this->locationOptions($request),
        ]);
    }

    public function store(StoreNetworkAssetRequest $request): RedirectResponse
    {
        Gate::authorize('store', NetworkAsset::class);

        $data = $request->validated();
        $data['code'] = NetworkAssetService::generateCode();
        $data['status'] = $data['status'] ?? 'available';

        // shortcut: ip_address and management_ip stay a display copy of the IPAM rows (Q6a); drop the columns
        // and read asset IPs from IPAM once a second consumer needs them.
        DB::transaction(fn () => IpAssignmentService::syncAssetAddresses(NetworkAsset::create($data)));

        return redirect()->route('admin.network-assets.index')->with('success', 'Aset jaringan dibuat.');
    }

    public function show(Request $request, NetworkAsset $asset): InertiaResponse
    {
        Gate::authorize('view', $asset);
        $asset->load(['product', 'location', 'customer', 'subscription', 'installations.location']);

        return Inertia::render('Admin/NetworkAssets/Show', [
            'asset' => new NetworkAssetResource($asset),
            'locationOptions' => $this->locationOptions($request),
        ]);
    }

    public function edit(Request $request, NetworkAsset $asset): InertiaResponse
    {
        Gate::authorize('edit', $asset);
        $asset->load(['product', 'location', 'customer', 'subscription']);

        return Inertia::render('Admin/NetworkAssets/Edit', [
            'asset' => new NetworkAssetResource($asset),
            'productOptions' => $this->productOptions($request, $asset->product?->public_id),
            'locationOptions' => $this->locationOptions($request, $asset->location?->public_id),
        ]);
    }

    public function update(UpdateNetworkAssetRequest $request, NetworkAsset $asset): RedirectResponse
    {
        Gate::authorize('update', $asset);

        $data = $request->validated();
        unset($data['status'], $data['code']);

        DB::transaction(function () use ($asset, $data): void {
            $asset->update($data);
            IpAssignmentService::syncAssetAddresses($asset);
        });

        return redirect()->route('admin.network-assets.index')->with('success', 'Aset jaringan diperbarui.');
    }

    public function destroy(NetworkAsset $asset): RedirectResponse
    {
        Gate::authorize('delete', $asset);

        abort_if(in_array($asset->status, ['installed', 'maintenance']), 422, 'Aset yang terpasang atau dalam perawatan tidak dapat dihapus. Lepas dulu.');

        DB::transaction(function () use ($asset): void {
            IpAssignmentService::releaseForAsset($asset);
            $asset->delete();
        });

        return back()->with('success', 'Aset jaringan dihapus.');
    }

    public function install(InstallNetworkAssetRequest $request, NetworkAsset $asset): RedirectResponse
    {
        Gate::authorize('network_asset.install');

        $validated = $request->validated();

        NetworkAssetService::install(
            $asset,
            (int) $validated['location_id'],
            $validated['customer_id'] ?? null,
            $validated['subscription_id'] ?? null,
            $validated['spk_id'] ?? null,
        );

        return back()->with('success', 'Aset dipasang.');
    }

    public function remove(Request $request, NetworkAsset $asset): RedirectResponse
    {
        Gate::authorize('network_asset.remove');

        $request->validate(['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'alasan']);

        NetworkAssetService::remove($asset, $request->input('reason'));

        return back()->with('success', 'Aset dilepas.');
    }

    public function maintenance(Request $request, NetworkAsset $asset): RedirectResponse
    {
        Gate::authorize('network_asset.maintenance');

        $request->validate(['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'alasan']);

        NetworkAssetService::setMaintenance($asset, $request->input('reason'));

        return back()->with('success', 'Aset masuk perawatan.');
    }

    public function resume(Request $request, NetworkAsset $asset): RedirectResponse
    {
        Gate::authorize('network_asset.maintenance');

        NetworkAssetService::resume($asset);

        return back()->with('success', 'Aset kembali terpasang.');
    }

    public function damage(Request $request, NetworkAsset $asset): RedirectResponse
    {
        Gate::authorize('network_asset.repair');

        $request->validate(['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'alasan']);

        NetworkAssetService::setDamaged($asset, $request->input('reason'));

        return back()->with('success', 'Aset ditandai rusak.');
    }

    public function repair(Request $request, NetworkAsset $asset): RedirectResponse
    {
        Gate::authorize('network_asset.repair');

        NetworkAssetService::repair($asset);

        return back()->with('success', 'Aset diperbaiki.');
    }

    public function retire(Request $request, NetworkAsset $asset): RedirectResponse
    {
        Gate::authorize('network_asset.retire');

        $request->validate(['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'alasan']);

        NetworkAssetService::retire($asset, $request->input('reason'));

        return back()->with('success', 'Aset dipensiunkan.');
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
                ->where('customer_id', PublicId::resolveOne(Customer::class, $request->input('customer_id')) ?? 0)
                ->get();
        }

        return Inertia::render('Admin/NetworkAssets/Trace', [
            'results' => NetworkAssetResource::collection($results)->resolve(),
            'search' => $request->input('search', ''),
            'customer_id' => $request->input('customer_id', ''),
        ]);
    }

    /** Active products, found by SKU or name; the current one stays in the list on an edit form. */
    private function productOptions(Request $request, ?string $selected = null): OptionalProp
    {
        return SearchOptions::for(
            $request,
            'product',
            Product::query()->where('is_active', true)->with('category:id,name'),
            ['name', 'sku'],
            fn (Product $product): array => [
                'value' => $product->public_id,
                'label' => "{$product->sku} - {$product->name}",
                'description' => $product->category?->name,
            ],
            $selected,
        );
    }

    /** Active locations, found by code, name or path; the current one stays in the list on an edit form. */
    private function locationOptions(Request $request, ?string $selected = null): OptionalProp
    {
        return SearchOptions::for(
            $request,
            'location',
            Location::query()->where('is_active', true),
            ['code', 'name', 'path'],
            fn (Location $location): array => [
                'value' => $location->public_id,
                'label' => "{$location->code} - {$location->name}",
                'description' => $location->path,
            ],
            $selected,
        );
    }
}
