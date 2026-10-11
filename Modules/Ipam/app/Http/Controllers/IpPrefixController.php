<?php

namespace Modules\Ipam\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Core\Location;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Ipam\Http\Requests\StoreIpPrefixRequest;
use Modules\Ipam\Http\Requests\UpdateIpPrefixRequest;
use Modules\Ipam\Http\Resources\IpAddressResource;
use Modules\Ipam\Models\IpAddress;
use Modules\Ipam\Models\IpPrefix;
use Modules\Ipam\Models\Vlan;
use Modules\Ipam\Queries\IpPrefixUtilisationQuery;
use Modules\Ipam\Services\IpPrefixService;

class IpPrefixController extends Controller
{
    private const FILTERS = ['family', 'usage', 'location_id', 'vlan_id', 'search'];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', IpPrefix::class);

        return Inertia::render('Admin/Ipam/Prefixes/Index', [
            'prefixes' => IpPrefixUtilisationQuery::rows($request->only(self::FILTERS)),
            'filters' => array_replace(array_fill_keys(self::FILTERS, null), $request->only(self::FILTERS)),
            ...$this->pickLists(),
            'can' => ['manage' => $request->user()?->can('ipam.manage') ?? false],
        ]);
    }

    public function store(StoreIpPrefixRequest $request): RedirectResponse
    {
        $prefix = IpPrefixService::create($request->validated());

        return redirect()->route('admin.ipam.prefixes.index')->with('success', "Prefix {$prefix->cidr} registered.");
    }

    public function show(Request $request, IpPrefix $prefix): InertiaResponse
    {
        Gate::authorize('view', $prefix);

        $addresses = IpAddress::query()
            ->where('family', $prefix->family)
            ->whereBetween('address_cmp', [$prefix->range_from, $prefix->range_to])
            ->with(['subscription:id,code,customer_id', 'subscription.customer:id,name', 'networkAsset:id,code,name'])
            ->orderBy('address_cmp')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Ipam/Prefixes/Show', [
            'prefix' => [
                ...IpPrefixUtilisationQuery::row($prefix),
                'parent' => $prefix->parent === null ? null : ['id' => $prefix->parent->id, 'cidr' => $prefix->parent->cidr],
            ],
            'children' => IpPrefixUtilisationQuery::children($prefix),
            'addresses' => IpAddressResource::collection($addresses),
            ...$this->pickLists(),
            'can' => [
                'manage' => $request->user()?->can('ipam.manage') ?? false,
                'assign' => $request->user()?->can('ipam.assign') ?? false,
            ],
        ]);
    }

    /**
     * The POP and VLAN choices of the prefix forms (create on the index, edit on the show page).
     *
     * @return array{locations: Collection<int, Location>, vlans: list<array{id: int, vid: int, name: string, location_code: string|null}>}
     */
    private function pickLists(): array
    {
        return [
            'locations' => Location::query()->where('type', 'pop')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'vlans' => Vlan::query()->with('location:id,code')->orderBy('vid')->get()
                ->map(fn (Vlan $vlan): array => ['id' => $vlan->id, 'vid' => $vlan->vid, 'name' => $vlan->name, 'location_code' => $vlan->location?->code])
                ->all(),
        ];
    }

    public function update(UpdateIpPrefixRequest $request, IpPrefix $prefix): RedirectResponse
    {
        IpPrefixService::update($prefix, $request->validated());

        return back()->with('success', 'Prefix updated.');
    }

    public function destroy(IpPrefix $prefix): RedirectResponse
    {
        Gate::authorize('delete', $prefix);

        IpPrefixService::delete($prefix);

        return redirect()->route('admin.ipam.prefixes.index')->with('success', "Prefix {$prefix->cidr} deleted.");
    }
}
