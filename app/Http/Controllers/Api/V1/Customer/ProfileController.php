<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCustomerAccess;
use App\Http\Resources\Api\V1\CustomerProfileResource;
use App\Models\Core\Customer;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __invoke(Request $request): CustomerProfileResource
    {
        $customer = $request->attributes->get(EnsureCustomerAccess::CUSTOMER_ATTRIBUTE);

        abort_unless($customer instanceof Customer, 403);

        $customer->load(['addresses' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('id')]);

        return new CustomerProfileResource($customer);
    }
}
