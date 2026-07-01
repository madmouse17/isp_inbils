<?php

namespace Modules\SPK\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\SPK\Http\Requests\StoreWorkOrderRequest;
use Modules\SPK\Http\Requests\UpdateWorkOrderRequest;
use Modules\SPK\Http\Resources\WorkOrderResource;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderEvidence;
use Modules\SPK\Models\WorkOrderItem;
use Modules\SPK\Services\SpkService;

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

        $workOrders = $query->latest()->paginate(15)->withQueryString();

        return Inertia::render('Admin/SPK/Index', [
            'workOrders' => WorkOrderResource::collection($workOrders),
            'technicians' => \App\Http\Resources\UserResource::collection(
                User::query()->whereHas('roles', fn ($q) => $q->where('name', 'technician'))
                    ->where('is_active', true)->orderBy('name')->get()
            ),
            'filters' => $request->only(['type', 'status', 'assigned_to', 'search']),
            'can' => ['create' => $request->user()?->can('spk.create') ?? false],
        ]);
    }

    public function create(Request $request): InertiaResponse
    {
        Gate::authorize('create', WorkOrder::class);

        return Inertia::render('Admin/SPK/Create', [
            'customers' => \App\Http\Resources\CustomerResource::collection(Customer::query()->where('is_active', true)->orderBy('name')->get()),
            'subscriptions' => \App\Http\Resources\SubscriptionResource::collection(ServiceSubscription::query()->whereIn('status', ['pending', 'active'])->orderBy('code')->get()),
            'locations' => \App\Http\Resources\LocationResource::collection(Location::query()->where('is_active', true)->orderBy('code')->get()),
            'technicians' => \App\Http\Resources\UserResource::collection(
                User::query()->whereHas('roles', fn ($q) => $q->where('name', 'technician'))
                    ->where('is_active', true)->orderBy('name')->get()
            ),
        ]);
    }

    public function store(StoreWorkOrderRequest $request): RedirectResponse
    {
        Gate::authorize('store', WorkOrder::class);

        $data = $request->validated();
        $data['code'] = SpkService::generateCode();
        $data['status'] = 'draft';
        $data['created_by'] = $request->user()->id;

        $wo = WorkOrder::create($data);

        return redirect()->route('admin.spk.show', $wo)
            ->with('success', 'SPK created.');
    }

    public function show(WorkOrder $wo): InertiaResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('view', $wo);

        $wo->load(['customer', 'subscription', 'location', 'assignee', 'items.product', 'assignments.technician', 'evidence']);

        return Inertia::render('Admin/SPK/Show', [
            'workOrder' => new WorkOrderResource($wo),
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
            'customers' => \App\Http\Resources\CustomerResource::collection(Customer::query()->where('is_active', true)->orderBy('name')->get()),
            'subscriptions' => \App\Http\Resources\SubscriptionResource::collection(ServiceSubscription::query()->whereIn('status', ['pending', 'active'])->orderBy('code')->get()),
            'locations' => \App\Http\Resources\LocationResource::collection(Location::query()->where('is_active', true)->orderBy('code')->get()),
        ]);
    }

    public function update(UpdateWorkOrderRequest $request, WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('update', $wo);
        abort_if(! in_array($wo->status, ['draft', 'generated']), 422, 'Can only edit draft or generated SPK.');

        $wo->update($request->validated());

        return back()->with('success', 'SPK updated.');
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

        $request->validate(['technician_id' => ['required', 'exists:users,id']]);
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

        $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'quantity_reserved' => ['nullable', 'numeric', 'min:0'],
            'quantity_used' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        WorkOrderItem::updateOrCreate(
            ['work_order_id' => $wo->id, 'product_id' => $request->integer('product_id')],
            $request->only(['quantity_reserved', 'quantity_used', 'note'])
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
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf,doc,docx'],
            'caption' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');
        $path = $file->store("evidence/{$wo->id}", 'public');

        WorkOrderEvidence::create([
            'work_order_id' => $wo->id,
            'type' => $file->getClientOriginalExtension() === 'pdf' ? 'document' : 'photo',
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'caption' => $request->input('caption'),
            'uploaded_by' => $request->user()->id,
            'uploaded_at' => now(),
        ]);

        return back()->with('success', 'Evidence uploaded.');
    }

    public function removeEvidence(WorkOrder $wo, WorkOrderEvidence $evidence): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        abort_unless($evidence->work_order_id === $wo->id, 404);
        Gate::authorize('spk.evidence.upload');

        Storage::disk('public')->delete($evidence->file_path);
        $evidence->delete();

        return back()->with('success', 'Evidence removed.');
    }

    private function ensureSameCompany(WorkOrder $wo): void
    {
        abort_unless($wo->company_id === \App\Services\Core\CompanyService::currentId(), 404);
    }
}
