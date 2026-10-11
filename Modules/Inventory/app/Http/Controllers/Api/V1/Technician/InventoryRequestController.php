<?php

namespace Modules\Inventory\Http\Controllers\Api\V1\Technician;

use App\Http\Controllers\Controller;
use App\Services\Core\CompanyService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Inventory\Http\Resources\Api\V1\TechnicianInventoryRequestResource;
use Modules\Inventory\Models\InventoryRequest;
use Modules\SPK\Models\WorkOrder;

class InventoryRequestController extends Controller
{
    private const STATUSES = ['draft', 'submitted', 'approved_reserved', 'preparing', 'ready_for_pickup', 'issued', 'rejected', 'cancelled'];

    public function index(Request $request)
    {
        Gate::authorize('viewAny', InventoryRequest::class);
        abort_unless($request->user()->can('inventory.request.view.own'), 403);

        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $paginator = InventoryRequest::withoutCompany()
            ->where('company_id', CompanyService::idFor($request->user()))
            ->where('requested_by', $request->user()->id)
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->with(['items.product.unit'])
            ->latest('id')
            ->paginate($validated['per_page'] ?? 20);

        $this->loadWorkOrderSources($paginator->getCollection(), CompanyService::idFor($request->user()));

        return TechnicianInventoryRequestResource::collection($paginator);
    }

    /** @param Collection<int, InventoryRequest> $requests */
    private function loadWorkOrderSources(Collection $requests, ?int $companyId): void
    {
        $ids = $requests
            ->filter(fn (InventoryRequest $request): bool => $request->source_type === 'work_order' && $request->source_id !== null)
            ->pluck('source_id')
            ->unique()
            ->values();

        if ($ids->isEmpty() || $companyId === null) {
            return;
        }

        $workOrders = WorkOrder::withoutCompany()
            ->where('company_id', $companyId)
            ->whereIn('id', $ids)
            ->get(['id', 'code'])
            ->keyBy('id');

        $requests->each(function (InventoryRequest $request) use ($workOrders): void {
            if ($request->source_type === 'work_order') {
                $request->setRelation('source', $workOrders->get($request->source_id));
            }
        });
    }
}
