<?php

namespace Modules\NetworkAsset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\RecordNotFound;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Queries\OdnOccupancyQuery;

class OdnController extends Controller
{
    private const FILTERS = ['pop_id', 'min_percent', 'search'];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', OdnPort::class);

        $filters = $request->only(self::FILTERS);

        return Inertia::render('Admin/Odn/Index', [
            'odps' => JsonResource::collection(OdnOccupancyQuery::odps($filters)),
            'ponPorts' => JsonResource::collection(OdnOccupancyQuery::ponPorts($filters)),
            'unmapped' => JsonResource::collection(OdnOccupancyQuery::unmapped($filters)),
            'filters' => array_replace(array_fill_keys(self::FILTERS, null), $filters),
            'pops' => OdnOccupancyQuery::pops(),
        ]);
    }

    public function show(Request $request, NetworkAsset $asset): InertiaResponse
    {
        Gate::authorize('viewAny', OdnPort::class);
        RecordNotFound::abortUnless(in_array($asset->asset_type, ['olt', 'odc', 'odp'], true));

        $isOlt = $asset->asset_type === 'olt';

        return Inertia::render('Admin/Odn/Asset', [
            'asset' => [
                'id' => $asset->public_id,
                'code' => $asset->code,
                'name' => $asset->name,
                'asset_type' => $asset->asset_type,
                'location_path' => $asset->location?->getAttribute('path'),
            ],
            'ponPorts' => $isOlt ? OdnOccupancyQuery::ponPortRows($asset) : [],
            'splitters' => $isOlt ? [] : OdnOccupancyQuery::splitterRows($asset),
            ...($isOlt ? [] : ['inputPortOptions' => OdnOccupancyQuery::inputPortOptions($request)]),
            'can' => [
                'manage' => $request->user()?->can('odn.manage') ?? false,
                'assign' => $request->user()?->can('odn.assign') ?? false,
            ],
        ]);
    }
}
