<?php

namespace Modules\NetworkAsset\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Modules\NetworkAsset\Http\Requests\AssignDropRequest;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Services\OdnService;

class OdnDropController extends Controller
{
    public function store(AssignDropRequest $request, OdnPort $port): RedirectResponse
    {
        OdnService::assignDrop($port, (int) $request->validated('subscription_id'));

        return back()->with('success', 'Drop recorded.');
    }

    public function destroy(OdnPort $port): RedirectResponse
    {
        Gate::authorize('assignDrop', $port);

        OdnService::releaseDrop($port);

        return back()->with('success', 'Drop released.');
    }
}
