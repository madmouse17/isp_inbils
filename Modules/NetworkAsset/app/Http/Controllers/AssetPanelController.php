<?php

namespace Modules\NetworkAsset\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\NetworkAsset\Http\Requests\ApplyPortTemplateRequest;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Services\AssetPanelService;

class AssetPanelController extends Controller
{
    public function update(ApplyPortTemplateRequest $request, NetworkAsset $asset): RedirectResponse
    {
        $data = $request->validated();

        AssetPanelService::apply($asset, $data['template_key'] ?? null, $data['layout'] ?? null, isset($data['height_u']) ? (int) $data['height_u'] : null);

        return back()->with('success', $asset->asset_type === 'rack' ? 'Ukuran rak disimpan.' : 'Model perangkat diterapkan.');
    }
}
