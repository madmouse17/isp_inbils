<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeSubscriptionPackageRequest;
use App\Http\Requests\Admin\StoreSubscriptionRequest;
use App\Http\Requests\Admin\UpdateSubscriptionRequest;
use App\Http\Resources\CustomerAddressResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Core\Customer;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use App\Services\Core\SubscriptionService;
use App\Support\ExportQuery;
use App\Support\OptimisticLock;
use App\Support\PublicId;
use App\Support\RecordNotFound;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\OptionalProp;
use Inertia\Response as InertiaResponse;
use Modules\Ipam\Queries\IpPrefixUtilisationQuery;
use Modules\Ipam\Services\IpAssignmentService;
use Modules\NetworkAsset\Queries\OdnOccupancyQuery;
use Modules\NetworkAsset\Services\OdnService;
use Modules\Service\Models\ServicePackage;
use Modules\Service\Services\ServicePackageOptions;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubscriptionController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['code', 'status', 'created_at'];

    /** Export labels; the stored values stay as they are (`lib/statuses/subscription.ts` carries the same words for the pages). */
    private const STATUS_LABELS = ['pending' => 'Menunggu', 'active' => 'Aktif', 'suspended' => 'Ditangguhkan', 'terminated' => 'Berhenti'];

    public function indexForCustomer(Customer $customer, Request $request): InertiaResponse
    {
        $this->ensureSameCompany($customer);
        Gate::authorize('customer.subscription.view');

        return Inertia::render('Admin/Subscriptions/Index', [
            'customer' => ['id' => $customer->public_id, 'code' => $customer->code, 'name' => $customer->name],
            'subscriptions' => SubscriptionResource::collection(
                $this->filteredQuery($customer, $request)->paginate(10)->withQueryString()
            ),
            'packageGroups' => ServicePackageOptions::grouped($customer->type),
            'addresses' => CustomerAddressResource::collection($customer->addresses()->latest()->get()),
            'filters' => $request->only(['search', 'service_package_id', 'status', 'sort', 'direction']),
            'can' => ['export' => (bool) ($request->user()?->can('customer.subscription.export'))],
        ]);
    }

    public function storeForCustomer(StoreSubscriptionRequest $request, Customer $customer): RedirectResponse
    {
        $this->ensureSameCompany($customer);
        Gate::authorize('customer.subscription.manage');

        $data = $request->validated();
        $data['customer_id'] = $customer->id;

        SubscriptionService::create($data);

        return redirect()->route('admin.customers.subscriptions.index', $customer)
            ->with('success', 'Langganan dibuat.');
    }

    public function show(Request $request, ServiceSubscription $subscription): InertiaResponse
    {
        $this->ensureSameCompany($subscription);
        Gate::authorize('customer.subscription.view');

        $subscription->load([
            'servicePackage.category',
            'servicePackage.latestVersion',
            'packageVersion.bandwidthProfile',
            'packageVersion.speedProfile',
            'packageVersion.slaTier',
            'packageVersion.category',
            'customer',
            'installationAddress',
            'servingPop',
            'blockedBy:id,public_id,name',
        ]);

        $user = $request->user();
        $canAssignIp = $user?->can('ipam.assign') ?? false;
        $canAssignDrop = $user?->can('odn.assign') ?? false;
        $canChangePackage = $user?->can('customer.subscription.manage') ?? false;

        return Inertia::render('Admin/Subscriptions/Show', [
            'subscription' => new SubscriptionResource($subscription),
            'ipAddresses' => ($user?->can('ipam.view') ?? false)
                ? IpAssignmentService::forSubscription($subscription)->map(fn ($address): array => [
                    'id' => $address->public_id,
                    'family' => $address->family,
                    'address' => $address->address,
                    'prefix_cidr' => $address->prefix?->cidr,
                    'status' => $address->status,
                ])->all()
                : [],
            // Pools the NOC can draw a static address from; offered only to holders of ipam.assign.
            ...($canAssignIp ? ['staticPools' => IpPrefixUtilisationQuery::staticPools($subscription->serving_pop_id)] : []),
            'dropPort' => ($user?->can('odn.view') ?? false) ? OdnService::dropPortFor($subscription) : null,
            // Free ODP ports under the serving POP; evaluated only on a partial reload that asks for it.
            ...($canAssignDrop ? ['dropPortOptions' => OdnOccupancyQuery::dropPortOptions($request, $subscription->serving_pop_id)] : []),
            // The packages a subscription can move to; evaluated only on a partial reload that asks for it.
            ...($canChangePackage ? ['packageOptions' => $this->packageOptions()] : []),
            'can' => ['assignIp' => $canAssignIp, 'assignDrop' => $canAssignDrop, 'block' => $user?->can('customer.subscription.block') ?? false, 'changePackage' => $canChangePackage],
        ]);
    }

    public function update(UpdateSubscriptionRequest $request, ServiceSubscription $subscription): RedirectResponse
    {
        $this->ensureSameCompany($subscription);
        Gate::authorize('customer.subscription.manage');

        $data = $request->safe()->except(OptimisticLock::FIELD);
        unset($data['status'], $data['code']);

        DB::transaction(function () use ($request, $subscription, $data) {
            $subscription = ServiceSubscription::query()->lockForUpdate()->findOrFail($subscription->id);
            OptimisticLock::assert($subscription, $request->string(OptimisticLock::FIELD)->toString());

            $addressChanged = isset($data['installation_address_id']) && (int) $data['installation_address_id'] !== (int) $subscription->installation_address_id;

            $subscription->update($data);

            if ($addressChanged) {
                $subscription->load('installationAddress', 'customer');
                $generatedSpk = $subscription->workOrders()
                    ->whereIn('status', ['draft', 'generated', 'rejected', 'cancelled'])
                    ->latest()
                    ->first();

                if ($generatedSpk) {
                    $generatedSpk->update([
                        'subscription_id' => $subscription->id,
                        'title' => 'Pemasangan '.$subscription->code.' - '.($subscription->customer->name ?? ''),
                    ]);
                }
            }
        });

        return back()->with('success', 'Langganan diperbarui.');
    }

    public function changePackage(ChangeSubscriptionPackageRequest $request, ServiceSubscription $subscription): RedirectResponse
    {
        $this->ensureSameCompany($subscription);
        Gate::authorize('customer.subscription.manage');

        $data = $request->validated();

        SubscriptionService::changePackage($subscription, (int) $data['service_package_id'], $data['mrc_amount'] ?? null);

        return back()->with('success', 'Langganan dipindahkan ke versi terbaru paket.');
    }

    public function activate(Request $request, ServiceSubscription $subscription): RedirectResponse
    {
        $this->ensureSameCompany($subscription);
        Gate::authorize('customer.subscription.activate');

        SubscriptionService::activate($subscription);

        return back()->with('success', 'Langganan diaktifkan.');
    }

    public function suspend(Request $request, ServiceSubscription $subscription): RedirectResponse
    {
        $this->ensureSameCompany($subscription);
        Gate::authorize('customer.subscription.suspend');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'source' => ['nullable', 'in:manual,overdue'],
        ]);

        SubscriptionService::suspend($subscription, $validated['reason'], $validated['source'] ?? 'manual');

        return back()->with('success', 'Langganan ditangguhkan.');
    }

    public function block(Request $request, ServiceSubscription $subscription): RedirectResponse
    {
        $this->ensureSameCompany($subscription);
        Gate::authorize('customer.subscription.block');

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        SubscriptionService::block($subscription, $validated['reason']);

        return back()->with('success', 'Internet langganan diblokir.');
    }

    public function unblock(Request $request, ServiceSubscription $subscription): RedirectResponse
    {
        $this->ensureSameCompany($subscription);
        Gate::authorize('customer.subscription.block');

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        SubscriptionService::unblock($subscription, $validated['reason'] ?? null);

        return back()->with('success', 'Blokir internet langganan dibuka.');
    }

    public function reactivate(Request $request, ServiceSubscription $subscription): RedirectResponse
    {
        $this->ensureSameCompany($subscription);
        Gate::authorize('customer.subscription.reactivate');

        SubscriptionService::reactivate($subscription);

        return back()->with('success', 'Langganan diaktifkan kembali.');
    }

    public function terminate(Request $request, ServiceSubscription $subscription): RedirectResponse
    {
        $this->ensureSameCompany($subscription);
        Gate::authorize('customer.subscription.terminate');

        $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'release_ont' => ['boolean'],
        ]);

        SubscriptionService::terminate($subscription, $request->input('reason'), $request->boolean('release_ont'));

        return back()->with('success', 'Langganan dihentikan.');
    }

    public function export(Customer $customer, Request $request): HttpResponse|StreamedResponse
    {
        $this->ensureSameCompany($customer);
        Gate::authorize('customer.subscription.export');

        $export = ExportQuery::make($this->filteredQuery($customer, $request))
            ->defaultSort('code', 'asc')
            ->fromRequest($request)
            ->maxRows((int) config('exports.max_rows', 5000));

        $columns = [
            'code' => 'Kode',
            'service_package' => 'Paket',
            'status' => 'Status',
            'billing_day' => 'Tanggal tagihan',
            'mrc_amount' => 'Biaya bulanan',
            'otc_installation_fee' => 'Biaya pemasangan',
            'activation_date' => 'Tanggal aktivasi',
            'expiration_date' => 'Tanggal berakhir',
        ];
        $map = static fn (ServiceSubscription $subscription): array => [
            'code' => $subscription->code,
            'service_package' => $subscription->servicePackage->name ?? '-',
            'status' => self::STATUS_LABELS[$subscription->status] ?? $subscription->status,
            'billing_day' => $subscription->billing_day,
            'mrc_amount' => $subscription->mrc_amount,
            'otc_installation_fee' => $subscription->otc_installation_fee,
            'activation_date' => optional($subscription->activation_date)?->toDateString() ?? '-',
            'expiration_date' => optional($subscription->expiration_date)?->toDateString() ?? '-',
        ];

        $filename = 'subscriptions-'.$customer->public_id.'-'.now()->format('Ymd-His');

        return strtolower((string) $request->input('format', 'csv')) === 'pdf'
            ? $export->streamPdf('Langganan', $columns, $map, $filename.'.pdf')
            : $export->streamCsv($columns, $map, $filename.'.csv');
    }

    /** @return Builder<ServiceSubscription> */
    private function filteredQuery(Customer $customer, Request $request): Builder
    {
        $query = $customer->subscriptions()
            ->getQuery()
            ->with(['servicePackage.category', 'installationAddress', 'servingPop'])
            ->when(trim((string) $request->input('search')) !== '', function (Builder $query) use ($request): void {
                $term = '%'.trim((string) $request->input('search')).'%';
                $query->where(function (Builder $sub) use ($term): void {
                    $sub->where('code', 'like', $term)
                        ->orWhereHas('servicePackage', fn (Builder $pkg) => $pkg->where('name', 'like', $term)->orWhere('code', 'like', $term));
                });
            })
            ->when($request->filled('service_package_id'), fn (Builder $query) => $query->where('service_package_id', PublicId::resolveOne(ServicePackage::class, $request->input('service_package_id')) ?? 0))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')->toString()));

        return $this->applySort($query, $request, 'created_at', 'desc');
    }

    private function packageOptions(): OptionalProp
    {
        // shortcut: the whole active catalogue in one list, a few dozen rows; search it on the server (SearchOptions) when a company outgrows a select.
        return Inertia::optional(fn (): array => ServicePackage::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['public_id', 'code', 'name', 'price_mrc'])
            ->map(fn (ServicePackage $package): array => [
                'id' => $package->public_id,
                'code' => $package->getAttribute('code'),
                'name' => $package->name,
                'price_mrc' => $package->getAttribute('price_mrc'),
            ])
            ->all());
    }

    private function ensureSameCompany(Customer|ServiceSubscription $model): void
    {
        RecordNotFound::abortUnless($model->company_id === CompanyService::currentId());
    }
}
