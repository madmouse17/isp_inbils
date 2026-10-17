<?php

namespace Modules\SPK\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCustomerAccess;
use App\Http\Requests\Api\V1\PageRequest;
use App\Models\Core\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\SPK\Http\Resources\Api\V1\CustomerWorkOrderResource;
use Modules\SPK\Models\WorkOrder;

class WorkOrderController extends Controller
{
    /** The customer hears of a visit from the assignment on: a draft or a waiting SPK is the ISP's own business. */
    private const VISIBLE = ['assigned', 'in_progress', 'waiting_review', 'rejected', 'completed', 'cancelled'];

    private const CLOSED = ['completed', 'cancelled'];

    /**
     * The technician visits of the customer's subscriptions
     *
     * The visits still under way and the closed ones of the last 30 days, newest first. Only SPKs of the customer's own subscriptions.
     */
    public function index(PageRequest $request): AnonymousResourceCollection
    {
        $visits = $this->visitsOf($request)
            ->where(fn (Builder $query) => $query->whereNotIn('status', self::CLOSED)->orWhere('updated_at', '>=', now()->subDays(30)))
            ->latest('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return CustomerWorkOrderResource::collection($visits);
    }

    /**
     * One technician visit
     *
     * A closed visit opens whatever its age, so an old notification still lands on it. Another customer's visit is 404.
     */
    public function show(Request $request, string $wo): CustomerWorkOrderResource
    {
        return new CustomerWorkOrderResource($this->visitsOf($request)->wherePublicId($wo)->firstOrFail());
    }

    /** @return Builder<WorkOrder> */
    private function visitsOf(Request $request): Builder
    {
        $customer = $request->attributes->get(EnsureCustomerAccess::CUSTOMER_ATTRIBUTE);
        abort_unless($customer instanceof Customer, 403);

        return WorkOrder::query()
            ->whereIn('subscription_id', $customer->subscriptions()->select('id'))
            ->whereIn('status', self::VISIBLE)
            ->with([
                'assignee:id,name',
                'assignments' => fn ($query) => $query->whereNull('unassigned_at'),
                'subscription:id,installation_address_id',
                'subscription.installationAddress:id,label,address,city',
            ]);
    }
}
