<?php

namespace Modules\NetworkAsset\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\NetworkAsset\Http\Requests\UpdateAssetPortRequest;
use Modules\NetworkAsset\Models\AssetPort;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Services\AssetPanelService;

class AssetPortController extends Controller
{
    /** The port is resolved through the asset (scoped binding), so a port of another asset is a 404. */
    public function update(UpdateAssetPortRequest $request, NetworkAsset $asset, AssetPort $port): RedirectResponse
    {
        AssetPanelService::updatePort($port, $request->validated());

        return back()->with('success', 'Port diperbarui.');
    }
}
