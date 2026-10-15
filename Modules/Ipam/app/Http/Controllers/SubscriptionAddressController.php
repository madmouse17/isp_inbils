<?php

namespace Modules\Ipam\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Core\ServiceSubscription;
use Illuminate\Http\RedirectResponse;
use Modules\Ipam\Http\Requests\StoreSubscriptionAddressRequest;
use Modules\Ipam\Models\IpPrefix;
use Modules\Ipam\Services\IpAssignmentService;

class SubscriptionAddressController extends Controller
{
    public function store(StoreSubscriptionAddressRequest $request, ServiceSubscription $subscription): RedirectResponse
    {
        $pool = IpPrefix::query()->findOrFail($request->validated('ip_prefix_id'));

        $row = IpAssignmentService::assignToSubscription($subscription, $pool, $request->validated('address') ?: null);

        return back()->with('success', "Alamat {$row->address} diberikan ke {$subscription->code}.");
    }
}
