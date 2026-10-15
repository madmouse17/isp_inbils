<?php

namespace Modules\Ipam\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Ipam\Models\IpAddress;
use Modules\Ipam\Services\IpAssignmentService;

class IpAddressController extends Controller
{
    public function destroy(IpAddress $address): RedirectResponse
    {
        Gate::authorize('delete', $address);

        IpAssignmentService::release($address);

        return back()->with('success', "Alamat {$address->address} dilepas.");
    }
}
