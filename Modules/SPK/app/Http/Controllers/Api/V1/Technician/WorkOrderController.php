<?php

namespace Modules\SPK\Http\Controllers\Api\V1\Technician;

use App\Http\Controllers\Controller;
use App\Support\FieldCheckIn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\SPK\Http\Requests\Api\V1\EnRouteWorkOrderRequest;
use Modules\SPK\Http\Resources\Api\V1\TechnicianWorkOrderResource;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Services\SpkService;

class WorkOrderController extends Controller
{
    public function show(Request $request, WorkOrder $wo): TechnicianWorkOrderResource
    {
        // Same rule as My work: spk.view alone must not expose other technicians' SPKs and customer contacts.
        abort_unless((int) $wo->assigned_to === (int) $request->user()->id, 404);
        Gate::authorize('view', $wo);

        return new TechnicianWorkOrderResource($this->detail($wo));
    }

    /**
     * Tell the customer the technician has left for the visit
     *
     * Only while the SPK is assigned, and only once: asking again changes nothing and returns the SPK as it is. The status stays `assigned`; the SPK moves to the `en_route` step and the customer's app is told. The location fix is optional and kept apart from the check-in of the start.
     */
    public function enRoute(EnRouteWorkOrderRequest $request, WorkOrder $wo): TechnicianWorkOrderResource
    {
        $wo = SpkService::markEnRoute($wo, $request->validated('check_in'));

        return new TechnicianWorkOrderResource($this->detail($wo));
    }

    public function start(Request $request, WorkOrder $wo): TechnicianWorkOrderResource
    {
        Gate::authorize('start', $wo);
        $validated = $request->validate(FieldCheckIn::rules());

        $wo = SpkService::start($wo, $validated['check_in'] ?? null);

        return new TechnicianWorkOrderResource($this->detail($wo));
    }

    public function submit(WorkOrder $wo): TechnicianWorkOrderResource
    {
        Gate::authorize('submit', $wo);

        $wo = SpkService::submitForReview($wo);

        return new TechnicianWorkOrderResource($this->detail($wo));
    }

    private function detail(WorkOrder $wo): WorkOrder
    {
        return $wo->load([
            'customer',
            'subscription.servicePackage',
            'subscription.installationAddress',
            'location',
            'assignments',
            'items.product.unit',
            'media',
        ]);
    }
}
