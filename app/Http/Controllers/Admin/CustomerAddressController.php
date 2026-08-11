<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCustomerAddressRequest;
use App\Http\Requests\Admin\UpdateCustomerAddressRequest;
use App\Http\Resources\CustomerAddressResource;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Services\Core\CompanyService;
use App\Support\ExportQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerAddressController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['label', 'city', 'postal_code', 'created_at'];

    public function index(Customer $customer): InertiaResponse
    {
        $this->ensureSameCompany($customer);
        Gate::authorize('customer.address.manage');

        return Inertia::render('Admin/CustomerAddresses/Index', [
            'customer' => $customer->only(['id', 'code', 'name']),
            'addresses' => CustomerAddressResource::collection($this->filteredQuery($customer, request())->paginate(10)->withQueryString()),
            'filters' => request()->only(['search', 'sort', 'direction']),
            'can' => ['export' => request()->user()?->can('customer.address.export') ?? false],
        ]);
    }

    public function export(Customer $customer, Request $request): Response|StreamedResponse
    {
        $this->ensureSameCompany($customer);
        Gate::authorize('customer.address.export');

        $format = strtolower((string) $request->input('format', 'csv'));
        $stamp = now()->format('Ymd-His');
        $export = ExportQuery::make($this->filteredQuery($customer, $request))
            ->defaultSort('label', 'asc')
            ->maxRows(ExportQuery::resolveMaxRows(config('exports.max_rows', ExportQuery::DEFAULT_MAX_ROWS)));

        $columns = [
            'label' => 'Label',
            'address' => 'Address',
            'city' => 'City',
            'postal_code' => 'Postal Code',
            'is_installation_point' => 'Install Point',
            'is_primary' => 'Primary',
            'notes' => 'Notes',
        ];

        $map = static fn (CustomerAddress $address): array => [
            'label' => $address->label,
            'address' => $address->address,
            'city' => $address->city ?? '',
            'postal_code' => $address->postal_code ?? '',
            'is_installation_point' => $address->is_installation_point ? 'Yes' : 'No',
            'is_primary' => $address->is_primary ? 'Yes' : 'No',
            'notes' => $address->notes ?? '',
        ];

        if ($format === 'pdf') {
            return $export->streamPdf('Customer Addresses', $columns, $map, "customer-addresses-{$customer->id}-{$stamp}.pdf");
        }

        return $export->streamCsv($columns, $map, "customer-addresses-{$customer->id}-{$stamp}.csv");
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

    /** @return Builder<CustomerAddress> */
    private function filteredQuery(Customer $customer, Request $request): Builder
    {
        $query = $customer->addresses()
            ->when(trim((string) $request->input('search')) !== '', function (Builder $query) use ($request): void {
                $term = '%'.trim((string) $request->input('search')).'%';
                $query->where(function (Builder $sub) use ($term): void {
                    $sub->where('label', 'like', $term)
                        ->orWhere('address', 'like', $term)
                        ->orWhere('city', 'like', $term)
                        ->orWhere('postal_code', 'like', $term)
                        ->orWhere('notes', 'like', $term);
                });
            });

        return $this->applySort($query, $request, 'created_at', 'desc');
    }
}
