<?php

namespace Modules\SPK\Http\Controllers;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\LocationResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Core\Customer;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use App\Services\Core\NumberSequenceService;
use App\Support\ExportQuery;
use App\Support\FieldCheckIn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Inventory\Http\Resources\ProductResource;
use Modules\Inventory\Models\Product;
use Modules\NetworkAsset\Http\Resources\NetworkAssetResource;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Services\OdnService;
use Modules\SPK\Http\Requests\AssignWorkOrderRequest;
use Modules\SPK\Http\Requests\StoreWorkOrderEvidenceRequest;
use Modules\SPK\Http\Requests\StoreWorkOrderItemRequest;
use Modules\SPK\Http\Requests\StoreWorkOrderRequest;
use Modules\SPK\Http\Requests\UpdateWorkOrderRequest;
use Modules\SPK\Http\Resources\WorkOrderResource;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderItem;
use Modules\SPK\Services\SpkService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WorkOrderController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['code', 'title', 'type', 'status', 'priority', 'scheduled_date', 'created_at'];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', WorkOrder::class);

        $workOrders = $this->applySort($this->filteredQuery($request), $request, 'created_at', 'desc')
            ->paginate($this->perPage($request))->withQueryString();

        return Inertia::render('Admin/SPK/Index', [
            'workOrders' => WorkOrderResource::collection($workOrders),
            'technicians' => EmployeeResource::collection($this->technicians()),
            'typeOptions' => ['installation', 'maintenance', 'upgrade_service', 'relocation'],
            'statusOptions' => ['draft', 'generated', 'assigned', 'in_progress', 'waiting_review', 'completed', 'rejected', 'cancelled'],
            'filters' => $request->only(['type', 'status', 'assigned_to', 'search', 'sort_by', 'sort_dir', 'per_page']),
            'can' => ['create' => $request->user()?->can('spk.create') ?? false, 'export' => $request->user()?->can('spk.export') ?? false],
        ]);
    }

    public function export(Request $request): Response|StreamedResponse
    {
        Gate::authorize('viewAny', WorkOrder::class);
        Gate::authorize('spk.export');

        $format = strtolower((string) $request->input('format', 'csv'));
        $stamp = now()->format('Ymd-His');
        $export = ExportQuery::make($this->applySort($this->filteredQuery($request), $request, 'created_at', 'desc'))
            ->defaultSort('created_at', 'desc')
            ->maxRows((int) config('exports.max_rows', 5000));

        $columns = [
            'code' => 'Code',
            'title' => 'Title',
            'type' => 'Type',
            'status' => 'Status',
            'priority' => 'Priority',
            'customer' => 'Customer',
            'assignee' => 'Technician',
        ];

        $map = static fn (WorkOrder $wo): array => [
            'code' => $wo->code,
            'title' => $wo->title,
            'type' => $wo->type,
            'status' => $wo->status,
            'priority' => $wo->priority,
            'customer' => data_get($wo, 'customer.name', ''),
            'assignee' => data_get($wo, 'assignee.name', ''),
        ];

        if ($format === 'pdf') {
            return $export->streamPdf('SPK', $columns, $map, "spk-export-{$stamp}.pdf");
        }

        return $export->streamCsv($columns, $map, "spk-export-{$stamp}.csv");
    }

    /** @return Builder<WorkOrder> */
    private function filteredQuery(Request $request): Builder
    {
        $query = WorkOrder::query()
            ->with(['customer', 'subscription', 'location', 'assignee'])
            ->when($request->input('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('assigned_to'), fn ($q, $v) => $q->where('assigned_to', $v))
            ->when($request->input('search'), fn ($q, $v) => $q->where(fn ($sq) => $sq
                ->where('code', 'like', "%{$v}%")
                ->orWhere('title', 'like', "%{$v}%")));

        if (($user = request()->user()) !== null
            && ($user->can('spk.start') || $user->can('spk.submit'))
            && ! $user->can('spk.assign')) {
            $query->where('assigned_to', request()->user()->id);
        }

        return $query;
    }

    public function create(Request $request): InertiaResponse
    {
        Gate::authorize('create', WorkOrder::class);

        return Inertia::render('Admin/SPK/Create', [
            'customers' => CustomerResource::collection(Customer::query()->where('is_active', true)->orderBy('name')->get()),
            'subscriptions' => SubscriptionResource::collection(ServiceSubscription::query()->whereIn('status', ['pending', 'active'])->orderBy('code')->get()),
            'locations' => LocationResource::collection(Location::query()->where('is_active', true)->orderBy('code')->get()),
            'technicians' => EmployeeResource::collection($this->technicians()),
        ]);
    }

    public function store(StoreWorkOrderRequest $request): RedirectResponse
    {
        Gate::authorize('store', WorkOrder::class);

        DB::transaction(function () use ($request) {
            $data = $request->validated();
            $data['code'] = NumberSequenceService::generate('spk', 'SPK', CompanyService::currentId());
            $data['status'] = 'draft';
            $data['created_by'] = $request->user()->id;

            WorkOrder::create($data);
        });

        return redirect()->route('admin.spk.index')
            ->with('success', 'SPK created.');
    }

    public function show(Request $request, WorkOrder $wo): InertiaResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('view', $wo);

        $wo->load(['customer', 'subscription', 'location', 'assignee', 'items.product.unit', 'items.networkAsset.product.unit', 'assignments.technician', 'media']);
        $checkIn = FieldCheckIn::recordFor($wo, 'work_order');

        return Inertia::render('Admin/SPK/Show', [
            'workOrder' => new WorkOrderResource($wo),
            'technicians' => EmployeeResource::collection($this->technicians()),
            'products' => ProductResource::collection($this->products()),
            'networkAssets' => NetworkAssetResource::collection($this->networkAssets()),
            'can' => [
                'uploadEvidence' => $request->user()?->can('uploadEvidence', $wo) ?? false,
            ],
            'checkInSummary' => $checkIn === null ? null : FieldCheckIn::summary($checkIn),
            // The drop reserved for the customer (read-only), so the technician connects the right ODP port.
            'dropPort' => $wo->type === 'installation' && $wo->subscription !== null && Gate::allows('odn.view')
                ? OdnService::dropPortFor($wo->subscription)
                : null,
        ]);
    }

    public function edit(WorkOrder $wo): InertiaResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('edit', $wo);
        abort_if(! in_array($wo->status, ['draft', 'generated']), 422, 'Can only edit draft or generated SPK.');

        $wo->load(['customer', 'subscription', 'location', 'assignee']);

        return Inertia::render('Admin/SPK/Edit', [
            'workOrder' => new WorkOrderResource($wo),
            'customers' => CustomerResource::collection(Customer::query()->where('is_active', true)->orderBy('name')->get()),
            'subscriptions' => SubscriptionResource::collection(ServiceSubscription::query()->whereIn('status', ['pending', 'active'])->orderBy('code')->get()),
            'locations' => LocationResource::collection(Location::query()->where('is_active', true)->orderBy('code')->get()),
            'technicians' => EmployeeResource::collection($this->technicians()),
        ]);
    }

    public function update(UpdateWorkOrderRequest $request, WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('update', $wo);
        abort_if(! in_array($wo->status, ['draft', 'generated']), 422, 'Can only edit draft or generated SPK.');

        $wo->update($request->validated());

        return redirect()->route('admin.spk.index')->with('success', 'SPK updated.');
    }

    public function destroy(WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('delete', $wo);
        abort_if(! in_array($wo->status, ['draft', 'cancelled']), 422, 'Can only delete draft or cancelled SPK.');

        $wo->delete();

        return back()->with('success', 'SPK deleted.');
    }

    public function generate(WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('spk.update');
        SpkService::generate($wo);

        return back()->with('success', 'SPK generated.');
    }

    public function assign(AssignWorkOrderRequest $request, WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('assign', $wo);

        try {
            SpkService::assign(
                $wo,
                $request->integer('technician_id'),
                $request->user()->id,
                $request->input('expected_assigned_to') !== null ? $request->integer('expected_assigned_to') : null,
                $request->input('expected_updated_at')
            );
        } catch (HttpException $exception) {
            if ($request->header('X-Inertia') && $exception->getStatusCode() === 409) {
                return back()->withErrors(['conflict' => $exception->getMessage()]);
            }

            throw $exception;
        }

        return back()->with('success', 'SPK assigned.');
    }

    public function start(WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('start', $wo);

        try {
            SpkService::start($wo);
        } catch (HttpException $exception) {
            if (request()->header('X-Inertia') && $exception->getStatusCode() === 409) {
                return back()->withErrors(['conflict' => $exception->getMessage()]);
            }

            throw $exception;
        }

        return back()->with('success', 'SPK started.');
    }

    public function submit(WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('submit', $wo);
        try {
            SpkService::submitForReview($wo);
        } catch (HttpException $exception) {
            if (request()->header('X-Inertia') && $exception->getStatusCode() === 409) {
                return back()->withErrors(['conflict' => $exception->getMessage()]);
            }

            throw $exception;
        }

        return back()->with('success', 'SPK submitted for review.');
    }

    public function approve(Request $request, WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('approve', $wo);

        try {
            SpkService::approve($wo);
        } catch (HttpException $e) {
            if ($request->header('X-Inertia') && $e->getStatusCode() === 422) {
                return back()->withErrors(['error' => $e->getMessage()]);
            }
            throw $e;
        }

        return back()->with('success', 'SPK approved.');
    }

    public function reject(Request $request, WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('reject', $wo);

        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            SpkService::reject($wo, $request->input('reason'));
        } catch (HttpException $e) {
            if ($request->header('X-Inertia') && $e->getStatusCode() === 422) {
                return back()->withErrors(['error' => $e->getMessage()]);
            }
            throw $e;
        }

        return back()->with('success', 'SPK rejected.');
    }

    public function cancel(Request $request, WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('cancel', $wo);

        $request->validate(['reason' => ['required', 'string', 'max:500']]);
        SpkService::cancel($wo, $request->input('reason'));

        return back()->with('success', 'SPK cancelled.');
    }

    public function addItem(StoreWorkOrderItemRequest $request, WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);

        SpkService::addItem($wo, $request->validated());

        return back()->with('success', 'Item added.');
    }

    public function removeItem(WorkOrder $wo, WorkOrderItem $item): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        abort_unless($item->work_order_id === $wo->id, 404);
        Gate::authorize('spk.update');

        $item->delete();

        return back()->with('success', 'Item removed.');
    }

    public function uploadEvidence(StoreWorkOrderEvidenceRequest $request, WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        SpkService::addEvidence($wo, $request->user(), $request->evidenceAttributes());

        return back()->with('success', 'Evidence uploaded.');
    }

    public function removeEvidence(Request $request, WorkOrder $wo, Media $evidence): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        SpkService::removeEvidence($wo, $evidence->id, $request->user());

        return back()->with('success', 'Evidence removed.');
    }

    private function ensureSameCompany(WorkOrder $wo): void
    {
        abort_unless($wo->company_id === CompanyService::currentId(), 404);
    }

    /** @return Collection<int, EmployeeProfile> */
    private function technicians(): Collection
    {
        return EmployeeProfile::query()
            ->with(['user', 'organization'])
            ->where('company_id', CompanyService::currentId())
            ->where('status', 'active')
            ->whereHas('user', function ($query) {
                $query->where('company_id', CompanyService::currentId())
                    ->where('is_active', true)
                    ->where(function ($permissionQuery) {
                        $permissionQuery->whereHas('permissions', fn ($query) => $query->whereIn('name', ['spk.start', 'spk.submit']))
                            ->orWhereHas('roles.permissions', fn ($query) => $query->whereIn('name', ['spk.start', 'spk.submit']));
                    });
            })
            ->orderBy('employee_number')
            ->get();
    }

    /** @return Collection<int, Product> */
    private function products(): Collection
    {
        return Product::query()
            ->with(['category', 'unit'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, NetworkAsset> */
    private function networkAssets(): Collection
    {
        return NetworkAsset::query()
            ->with(['product.unit'])
            ->where('status', 'available')
            ->orderBy('code')
            ->get();
    }
}
