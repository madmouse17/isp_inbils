<?php

namespace Modules\SPK\Http\Controllers\Api\V1\Technician;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Api\EnforceIdempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SPK\Http\Requests\StoreWorkOrderEvidenceRequest;
use Modules\SPK\Http\Resources\Api\V1\EvidenceResource;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Services\SpkService;

class WorkOrderEvidenceController extends Controller
{
    public function store(StoreWorkOrderEvidenceRequest $request, WorkOrder $wo): JsonResponse
    {
        $media = SpkService::addEvidence(
            $wo,
            $request->user(),
            $request->evidenceAttributes(),
            $request->attributes->get(EnforceIdempotency::KEY_ATTRIBUTE),
        );

        return (new EvidenceResource($media, $request->user()))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, WorkOrder $wo, int $mediaId): JsonResponse
    {
        SpkService::removeEvidence($wo, $mediaId, $request->user());

        return response()->json(null, 204);
    }
}
