<?php

namespace Modules\NetworkAsset\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Modules\NetworkAsset\Http\Requests\LinkAssetPortRequest;
use Modules\NetworkAsset\Models\AssetPort;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Services\AssetPanelService;

class AssetPortLinkController extends Controller
{
    public function store(LinkAssetPortRequest $request, NetworkAsset $asset, AssetPort $port): RedirectResponse
    {
        AssetPanelService::link($port, (int) $request->validated('target_port_id'));

        return back()->with('success', 'Port dihubungkan.');
    }

    public function destroy(NetworkAsset $asset, AssetPort $port): RedirectResponse
    {
        Gate::authorize('updatePanel', $asset);

        AssetPanelService::unlink($port);

        return back()->with('success', 'Sambungan port dilepas.');
    }
}
