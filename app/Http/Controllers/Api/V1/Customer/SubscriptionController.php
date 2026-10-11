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
                'servicePackage:id,name',
                'installationAddress:id,address,city',
            ])
            ->latest('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return CustomerSubscriptionResource::collection($subscriptions);
    }

    public function show(Request $request, int $id): CustomerSubscriptionResource
    {
        $customer = $request->attributes->get(EnsureCustomerAccess::CUSTOMER_ATTRIBUTE);

        abort_unless($customer instanceof Customer, 403);

        $subscription = $customer->subscriptions()
            ->with([
                'servicePackage:id,name',
                'installationAddress:id,address,city',
            ])
            ->findOrFail($id);

        return new CustomerSubscriptionResource($subscription);
    }
}
