<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Core\Location;
use App\Queries\Admin\ApprovalInboxQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Inventory\Http\Requests\ApproveInventoryRequestRequest;
use Modules\Inventory\Http\Requests\CancelInventoryRequestRequest;
use Modules\Inventory\Http\Requests\RejectInventoryRequestRequest;
use Modules\Inventory\Http\Requests\StoreInventoryRequestRequest;
use Modules\Inventory\Http\Resources\InventoryRequestResource;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Services\InventoryRequestService;
use Modules\Inventory\Support\InventoryOptions;
use Modules\Inventory\Support\InventoryRequestSources;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InventoryRequestController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        Gate::authorize('viewAny', InventoryRequest::class);

        $user = $request->user();
        $filters = $request->only([
            'tab',
            'status',
            'search',
            'requester_id',
            'warehouse_location_id',
            'needed_from',
            'needed_to',
            'sort',
            'direction',
            'per_page',
            'page',
            'return_search',
            'return_status',
            'return_sort',
            'return_direction',
            'return_per_page',
            'return_page',
        ]);
        $filters['per_page'] ??= 10;

        $paginator = InventoryRequestService::paginate($user, $filters);
        $badgeCounts = InventoryRequestService::getBadgeCounts($user);

        $data = [
            'requests' => InventoryRequestResource::collection($paginator),
            'filters' => $filters,
            'badgeCounts' => $badgeCounts,
            'can' => [
                'create' => $user->can('create', InventoryRequest::class),
                'viewAll' => $user->hasPermissionTo('inventory.request.view.all'),
                'approve' => $user->hasPermissionTo('inventory.request.approve'),
                'prepare' => $user->hasPermissionTo('inventory.request.prepare'),
                'issue' => $user->hasPermissionTo('inventory.request.issue'),
                'audit' => $user->hasPermissionTo('inventory.request.audit'),
            ],
        ];

        if ($request->wantsJson()) {
            return response()->json($data);
        }

        return Inertia::render('Admin/Inventory/Requests/Index', $data);
    }

    public function show(Request $request, InventoryRequest $inventoryRequest): Response|JsonResponse
    {
        Gate::authorize('view', $inventoryRequest);

        $inventoryRequest->load([
            'items.product.unit',
            'items.sourceLocation',
            'requester',
            'approver',
            'preparer',
            'readyUser',
            'issuer',
            'rejecter',
            'canceller',
            'warehouseLocation',
        ]);

        $data = [
            'request' => new InventoryRequestResource($inventoryRequest),
            // What each line can still reserve, only while the request waits for an approver who may approve it.
            'stockAvailable' => $inventoryRequest->status === 'submitted' && $request->user()->can('approve', $inventoryRequest)
                ? ApprovalInboxQuery::inventoryStockAvailable($inventoryRequest)
                : null,
        ];

        if ($request->wantsJson()) {
            return response()->json($data);
        }

        return Inertia::render('Admin/Inventory/Requests/Show', $data);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', InventoryRequest::class);

        return Inertia::render('Admin/Inventory/Requests/Create', [
            'warehouses' => Location::query()->where('is_active', true)->orderBy('name')->get(['public_id', 'name', 'code', 'type'])
                ->map(fn (Location $location): array => ['id' => $location->public_id, 'name' => $location->name, 'code' => $location->code, 'type' => $location->type])
                ->all(),
            'productOptions' => InventoryOptions::products($request, Product::query()->where('is_active', true)->where('type', '!=', 'asset')),
            'workOrderOptions' => InventoryRequestSources::workOrders($request),
            'ticketOptions' => InventoryRequestSources::tickets($request),
        ]);
    }

    public function store(StoreInventoryRequestRequest $request): RedirectResponse|JsonResponse
    {
        $created = InventoryRequestService::create($request->user(), $request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Permintaan barang berhasil dibuat.',
                'data' => new InventoryRequestResource($created),
            ], 201);
        }

        return redirect()->route('admin.inventory.requests.show', $created)
            ->with('success', 'Permintaan barang berhasil dibuat.');
    }

    public function submit(Request $request, InventoryRequest $inventoryRequest): RedirectResponse|JsonResponse
    {
        Gate::authorize('submit', $inventoryRequest);

        $submitted = InventoryRequestService::submit($inventoryRequest, $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Permintaan barang berhasil diajukan ke antrean gudang.',
                'data' => new InventoryRequestResource($submitted),
            ]);
        }

        return back()->with('success', 'Permintaan barang berhasil diajukan ke antrean gudang.');
    }

    public function approve(ApproveInventoryRequestRequest $request, InventoryRequest $inventoryRequest): RedirectResponse|JsonResponse
    {
        try {
            $approved = InventoryRequestService::approve($inventoryRequest, $request->user(), $request->validated());
        } catch (HttpException $e) {
            if ($request->header('X-Inertia') && in_array($e->getStatusCode(), [403, 422], true)) {
                return back()->withErrors(['error' => $e->getMessage()]);
            }
            throw $e;
        }

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Permintaan barang disetujui dan stok berhasil direservasi.',
                'data' => new InventoryRequestResource($approved),
            ]);
        }

        return back()->with('success', 'Permintaan barang disetujui dan stok berhasil direservasi.');
    }

    public function prepare(Request $request, InventoryRequest $inventoryRequest): RedirectResponse|JsonResponse
    {
        Gate::authorize('prepare', $inventoryRequest);

        $prepared = InventoryRequestService::prepare($inventoryRequest, $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Status permintaan diubah menjadi sedang disiapkan gudang.',
                'data' => new InventoryRequestResource($prepared),
            ]);
        }

        return back()->with('success', 'Status permintaan diubah menjadi sedang disiapkan gudang.');
    }

    public function markReady(Request $request, InventoryRequest $inventoryRequest): RedirectResponse|JsonResponse
    {
        Gate::authorize('ready', $inventoryRequest);

        $ready = InventoryRequestService::markReady($inventoryRequest, $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Status permintaan diubah menjadi siap diambil.',
                'data' => new InventoryRequestResource($ready),
            ]);
        }

        return back()->with('success', 'Status permintaan diubah menjadi siap diambil.');
    }

    public function issue(Request $request, InventoryRequest $inventoryRequest): RedirectResponse|JsonResponse
    {
        Gate::authorize('issue', $inventoryRequest);

        $issued = InventoryRequestService::issue($inventoryRequest, $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Barang berhasil diserahterimakan dan stok berkurang.',
                'data' => new InventoryRequestResource($issued),
            ]);
        }

        return back()->with('success', 'Barang berhasil diserahterimakan dan stok berkurang.');
    }

    public function reject(RejectInventoryRequestRequest $request, InventoryRequest $inventoryRequest): RedirectResponse|JsonResponse
    {
        try {
            $rejected = InventoryRequestService::reject($inventoryRequest, $request->user(), $request->validated()['rejection_reason']);
        } catch (HttpException $e) {
            if ($request->header('X-Inertia') && in_array($e->getStatusCode(), [403, 422], true)) {
                return back()->withErrors(['error' => $e->getMessage()]);
            }
            throw $e;
        }

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Permintaan barang telah ditolak.',
                'data' => new InventoryRequestResource($rejected),
            ]);
        }

        return back()->with('success', 'Permintaan barang telah ditolak.');
    }

    public function cancel(CancelInventoryRequestRequest $request, InventoryRequest $inventoryRequest): RedirectResponse|JsonResponse
    {
        $cancelled = InventoryRequestService::cancel($inventoryRequest, $request->user(), $request->validated()['cancellation_reason'] ?? null);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Permintaan barang berhasil dibatalkan.',
                'data' => new InventoryRequestResource($cancelled),
            ]);
        }

        return back()->with('success', 'Permintaan barang berhasil dibatalkan.');
    }
}
