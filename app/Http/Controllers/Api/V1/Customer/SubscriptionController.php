<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCustomerAccess;
use App\Http\Requests\Api\V1\PageRequest;
use App\Http\Resources\Api\V1\CustomerSubscriptionResource;
use App\Models\Core\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubscriptionController extends Controller
{
    public function index(PageRequest $request): AnonymousResourceCollection
    {
        $customer = $request->attributes->get(EnsureCustomerAccess::CUSTOMER_ATTRIBUTE);

        abort_unless($customer instanceof Customer, 403);

        $subscriptions = $customer->subscriptions()
            ->with([
                'servicePackage:id,public_id,name,service_package_category_id,bandwidth_profile_id,speed_profile_id,sla_tier_id',
                'servicePackage.category:id,public_id,name',
                'servicePackage.bandwidthProfile:id,download_mbps,upload_mbps',
                'servicePackage.speedProfile:id,download_max_mbps',
                'servicePackage.slaTier:id,name,response_time_hours,resolution_time_hours',
                'pppoeAccount:id,subscription_id,service_type,username',
                'installationAddress:id,address,city,label,lat,lng',
            ])
            ->latest('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return CustomerSubscriptionResource::collection($subscriptions);
    }

    public function show(Request $request, string $id): CustomerSubscriptionResource
    {
        $customer = $request->attributes->get(EnsureCustomerAccess::CUSTOMER_ATTRIBUTE);

        abort_unless($customer instanceof Customer, 403);

        $subscription = $customer->subscriptions()
            ->with([
                'servicePackage:id,public_id,name,service_package_category_id,bandwidth_profile_id,speed_profile_id,sla_tier_id',
                'servicePackage.category:id,public_id,name',
                'servicePackage.bandwidthProfile:id,download_mbps,upload_mbps',
                'servicePackage.speedProfile:id,download_max_mbps',
                'servicePackage.slaTier:id,name,response_time_hours,resolution_time_hours',
                'pppoeAccount:id,subscription_id,service_type,username',
                'installationAddress:id,address,city,label,lat,lng',
            ])
            ->wherePublicId($id)
            ->firstOrFail();

        return new CustomerSubscriptionResource($subscription);
    }
}
