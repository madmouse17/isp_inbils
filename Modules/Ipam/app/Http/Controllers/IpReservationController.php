<?php

namespace Modules\Ipam\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Ipam\Http\Requests\StoreReservationRequest;
use Modules\Ipam\Models\IpPrefix;
use Modules\Ipam\Services\IpAssignmentService;

class IpReservationController extends Controller
{
    public function store(StoreReservationRequest $request, IpPrefix $prefix): RedirectResponse
    {
        $reservation = IpAssignmentService::reserve($prefix, $request->validated('address'), $request->validated('note'));

        return back()->with('success', "Address {$reservation->address} reserved.");
    }
}
