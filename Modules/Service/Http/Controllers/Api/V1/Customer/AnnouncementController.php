<?php

namespace Modules\Service\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCustomerAccess;
use App\Models\Core\Customer;
use Illuminate\Http\Request;
use Modules\Service\Queries\CustomerAudienceQuery;
use Modules\Service\Resources\Api\V1\CustomerAnnouncementResource;

class AnnouncementController extends Controller
{
    public function show(Request $request, int $announcement, CustomerAudienceQuery $audience): CustomerAnnouncementResource
    {
        $customer = $request->attributes->get(EnsureCustomerAccess::CUSTOMER_ATTRIBUTE);
        abort_unless($customer instanceof Customer, 403);

        $item = $audience->visibleAnnouncements($customer)->findOrFail($announcement);

        return new CustomerAnnouncementResource($item);
    }
}
