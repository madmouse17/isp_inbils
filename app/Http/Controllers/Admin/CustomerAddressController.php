<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCustomerAddressRequest;
use App\Http\Requests\Admin\UpdateCustomerAddressRequest;
use App\Http\Resources\CustomerAddressResource;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Services\Core\CompanyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class CustomerAddressController extends Controller
{
    public function index(Customer $customer): InertiaResponse
    {
        $this->ensureSameCompany($customer);
        Gate::authorize('customer.address.manage');

        return Inertia::render('Admin/CustomerAddresses/Index', [
            'customer' => $customer->only(['id', 'code', 'name']),
            'addresses' => CustomerAddressResource::collection($customer->addresses()->latest()->get()),
        ]);
    }

    public function store(StoreCustomerAddressRequest $request, Customer $customer): RedirectResponse
    {
        $this->ensureSameCompany($customer);
        Gate::authorize('customer.address.manage');

        $data = $request->validated();

        DB::transaction(function () use ($customer, $data) {
            if (($data['is_installation_point'] ?? false) === true) {
                $customer->addresses()->update(['is_installation_point' => false]);
            }
            $customer->addresses()->create($data);
        });

        return back()->with('success', 'Address added.');
    }

    public function update(UpdateCustomerAddressRequest $request, Customer $customer, CustomerAddress $address): RedirectResponse
    {
        $this->ensureSameCompany($customer);
        abort_unless($address->customer_id === $customer->id, 404);
        Gate::authorize('customer.address.manage');

        $data = $request->validated();

        DB::transaction(function () use ($customer, $address, $data) {
            if (($data['is_installation_point'] ?? false) === true && ! $address->is_installation_point) {
                $customer->addresses()->where('id', '!=', $address->id)->update(['is_installation_point' => false]);
            }
            $address->update($data);
        });

        return back()->with('success', 'Address updated.');
    }

    public function destroy(Customer $customer, CustomerAddress $address): RedirectResponse
    {
        $this->ensureSameCompany($customer);
        abort_unless($address->customer_id === $customer->id, 404);
        Gate::authorize('customer.address.manage');

        abort_if($address->subscriptions()->exists(), 422, 'Cannot delete address with active subscription.');

        $address->delete();

        return back()->with('success', 'Address deleted.');
    }

    private function ensureSameCompany(Customer $customer): void
    {
        abort_unless($customer->company_id === CompanyService::currentId(), 404);
    }
}
