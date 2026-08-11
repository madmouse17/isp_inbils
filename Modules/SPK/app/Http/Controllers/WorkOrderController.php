<?php

namespace Modules\SPK\Http\Controllers;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Concerns\UploadsMedia;
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
use Modules\SPK\Http\Requests\AssignWorkOrderRequest;
use Modules\SPK\Http\Requests\StoreWorkOrderItemRequest;
use Modules\SPK\Http\Requests\StoreWorkOrderRequest;
use Modules\SPK\Http\Requests\UpdateWorkOrderRequest;
use Modules\SPK\Http\Resources\WorkOrderResource;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderItem;
use Modules\SPK\Services\SpkService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkOrderController extends Controller
{
    use HasIndexQuery;
    use UploadsMedia;

    private const SORTABLE = ['code', 'title', 'type', 'status', 'priority', 'created_at'];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', WorkOrder::class);

        $workOrders = $this->filteredQuery($request)->paginate(10)->withQueryString();

        return Inertia::render('Admin/SPK/Index', [
            'workOrders' => WorkOrderResource::collection($workOrders),
            'technicians' => EmployeeResource::collection($this->technicians()),
            'filters' => $request->only(['type', 'status', 'assigned_to', 'search', 'sort', 'direction']),
            'can' => ['create' => $request->user()?->can('spk.create') ?? false, 'export' => $request->user()?->can('spk.export') ?? false],
        ]);
    }

    public function export(Request $request): Response|StreamedResponse
    {
        Gate::authorize('spk.export');

        $format = strtolower((string) $request->input('format', 'csv'));
        $stamp = now()->format('Ymd-His');
        $export = ExportQuery::make($this->filteredQuery($request))
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
            'customer' => $wo->customer?->name ?? '',
            'assignee' => $wo->assignee?->name ?? '',
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

        if (request()->user()?->hasRole('technician')) {
            $query->where('assigned_to', request()->user()->id);
        }

        return $query->tap(fn (Builder $query) => $this->applySort($query, $request, 'created_at', 'desc'));
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

    public function show(WorkOrder $wo): InertiaResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('view', $wo);

        $wo->load(['customer', 'subscription', 'location', 'assignee', 'items.product.unit', 'items.networkAsset.product.unit', 'assignments.technician', 'media']);

        return Inertia::render('Admin/SPK/Show', [
            'workOrder' => new WorkOrderResource($wo),
            'technicians' => EmployeeResource::collection($this->technicians()),
            'products' => ProductResource::collection($this->products()),
            'networkAssets' => NetworkAssetResource::collection($this->networkAssets()),
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

        SpkService::assign($wo, $request->integer('technician_id'), $request->user()->id);

        return back()->with('success', 'SPK assigned.');
    }

    public function start(WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('spk.start');
        SpkService::start($wo);

        return back()->with('success', 'SPK started.');
    }

    public function submit(WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('spk.submit');
        SpkService::submitForReview($wo);

        return back()->with('success', 'SPK submitted for review.');
    }

    public function approve(WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('spk.approve');
        SpkService::approve($wo);

        return back()->with('success', 'SPK approved.');
    }

    public function reject(Request $request, WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('spk.reject');

        $request->validate(['reason' => ['required', 'string', 'max:500']]);
        SpkService::reject($wo, $request->input('reason'));

        return back()->with('success', 'SPK rejected.');
    }

    public function cancel(Request $request, WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('spk.cancel');

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

    public function uploadEvidence(Request $request, WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('spk.evidence.upload');

        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf,doc,docx', 'mimetypes:image/jpeg,image/png,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'caption' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');
        $this->storeMedia($wo, $file, 'evidence', [
            'company_id' => $wo->company_id,
            'type' => str($file->getMimeType())->startsWith('image/') ? 'photo' : 'document',
            'caption' => $request->input('caption'),
            'uploaded_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Evidence uploaded.');
    }

    public function removeEvidence(WorkOrder $wo, Media $evidence): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        abort_unless($evidence->model_type === $wo::class && (int) $evidence->model_id === $wo->id, 404);
        abort_unless($evidence->collection_name === 'evidence', 404);
        Gate::authorize('spk.evidence.upload');

        $evidence->delete();

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
            ->where('status', 'active')
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->whereHas('user.roles', fn ($query) => $query->where('name', 'technician'))
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
