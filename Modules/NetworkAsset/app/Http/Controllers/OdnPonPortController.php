<?php

namespace Modules\NetworkAsset\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\NetworkAsset\Http\Requests\StorePonPortsRequest;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Services\OdnService;

class OdnPonPortController extends Controller
{
    public function store(StorePonPortsRequest $request, NetworkAsset $asset): RedirectResponse
    {
        $ports = OdnService::createPonPorts($asset, (int) $request->validated('count'));

        return back()->with('success', "{$ports->count()} PON port(s) created on {$asset->code}.");
    }
}
