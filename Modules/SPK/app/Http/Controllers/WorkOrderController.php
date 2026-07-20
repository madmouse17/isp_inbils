<?php

namespace Modules\SPK\Http\Controllers;

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
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Inventory\Http\Resources\ProductResource;
use Modules\Inventory\Models\Product;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\SPK\Http\Requests\StoreWorkOrderRequest;
use Modules\SPK\Http\Requests\UpdateWorkOrderRequest;
use Modules\SPK\Http\Resources\WorkOrderResource;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderItem;
use Modules\SPK\Services\SpkService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class WorkOrderController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', WorkOrder::class);

        $query = WorkOrder::query()
            ->with(['customer', 'subscription', 'location', 'assignee'])
            ->when($request->input('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('assigned_to'), fn ($q, $v) => $q->where('assigned_to', $v))
            ->when($request->input('search'), fn ($q, $v) => $q->where(fn ($sq) => $sq
                ->where('code', 'like', "%{$v}%")
                ->orWhere('title', 'like', "%{$v}%")));

        // Technician sees only own SPK
        if ($request->user()?->hasRole('technician')) {
            $query->where('assigned_to', $request->user()->id);
        }

        $workOrders = $query->latest()->paginate(10)->withQueryString();

        return Inertia::render('Admin/SPK/Index', [
            'workOrders' => WorkOrderResource::collection($workOrders),
            'technicians' => EmployeeResource::collection($this->technicians()),
            'filters' => $request->only(['type', 'status', 'assigned_to', 'search']),
            'can' => ['create' => $request->user()?->can('spk.create') ?? false],
        ]);
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

        $data = $request->validated();
        $data['code'] = SpkService::generateCode();
        $data['status'] = 'draft';
        $data['created_by'] = $request->user()->id;

        WorkOrder::create($data);

        return redirect()->route('admin.spk.index')
            ->with('success', 'SPK created.');
    }

    public function show(WorkOrder $wo): InertiaResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('view', $wo);

        $wo->load(['customer', 'subscription', 'location', 'assignee', 'items.product.unit', 'assignments.technician', 'media']);

        return Inertia::render('Admin/SPK/Show', [
            'workOrder' => new WorkOrderResource($wo),
            'technicians' => EmployeeResource::collection($this->technicians()),
            'products' => ProductResource::collection($this->products()),
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

    public function generate(Request $request, WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('spk.update');
        SpkService::generate($wo);

        return back()->with('success', 'SPK generated.');
    }

    public function assign(Request $request, WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('spk.assign');

        $request->validate([
            'technician_id' => [
                'required',
                Rule::exists('users', 'id')->where('company_id', CompanyService::currentId()),
                function (string $attribute, mixed $value, Closure $fail): void {
                    $exists = EmployeeProfile::query()
                        ->where('user_id', $value)
                        ->where('status', 'active')
                        ->whereHas('user.roles', fn ($query) => $query->where('name', 'technician'))
                        ->exists();

                    if (! $exists) {
                        $fail('Selected technician must be an employee with technician role.');
                    }
                },
            ],
        ]);
        SpkService::assign($wo, $request->integer('technician_id'), $request->user()->id);

        return back()->with('success', 'SPK assigned.');
    }

    public function start(Request $request, WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('spk.start');
        SpkService::start($wo);

        return back()->with('success', 'SPK started.');
    }

    public function submit(Request $request, WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('spk.submit');
        SpkService::submitForReview($wo);

        return back()->with('success', 'SPK submitted for review.');
    }

    public function approve(Request $request, WorkOrder $wo): RedirectResponse
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

    public function addItem(Request $request, WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('spk.update');

        $data = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('company_id', CompanyService::currentId())->where('is_active', true)],
            'network_asset_id' => ['nullable', Rule::exists('network_assets', 'id')->where('company_id', CompanyService::currentId())->where('status', 'available')],
            'quantity_reserved' => ['nullable', 'numeric', 'min:0'],
            'quantity_used' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if ($request->filled('network_asset_id')) {
            $asset = NetworkAsset::query()->findOrFail($request->integer('network_asset_id'));
            if ($asset->product_id !== (int) $data['product_id']) {
                throw ValidationException::withMessages([
                    'network_asset_id' => 'Selected network asset must match selected product.',
                ]);
            }
        }

        $data['quantity_reserved'] ??= 0;
        $data['quantity_used'] ??= 0;

        WorkOrderItem::updateOrCreate(
            ['work_order_id' => $wo->id, 'product_id' => $request->integer('product_id')],
            $data
        );

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
        $wo->addMedia($file)
            ->withCustomProperties([
                'company_id' => $wo->company_id,
                'type' => str($file->getMimeType())->startsWith('image/') ? 'photo' : 'document',
                'caption' => $request->input('caption'),
                'uploaded_by' => $request->user()->id,
            ])
            ->toMediaCollection('evidence', 'public');

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
}
