<?php

namespace Modules\Inventory\Http\Controllers\Api\V1\Technician;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Api\EnforceIdempotency;
use App\Services\Core\CompanyService;
use App\Support\PublicId;
use App\Support\RecordNotFound;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Inventory\Http\Requests\DeclareMaterialReturnRequest;
use Modules\Inventory\Http\Resources\Api\V1\TechnicianMaterialReturnResource;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\Inventory\Services\MaterialReturnService;
use Modules\SPK\Models\WorkOrder;

class MaterialReturnController extends Controller
{
    private const STATUSES = ['awaiting_declaration', 'awaiting_receipt', 'correction_required', 'reconciled', 'exception_open', 'resolved'];

    public function index(Request $request)
    {
        Gate::authorize('viewAny', MaterialReturnCase::class);
        $validated = $request->validate([
            'work_order_id' => ['nullable', 'ulid'],
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $this->baseQuery($request)
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($validated['work_order_id'] ?? null, function ($query, string $workOrderId): void {
                $query->whereHas('inventoryRequest', fn ($request) => $request
                    ->where('source_type', 'work_order')
                    ->where('source_id', PublicId::resolveOne(WorkOrder::class, $workOrderId) ?? 0));
            })
            ->latest('id');

        return TechnicianMaterialReturnResource::collection($query->paginate($validated['per_page'] ?? 20));
    }

    public function show(Request $request, MaterialReturnCase $materialReturn): TechnicianMaterialReturnResource
    {
        $materialReturn = $this->ownCase($request, $materialReturn);
        Gate::authorize('view', $materialReturn);

        return new TechnicianMaterialReturnResource($this->loadDetail($materialReturn));
    }

    public function declare(DeclareMaterialReturnRequest $request, MaterialReturnCase $materialReturn): TechnicianMaterialReturnResource
    {
        $materialReturn = $this->ownCase($request, $materialReturn);
        $data = $request->validated();
        $data['idempotency_key'] = $request->attributes->get(EnforceIdempotency::KEY_ATTRIBUTE);

        if ($materialReturn->idempotency_key !== ($data['idempotency_key'] ?? null)) {
            Gate::authorize('declare', $materialReturn);
        }

        $updated = MaterialReturnService::declareUsage($materialReturn, $request->user(), $data);

        return new TechnicianMaterialReturnResource($this->loadDetail($updated));
    }

    private function baseQuery(Request $request)
    {
        return MaterialReturnCase::withoutCompany()
            ->where('company_id', CompanyService::idFor($request->user()))
            ->where('technician_id', $request->user()->id)
            ->with(['inventoryRequest', 'workOrder', 'warehouseLocation', 'lines.product.unit']);
    }

    private function ownCase(Request $request, MaterialReturnCase $case): MaterialReturnCase
    {
        RecordNotFound::abortUnless((int) $case->company_id === CompanyService::idFor($request->user()));
        RecordNotFound::abortUnless((int) $case->technician_id === (int) $request->user()->id);

        return $case;
    }

    private function loadDetail(MaterialReturnCase $case): MaterialReturnCase
    {
        return $case->load(['inventoryRequest', 'workOrder', 'warehouseLocation', 'lines.product.unit']);
    }
}
