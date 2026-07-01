<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCustomerRequest;
use App\Http\Requests\Admin\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Core\Customer;
use App\Services\Core\CompanyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class CustomerController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $customers = Customer::query()
            ->withCount(['addresses', 'subscriptions'])
            ->when($request->input('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->input('is_active'), fn ($q, $v) => $q->where('is_active', $v === 'true'))
            ->when($request->input('search'), fn ($q, $v) => $q->where(fn ($sq) => $sq
                ->where('name', 'like', "%{$v}%")
                ->orWhere('code', 'like', "%{$v}%")
                ->orWhere('phone', 'like', "%{$v}%")))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Admin/Customers/Index', [
            'customers' => CustomerResource::collection($customers),
            'filters' => $request->only(['type', 'is_active', 'search']),
        ]);
    }

    public function create(Request $request): InertiaResponse
    {
        Gate::authorize('create', Customer::class);

        return Inertia::render('Admin/Customers/Create');
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        Gate::authorize('store', Customer::class);

        $customer = Customer::create($request->validated());

        return redirect()->route('admin.customers.show', $customer)
            ->with('success', 'Customer created.');
    }

    public function show(Customer $customer): InertiaResponse
    {
        $this->ensureSameCompany($customer);
        Gate::authorize('view', $customer);

        $customer->load(['addresses', 'contacts', 'subscriptions.servicePackage']);

        return Inertia::render('Admin/Customers/Show', [
            'customer' => new CustomerResource($customer),
        ]);
    }

    public function edit(Customer $customer): InertiaResponse
    {
        $this->ensureSameCompany($customer);
        Gate::authorize('edit', $customer);

        return Inertia::render('Admin/Customers/Edit', [
            'customer' => new CustomerResource($customer),
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->ensureSameCompany($customer);
        Gate::authorize('update', $customer);

        $customer->update($request->validated());

        return back()->with('success', 'Customer updated.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $this->ensureSameCompany($customer);
        Gate::authorize('delete', $customer);

        if ($customer->subscriptions()->whereNotIn('status', ['terminated'])->exists()) {
            return back()->withErrors(['customer' => 'Cannot delete customer with active subscription.']);
        }

        $customer->delete();

        return redirect()->route('admin.customers.index')
            ->with('success', 'Customer deleted.');
    }

    public function export(Request $request): Response
    {
        Gate::authorize('customer.export');

        $customers = Customer::query()
            ->withCount(['addresses', 'subscriptions'])
            ->latest()
            ->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=customers.csv',
        ];

        $csv = "Code,Name,Type,Email,Phone,IsActive\n";
        foreach ($customers as $c) {
            $csv .= implode(',', [
                $c->code,
                $c->name,
                $c->type,
                $c->email ?? '',
                $c->phone ?? '',
                $c->is_active ? 'Yes' : 'No',
            ])."\n";
        }

        return response($csv, 200, $headers);
    }

    private function ensureSameCompany(Customer $customer): void
    {
        abort_unless($customer->company_id === CompanyService::currentId(), 404);
    }
}
