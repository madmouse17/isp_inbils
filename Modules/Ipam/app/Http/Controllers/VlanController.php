<?php

namespace Modules\Ipam\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Core\Location;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Ipam\Http\Requests\StoreVlanRequest;
use Modules\Ipam\Http\Requests\UpdateVlanRequest;
use Modules\Ipam\Http\Resources\VlanResource;
use Modules\Ipam\Models\Vlan;
use Modules\Ipam\Services\VlanService;

class VlanController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', Vlan::class);

        $vlans = Vlan::query()
            ->with('location:id,public_id,code')
            ->withCount('prefixes')
            ->orderBy('vid')
            ->orderBy('location_id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Ipam/Vlans/Index', [
            'vlans' => VlanResource::collection($vlans),
            'locations' => Location::query()->where('type', 'pop')->where('is_active', true)->orderBy('code')->get(['public_id', 'code', 'name'])
                ->map(fn (Location $location): array => ['id' => $location->public_id, 'code' => $location->code, 'name' => $location->name])
                ->all(),
            'can' => ['manage' => $request->user()?->can('ipam.manage') ?? false],
        ]);
    }

    public function store(StoreVlanRequest $request): RedirectResponse
    {
        $vlan = VlanService::create($request->validated());

        return redirect()->route('admin.ipam.vlans.index')->with('success', "VLAN {$vlan->vid} didaftarkan.");
    }

    public function update(UpdateVlanRequest $request, Vlan $vlan): RedirectResponse
    {
        VlanService::update($vlan, $request->validated());

        return redirect()->route('admin.ipam.vlans.index')->with('success', 'VLAN diperbarui.');
    }

    public function destroy(Vlan $vlan): RedirectResponse
    {
        Gate::authorize('delete', $vlan);

        VlanService::delete($vlan);

        return redirect()->route('admin.ipam.vlans.index')->with('success', "VLAN {$vlan->vid} dihapus.");
    }
}
