<?php

namespace Modules\NetworkAsset\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Modules\NetworkAsset\Http\Requests\StoreSplitterRequest;
use Modules\NetworkAsset\Http\Requests\UpdateSplitterRequest;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\OdnSplitter;
use Modules\NetworkAsset\Services\OdnService;

class OdnSplitterController extends Controller
{
    public function store(StoreSplitterRequest $request, NetworkAsset $asset): RedirectResponse
    {
        $data = $request->validated();

        $splitter = OdnService::createSplitter($asset, (int) $data['outputs'], $data['input_port_id'] ?? null, $data['label'] ?? null);

        return back()->with('success', "Splitter 1:{$splitter->outputs} ditambahkan ke {$asset->code}.");
    }

    public function update(UpdateSplitterRequest $request, OdnSplitter $splitter): RedirectResponse
    {
        OdnService::updateSplitter($splitter, $request->validated());

        return back()->with('success', 'Splitter diperbarui.');
    }

    public function destroy(OdnSplitter $splitter): RedirectResponse
    {
        Gate::authorize('delete', $splitter);

        OdnService::deleteSplitter($splitter);

        return back()->with('success', 'Splitter dihapus.');
    }
}
