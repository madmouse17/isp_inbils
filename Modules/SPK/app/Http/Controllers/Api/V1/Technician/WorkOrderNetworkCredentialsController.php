<?php

namespace Modules\SPK\Http\Controllers\Api\V1\Technician;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Services\SpkService;

class WorkOrderNetworkCredentialsController extends Controller
{
    /**
     * Show the PPPoE login of an installation SPK
     *
     * Only for the technician the SPK is assigned to now, and only while it is assigned or in progress; a reassignment ends the access. Every call is audited and counts against 5 a minute. The answer is never cached or kept for an `Idempotency-Key` replay: ask again to see it again. 409 `work_order_closed` when the SPK is no longer active, 422 `credentials_not_ready` when the account is not made yet.
     *
     * @response array{data: array{username: string, password: string}}
     */
    public function __invoke(Request $request, WorkOrder $wo): JsonResponse
    {
        return response()
            ->json(['data' => SpkService::revealNetworkCredentials($wo, $request->user())])
            ->header('Cache-Control', 'no-store, private');
    }
}
