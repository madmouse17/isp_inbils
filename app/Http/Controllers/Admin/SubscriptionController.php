<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSubscriptionRequest;
use App\Http\Requests\Admin\UpdateSubscriptionRequest;
use App\Http\Resources\CustomerAddressResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Core\Customer;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use App\Services\Core\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Service\Http\Resources\ServicePackageResource;
use Modules\Service\Models\ServicePackage;

class SubscriptionController extends Controller
{
    public function indexForCustomer(Customer $customer): InertiaResponse
    {
        $this->ensureSameCompany($customer);
        Gate::authorize('customer.subscription.view');

        return Inertia::render('Admin/Subscriptions/Index', [
            'customer' => $customer->only(['id', 'code', 'name']),
            'subscriptions' => SubscriptionResource::collection(
                $customer->subscriptions()->with(['servicePackage', 'installationAddress', 'servingPop'])->latest()->paginate(10)->withQueryString()
            ),
            'packages' => ServicePackageResource::collection(ServicePackage::query()->where('is_active', true)->orderBy('name')->get()),
            'addresses' => CustomerAddressResource::collection($customer->addresses()->latest()->get()),
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

    private function ensureSameCompany(Customer|ServiceSubscription $model): void
    {
        abort_unless($model->company_id === CompanyService::currentId(), 404);
    }
}
