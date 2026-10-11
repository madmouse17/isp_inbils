<?php

namespace Modules\NetworkAsset\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Services\OdnService;

class OdnPortController extends Controller
{
    public function destroy(OdnPort $port): RedirectResponse
    {
        Gate::authorize('delete', $port);

        OdnService::deletePort($port);

        return back()->with('success', 'PON port deleted.');
    }
}
