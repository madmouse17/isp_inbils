<?php

namespace Modules\SPK\Http\Controllers\Api\V1\Technician;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\SPK\Http\Requests\Api\V1\AddWorkOrderItemRequest;
use Modules\SPK\Http\Resources\Api\V1\TechnicianWorkOrderItemResource;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Services\SpkService;

class WorkOrderItemController extends Controller
{
    public function store(AddWorkOrderItemRequest $request, WorkOrder $wo): JsonResponse
    {
        $item = SpkService::addMaterial($wo, $request->user(), $request->validated())->load('product.unit');

        return (new TechnicianWorkOrderItemResource($item))->response()->setStatusCode(201);
    }

    /**
     * Withdraw the technician's use of a product
     *
     * A line the manager planned (a reservation or a note) stays with `quantity_used` 0; a line that held nothing but this use is deleted. The answer is 204 either way, so read the SPK again to see which. A serialized asset line is refused with 422.
     */
    public function destroy(Request $request, WorkOrder $wo, string $item): Response
    {
        SpkService::removeMaterial($wo, $item, $request->user());

        return response()->noContent();
    }
}
