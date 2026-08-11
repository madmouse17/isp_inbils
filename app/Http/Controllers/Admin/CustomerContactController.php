<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCustomerContactRequest;
use App\Http\Requests\Admin\UpdateCustomerContactRequest;
use App\Http\Resources\CustomerContactResource;
use App\Models\Core\Customer;
use App\Models\Core\CustomerContact;
use App\Services\Core\CompanyService;
use App\Support\ExportQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerContactController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['name', 'role', 'phone', 'email', 'created_at'];

    public function index(Customer $customer, Request $request): InertiaResponse
    {
        $this->ensureSameCompany($customer);
        Gate::authorize('customer.address.manage');

        return Inertia::render('Admin/CustomerContacts/Index', [
            'customer' => $customer->only(['id', 'code', 'name']),
            'contacts' => CustomerContactResource::collection($this->filteredQuery($customer, $request)->paginate(10)->withQueryString()),
            'filters' => $request->only(['search', 'sort', 'direction']),
            'can' => ['export' => (bool) ($request->user()?->can('customer.contact.export'))],
        ]);
    }

    public function store(StoreCustomerContactRequest $request, Customer $customer): RedirectResponse
    {
        $this->ensureSameCompany($customer);
        Gate::authorize('customer.address.manage');

        $data = $request->validated();

        DB::transaction(function () use ($customer, $data) {
            if (($data['is_primary'] ?? false) === true) {
                CustomerContact::query()->where('customer_id', $customer->id)->update(['is_primary' => false]);
            }
            CustomerContact::query()->where('customer_id', $customer->id)->create($data);
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
                CustomerContact::query()->where('customer_id', $customer->id)->where('id', '!=', $contact->id)->update(['is_primary' => false]);
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

    public function export(Customer $customer, Request $request): HttpResponse|StreamedResponse
    {
        $this->ensureSameCompany($customer);
        Gate::authorize('customer.contact.export');

        $export = ExportQuery::make($this->filteredQuery($customer, $request))
            ->defaultSort('name', 'asc')
            ->fromRequest($request)
            ->maxRows(ExportQuery::resolveMaxRows(config('exports.max_rows', ExportQuery::DEFAULT_MAX_ROWS)));

        $columns = [
            'name' => 'Name',
            'role' => 'Position',
            'phone' => 'Phone',
            'email' => 'Email',
            'is_primary' => 'Primary',
        ];
        $map = static fn (CustomerContact $contact): array => [
            'name' => $contact->name,
            'role' => $contact->role ?? '-',
            'phone' => $contact->phone ?? '-',
            'email' => $contact->email ?? '-',
            'is_primary' => $contact->is_primary ? 'Yes' : 'No',
        ];

        $filename = 'customer-contacts-'.$customer->id.'-'.now()->format('Ymd-His');

        return strtolower((string) $request->input('format', 'csv')) === 'pdf'
            ? $export->streamPdf('Customer Contacts', $columns, $map, $filename.'.pdf')
            : $export->streamCsv($columns, $map, $filename.'.csv');
    }

    /** @return Builder<CustomerContact> */
    private function filteredQuery(Customer $customer, Request $request): Builder
    {
        $query = CustomerContact::query()->where('customer_id', $customer->id)
            ->when(trim((string) $request->input('search')) !== '', function (Builder $query) use ($request): void {
                $term = '%'.trim((string) $request->input('search')).'%';
                $query->where(function (Builder $sub) use ($term): void {
                    $sub->where('name', 'like', $term)
                        ->orWhere('role', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('email', 'like', $term);
                });
            });

        return $this->applySort($query, $request, 'name');
    }

    private function ensureSameCompany(Customer $customer): void
    {
        abort_unless($customer->company_id === CompanyService::currentId(), 404);
    }
}
