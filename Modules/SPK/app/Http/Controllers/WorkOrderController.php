<?php

namespace Modules\SPK\Http\Controllers;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\LocationResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Core\Customer;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\Core\NumberSequenceService;
use App\Support\ExportQuery;
use App\Support\FieldCheckIn;
use App\Support\PublicId;
use App\Support\RecordNotFound;
use App\Support\SearchOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Inventory\Models\Product;
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
use Modules\SPK\Support\TripSteps;
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
            'code' => 'Kode',
            'title' => 'Judul',
            'type' => 'Tipe',
            'status' => 'Status',
            'priority' => 'Prioritas',
            'customer' => 'Pelanggan',
            'assignee' => 'Teknisi',
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
            ->when($request->input('assigned_to'), fn ($q, $v) => $q->where('assigned_to', PublicId::resolveOne(User::class, $v) ?? 0))
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

        return Inertia::render('Admin/SPK/Create', $this->createOptions($request));
    }

    public function store(StoreWorkOrderRequest $request): RedirectResponse
    {
        Gate::authorize('store', WorkOrder::class);

        DB::transaction(function () use ($request) {
            $data = $request->validated();
            $data['code'] = NumberSequenceService::generate('spk', CompanyService::currentId());
            $data['status'] = 'draft';
            $data['created_by'] = $request->user()->id;

            WorkOrder::create($data);
        });

        return redirect()->route('admin.spk.index')
            ->with('success', 'SPK berhasil dibuat.');
    }

    public function show(Request $request, WorkOrder $wo): InertiaResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('view', $wo);

        $wo->load(['customer', 'subscription', 'location', 'assignee', 'items.product.unit', 'items.networkAsset.product.unit', 'assignments.technician', 'media']);
        $checkIn = FieldCheckIn::recordFor($wo, 'work_order');
        $productId = PublicId::resolveOne(Product::class, $request->query('product_id')) ?? 0;

        return Inertia::render('Admin/SPK/Show', [
            'workOrder' => new WorkOrderResource($wo),
            'technicians' => $this->assignableTechnicians($wo),
            'productOptions' => SearchOptions::for(
                $request,
                'product',
                Product::query()->with('category:id,name')->where('is_active', true),
                ['name', 'sku'],
                fn (Product $product): array => ['value' => $product->public_id, 'label' => $product->sku.' - '.$product->name, 'description' => $product->category?->name],
            ),
            // The product the page just picked: its kind decides whether an asset is asked for, its unit labels the quantities.
            'selectedProduct' => Inertia::optional(function () use ($productId): ?array {
                $product = Product::query()->with('unit:id,name,symbol')->where('is_active', true)->find($productId);

                return $product === null ? null : [
                    'id' => $product->public_id,
                    'type' => $product->type,
                    'unit' => $product->unit?->only(['name', 'symbol']),
                ];
            }),
            // Serialized assets of the picked product that are still free to install.
            'networkAssetOptions' => SearchOptions::for(
                $request,
                'networkAsset',
                NetworkAsset::query()->where('status', 'available')->where('product_id', $productId),
                ['code', 'name'],
                fn (NetworkAsset $asset): array => ['value' => $asset->public_id, 'label' => $asset->code.' - '.$asset->name],
            ),
            'can' => [
                'uploadEvidence' => $request->user()?->can('uploadEvidence', $wo) ?? false,
            ],
            // Only what the page words itself: the technician's own coordinates stay out of the props.
            'checkIn' => $checkIn === null ? null : Arr::only($checkIn, ['status', 'distance_m', 'accuracy_m', 'reason']),
            // The four steps the apps show, and the one the visit is at.
            'steps' => TripSteps::of($wo),
            'currentStep' => TripSteps::current($wo),
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
        abort_if(! in_array($wo->status, ['draft', 'generated']), 422, 'SPK hanya bisa diubah saat berstatus draf atau menunggu penugasan.');

        $wo->load(['customer', 'subscription', 'location', 'assignee']);

        return Inertia::render('Admin/SPK/Edit', [
            'workOrder' => new WorkOrderResource($wo),
            'customers' => CustomerResource::collection(Customer::query()->where('is_active', true)->orderBy('name')->get()),
            'subscriptions' => SubscriptionResource::collection(ServiceSubscription::query()->whereIn('status', ['pending', 'active'])->orderBy('code')->get()),
            'locations' => LocationResource::collection(Location::query()->where('is_active', true)->orderBy('code')->get()),
            'technicians' => $this->assignableTechnicians($wo),
        ]);
    }

    public function update(UpdateWorkOrderRequest $request, WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('update', $wo);
        abort_if(! in_array($wo->status, ['draft', 'generated']), 422, 'SPK hanya bisa diubah saat berstatus draf atau menunggu penugasan.');

        $wo->update($request->validated());

        return redirect()->route('admin.spk.index')->with('success', 'SPK berhasil diubah.');
    }

    public function destroy(WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('delete', $wo);
        abort_if(! in_array($wo->status, ['draft', 'cancelled']), 422, 'SPK hanya bisa dihapus saat berstatus draf atau dibatalkan.');

        $wo->delete();

        return back()->with('success', 'SPK berhasil dihapus.');
    }

    public function generate(WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('spk.update');
        SpkService::generate($wo);

        return back()->with('success', 'SPK berhasil diterbitkan.');
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

        return back()->with('success', 'SPK berhasil ditugaskan.');
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

        return back()->with('success', 'SPK mulai dikerjakan.');
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

        return back()->with('success', 'SPK diajukan untuk review.');
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

        return back()->with('success', 'SPK disetujui.');
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

        return back()->with('success', 'SPK ditolak.');
    }

    public function cancel(Request $request, WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        Gate::authorize('cancel', $wo);

        $request->validate(['reason' => ['required', 'string', 'max:500']]);
        SpkService::cancel($wo, $request->input('reason'));

        return back()->with('success', 'SPK dibatalkan.');
    }

    public function addItem(StoreWorkOrderItemRequest $request, WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);

        SpkService::addItem($wo, $request->validated());

        return back()->with('success', 'Item berhasil ditambahkan.');
    }

    public function removeItem(WorkOrder $wo, WorkOrderItem $item): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        RecordNotFound::abortUnless($item->work_order_id === $wo->id);
        Gate::authorize('spk.update');

        $item->delete();

        return back()->with('success', 'Item berhasil dihapus.');
    }

    public function uploadEvidence(StoreWorkOrderEvidenceRequest $request, WorkOrder $wo): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        SpkService::addEvidence($wo, $request->user(), $request->evidenceAttributes());

        return back()->with('success', 'Bukti berhasil diunggah.');
    }

    public function removeEvidence(Request $request, WorkOrder $wo, string $evidence): RedirectResponse
    {
        $this->ensureSameCompany($wo);
        SpkService::removeEvidence($wo, $evidence, $request->user());

        return back()->with('success', 'Bukti berhasil dihapus.');
    }

    private function ensureSameCompany(WorkOrder $wo): void
    {
        RecordNotFound::abortUnless($wo->company_id === CompanyService::currentId());
    }

    /**
     * The people the assign dialog offers, and only to someone who may assign this SPK. A dispatcher needs the user to
     * assign, the name, the employee number, the unit and the phone to choose: no email, role, HR note or vehicle goes out.
     * open_today is the load of the day: assigned and in-progress SPK scheduled today, counted in one grouped query.
     *
     * @return list<array{id: string, name: string, employee_number: string, organization: string|null, phone: string|null, open_today: int}>
     */
    private function assignableTechnicians(WorkOrder $wo): array
    {
        if (! Gate::allows('assign', $wo)) {
            return [];
        }

        $profiles = EmployeeProfile::query()
            ->with(['user:id,public_id,name', 'organization:id,name'])
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

        $openToday = WorkOrder::query()
            ->whereIn('assigned_to', $profiles->pluck('user.id'))
            ->whereIn('status', ['assigned', 'in_progress'])
            ->whereDate('scheduled_date', today())
            ->groupBy('assigned_to')
            ->selectRaw('assigned_to, count(*) as open_count')
            ->pluck('open_count', 'assigned_to');

        return $profiles
            ->map(fn (EmployeeProfile $profile): array => [
                'id' => $profile->user->public_id,
                'name' => $profile->user->getAttribute('name'),
                'employee_number' => $profile->employee_number,
                'organization' => $profile->organization?->name,
                'phone' => $profile->phone,
                'open_today' => (int) ($openToday[$profile->user->id] ?? 0),
            ])
            ->all();
    }

    /**
     * The create form's pickers, searched on the server. Subscriptions narrow to the customer the page sends as
     * `customer_id`, so a subscription of another customer is never offered.
     *
     * @return array<string, mixed>
     */
    private function createOptions(Request $request): array
    {
        $customerId = PublicId::resolveOne(Customer::class, $request->query('customer_id')) ?? 0;

        return [
            'customerOptions' => SearchOptions::for(
                $request,
                'customer',
                Customer::query()->where('is_active', true),
                ['name', 'code'],
                fn (Customer $customer): array => ['value' => $customer->public_id, 'label' => $customer->name, 'description' => $customer->code],
            ),
            'subscriptionOptions' => SearchOptions::for(
                $request,
                'subscription',
                ServiceSubscription::query()
                    ->with('customer:id,name')
                    ->whereIn('status', ['pending', 'active'])
                    ->when($customerId > 0, fn (Builder $query) => $query->where('customer_id', $customerId)),
                ['code'],
                fn (ServiceSubscription $subscription): array => ['value' => $subscription->public_id, 'label' => $subscription->code, 'description' => $subscription->customer?->name],
            ),
            'locationOptions' => SearchOptions::for(
                $request,
                'location',
                Location::query()->where('is_active', true),
                ['code', 'name'],
                fn (Location $location): array => ['value' => $location->public_id, 'label' => $location->code.' - '.$location->name],
            ),
        ];
    }
}
