<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSubscriptionRequest;
use App\Http\Requests\Admin\UpdateSubscriptionRequest;
use App\Http\Resources\CustomerAddressResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Core\Customer;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use App\Services\Core\SubscriptionService;
use App\Support\ExportQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Service\Http\Resources\ServicePackageResource;
use Modules\Service\Models\ServicePackage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubscriptionController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['code', 'status', 'created_at'];

    public function indexForCustomer(Customer $customer, Request $request): InertiaResponse
    {
        $this->ensureSameCompany($customer);
        Gate::authorize('customer.subscription.view');

        return Inertia::render('Admin/Subscriptions/Index', [
            'customer' => $customer->only(['id', 'code', 'name']),
            'subscriptions' => SubscriptionResource::collection(
                $this->filteredQuery($customer, $request)->paginate(10)->withQueryString()
            ),
            'packages' => ServicePackageResource::collection(ServicePackage::query()->where('is_active', true)->orderBy('name')->get()),
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
            ->with('success', 'Subscription created.');
    }

    public function show(ServiceSubscription $subscription): InertiaResponse
    {
        $this->ensureSameCompany($subscription);
        Gate::authorize('customer.subscription.view');

        $subscription->load(['servicePackage', 'customer', 'installationAddress', 'servingPop']);

        return Inertia::render('Admin/Subscriptions/Show', [
            'subscription' => new SubscriptionResource($subscription),
        ]);
    }

    public function update(UpdateSubscriptionRequest $request, ServiceSubscription $subscription): RedirectResponse
    {
        $this->ensureSameCompany($subscription);
        Gate::authorize('customer.subscription.manage');

        $data = $request->validated();
        unset($data['status'], $data['code']);

        $subscription->update($data);

        return back()->with('success', 'Subscription updated.');
    }

    public function activate(Request $request, ServiceSubscription $subscription): RedirectResponse
    {
        $this->ensureSameCompany($subscription);
        Gate::authorize('customer.subscription.activate');

        SubscriptionService::activate($subscription);

        return back()->with('success', 'Subscription activated.');
    }

    public function suspend(Request $request, ServiceSubscription $subscription): RedirectResponse
    {
        $this->ensureSameCompany($subscription);
        Gate::authorize('customer.subscription.suspend');

        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        SubscriptionService::suspend($subscription, $request->input('reason'));

        return back()->with('success', 'Subscription suspended.');
    }

    public function reactivate(Request $request, ServiceSubscription $subscription): RedirectResponse
    {
        $this->ensureSameCompany($subscription);
        Gate::authorize('customer.subscription.reactivate');

        SubscriptionService::reactivate($subscription);

        return back()->with('success', 'Subscription reactivated.');
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

        return back()->with('success', 'Subscription terminated.');
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
            'code' => 'Code',
            'service_package' => 'Package',
            'status' => 'Status',
            'billing_day' => 'Billing Day',
            'mrc_amount' => 'MRC',
            'otc_installation_fee' => 'OTC',
            'activation_date' => 'Activated',
            'expiration_date' => 'Expires',
        ];
        $map = static fn (ServiceSubscription $subscription): array => [
            'code' => $subscription->code,
            'service_package' => $subscription->servicePackage?->name ?? '-',
            'status' => $subscription->status,
            'billing_day' => $subscription->billing_day,
            'mrc_amount' => $subscription->mrc_amount,
            'otc_installation_fee' => $subscription->otc_installation_fee,
            'activation_date' => optional($subscription->activation_date)?->toDateString() ?? '-',
            'expiration_date' => optional($subscription->expiration_date)?->toDateString() ?? '-',
        ];

        $filename = 'subscriptions-'.$customer->id.'-'.now()->format('Ymd-His');

        return strtolower((string) $request->input('format', 'csv')) === 'pdf'
            ? $export->streamPdf('Subscriptions', $columns, $map, $filename.'.pdf')
            : $export->streamCsv($columns, $map, $filename.'.csv');
    }

    /** @return Builder<ServiceSubscription> */
    private function filteredQuery(Customer $customer, Request $request): Builder
    {
        $query = $customer->subscriptions()
            ->with(['servicePackage', 'installationAddress', 'servingPop'])
            ->when(trim((string) $request->input('search')) !== '', function (Builder $query) use ($request): void {
                $term = '%'.trim((string) $request->input('search')).'%';
                $query->where(function (Builder $sub) use ($term): void {
                    $sub->where('code', 'like', $term)
                        ->orWhereHas('servicePackage', fn (Builder $pkg) => $pkg->where('name', 'like', $term)->orWhere('code', 'like', $term));
                });
            })
            ->when($request->filled('service_package_id'), fn (Builder $query) => $query->where('service_package_id', $request->integer('service_package_id')))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')->toString()));

        return $this->applySort($query, $request, 'created_at', 'desc');
    }

    private function ensureSameCompany(Customer|ServiceSubscription $model): void
    {
        abort_unless($model->company_id === CompanyService::currentId(), 404);
    }
}
