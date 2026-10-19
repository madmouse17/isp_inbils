<?php

namespace Modules\NetworkAsset\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\NetworkAsset\Http\Requests\UpdateRackPlacementRequest;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Services\AssetPanelService;

class RackPlacementController extends Controller
{
    public function update(UpdateRackPlacementRequest $request, NetworkAsset $asset): RedirectResponse
    {
        $data = $request->validated();
        $rackId = isset($data['rack_asset_id']) ? (int) $data['rack_asset_id'] : null;

        AssetPanelService::place($asset, $rackId, isset($data['rack_unit']) ? (int) $data['rack_unit'] : null, isset($data['height_u']) ? (int) $data['height_u'] : null);

        return back()->with('success', $rackId === null ? 'Perangkat dilepas dari rak.' : 'Posisi rak disimpan.');
    }
}
