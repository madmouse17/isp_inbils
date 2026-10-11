<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Inventory\Http\Requests\DeclareMaterialReturnRequest;
use Modules\Inventory\Http\Requests\ReceiveAcceptMaterialReturnRequest;
use Modules\Inventory\Http\Requests\ReceiveRejectMaterialReturnRequest;
use Modules\Inventory\Http\Requests\ResolveMaterialReturnExceptionRequest;
use Modules\Inventory\Http\Resources\MaterialReturnCaseResource;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\Inventory\Services\MaterialReturnService;

class MaterialReturnController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', MaterialReturnCase::class);

        $filters = $request->only([
            'status', 'technician_id', 'inventory_request_id', 'work_order_id',
            'search', 'sort', 'direction', 'per_page',
        ]);

        $paginator = MaterialReturnService::paginate($request->user(), $filters);

        return response()->json([
            'data' => MaterialReturnCaseResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'badges' => MaterialReturnService::getBadgeCounts($request->user()),
        ]);
    }

    public function show(MaterialReturnCase $material_return): JsonResponse
    {
        Gate::authorize('view', $material_return);

        $material_return->loadMissing(['lines.product', 'events.actor', 'technician', 'warehouseLocation', 'receiver', 'reconciler']);

        return response()->json([
            'data' => new MaterialReturnCaseResource($material_return),
        ]);
    }

    /**
     * Technician declares used vs returnable material.
     */
    public function declareUsage(DeclareMaterialReturnRequest $request, MaterialReturnCase $material_return): JsonResponse
    {
        Gate::authorize('declare', $material_return);

        $updated = MaterialReturnService::declareUsage($material_return, $request->user(), $request->validated());

        return response()->json([
            'message' => 'Deklarasi material return berhasil disimpan.',
            'data' => new MaterialReturnCaseResource($updated),
        ]);
    }

    /**
     * Warehouse physically accepts the returned items.
     */
    public function receiveAccept(ReceiveAcceptMaterialReturnRequest $request, MaterialReturnCase $material_return): JsonResponse
    {
        Gate::authorize('receive', $material_return);

        $updated = MaterialReturnService::receiveAccept($material_return, $request->user(), $request->validated());

        return response()->json([
            'message' => 'Penerimaan fisik material return berhasil diproses.',
            'data' => new MaterialReturnCaseResource($updated),
        ]);
    }

    /**
     * Warehouse physically rejects the returned items handover.
     */
    public function receiveReject(ReceiveRejectMaterialReturnRequest $request, MaterialReturnCase $material_return): JsonResponse
    {
        Gate::authorize('receive', $material_return);

        $updated = MaterialReturnService::receiveReject($material_return, $request->user(), $request->validated('rejection_reason'));

        return response()->json([
            'message' => 'Penyerahan fisik material return ditolak.',
            'data' => new MaterialReturnCaseResource($updated),
        ]);
    }

    /**
     * Warehouse Manager/Admin resolves discrepancy exception.
     */
    public function resolveException(ResolveMaterialReturnExceptionRequest $request, MaterialReturnCase $material_return): JsonResponse
    {
        Gate::authorize('resolve', $material_return);

        $case = MaterialReturnService::resolveException($material_return, $request->user(), $request->validated()['resolution_note']);

        return response()->json([
            'message' => 'Pengecualian diskrepansi material berhasil diselesaikan.',
            'data' => new MaterialReturnCaseResource($case),
        ]);
    }
}
