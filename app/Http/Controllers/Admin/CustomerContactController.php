<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCustomerContactRequest;
use App\Http\Requests\Admin\UpdateCustomerContactRequest;
use App\Http\Resources\CustomerContactResource;
use App\Models\Core\Customer;
use App\Models\Core\CustomerContact;
use App\Services\Core\CompanyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class CustomerContactController extends Controller
{
    public function index(Customer $customer): InertiaResponse
    {
        $this->ensureSameCompany($customer);
        Gate::authorize('customer.address.manage');

        return Inertia::render('Admin/CustomerContacts/Index', [
            'customer' => $customer->only(['id', 'code', 'name']),
            'contacts' => CustomerContactResource::collection($customer->contacts()->latest()->get()),
        ]);
    }

    public function store(StoreCustomerContactRequest $request, Customer $customer): RedirectResponse
    {
        $this->ensureSameCompany($customer);
        Gate::authorize('customer.address.manage');

        $data = $request->validated();

        DB::transaction(function () use ($customer, $data) {
            if (($data['is_primary'] ?? false) === true) {
                $customer->contacts()->update(['is_primary' => false]);
            }
            $customer->contacts()->create($data);
        });

        return back()->with('success', 'Contact added.');
    }

    public function update(UpdateCustomerContactRequest $request, Customer $customer, CustomerContact $contact): RedirectResponse
    {
        $this->ensureSameCompany($customer);
        abort_unless($contact->customer_id === $customer->id, 404);
        Gate::authorize('customer.address.manage');

        $data = $request->validated();

        DB::transaction(function () use ($customer, $contact, $data) {
            if (($data['is_primary'] ?? false) === true && ! $contact->is_primary) {
                $customer->contacts()->where('id', '!=', $contact->id)->update(['is_primary' => false]);
            }
            $contact->update($data);
        });

        return back()->with('success', 'Contact updated.');
    }

    public function destroy(Customer $customer, CustomerContact $contact): RedirectResponse
    {
        $this->ensureSameCompany($customer);
        abort_unless($contact->customer_id === $customer->id, 404);
        Gate::authorize('customer.address.manage');

        $contact->delete();

        return back()->with('success', 'Contact deleted.');
    }

    private function ensureSameCompany(Customer $customer): void
    {
        abort_unless($customer->company_id === CompanyService::currentId(), 404);
    }
}
