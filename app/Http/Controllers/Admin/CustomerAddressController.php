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
use App\Services\Core\IndonesiaRegionService;
use App\Services\Core\OpenStreetMapGeocoder;
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

    public function __construct(private readonly OpenStreetMapGeocoder $geocoder) {}

    public function index(Customer $customer, Request $request): InertiaResponse
    {
        $this->ensureSameCompany($customer);
        Gate::authorize('customer.address.manage');

        return Inertia::render('Admin/CustomerAddresses/Index', [
            'customer' => $customer->only(['id', 'code', 'name']),
            'addresses' => CustomerAddressResource::collection($this->filteredQuery($customer, $request)->paginate(10)->withQueryString()),
            'filters' => $request->only(['search', 'sort', 'direction']),
            'can' => ['export' => $request->user()?->can('customer.address.export') ?? false],
            'regions' => fn () => IndonesiaRegionService::options(
                $request->input('region_provinces', []),
                $request->input('region_cities', []),
                $request->input('region_districts', []),
            ),
            'geocodeResults' => Inertia::optional(
                fn () => $this->geocoder->search((string) $request->input('geocode_query')),
            ),
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
            'address' => 'Alamat',
            'city' => 'Kota',
            'postal_code' => 'Kode pos',
            'is_installation_point' => 'Titik pemasangan',
            'is_primary' => 'Utama',
            'notes' => 'Catatan',
        ];

        $map = static fn (CustomerAddress $address): array => [
            'label' => $address->label,
            'address' => $address->address,
            'city' => $address->city ?? '',
            'postal_code' => $address->postal_code ?? '',
            'is_installation_point' => $address->is_installation_point ? 'Ya' : 'Tidak',
            'is_primary' => $address->is_primary ? 'Ya' : 'Tidak',
            'notes' => $address->notes ?? '',
        ];

        if ($format === 'pdf') {
            return $export->streamPdf('Alamat Pelanggan', $columns, $map, "customer-addresses-{$customer->id}-{$stamp}.pdf");
        }

        return $export->streamCsv($columns, $map, "customer-addresses-{$customer->id}-{$stamp}.csv");
    }

    public function store(StoreCustomerAddressRequest $request, Customer $customer): RedirectResponse
    {
        $this->ensureSameCompany($customer);
        Gate::authorize('customer.address.manage');

        $data = IndonesiaRegionService::normalizeAddress($request->validated());

        DB::transaction(function () use ($customer, $data) {
            $customer->addresses()->lockForUpdate()->get();

            $hasAddresses = $customer->addresses()->exists();

            if (! $hasAddresses) {
                // First address must satisfy invariant A: exactly one primary
                // and exactly one installation point.
                $data['is_primary'] = true;
                $data['is_installation_point'] = true;
            } else {
                if (($data['is_primary'] ?? false) === true) {
                    $customer->addresses()->update(['is_primary' => false]);
                }
                if (($data['is_installation_point'] ?? false) === true) {
                    $customer->addresses()->update(['is_installation_point' => false]);
                }
            }

            $customer->addresses()->create($data);
        });

        return back()->with('success', 'Alamat ditambahkan.');
    }

    public function update(UpdateCustomerAddressRequest $request, Customer $customer, CustomerAddress $address): RedirectResponse
    {
        $this->ensureSameCompany($customer);
        abort_unless($address->customer_id === $customer->id, 404);
        Gate::authorize('customer.address.manage');

        $data = IndonesiaRegionService::normalizeAddress($request->validated());

        DB::transaction(function () use ($customer, $address, $data) {
            $customer->addresses()->lockForUpdate()->get();

            if (($data['is_primary'] ?? false) === true) {
                $customer->addresses()->where('id', '!=', $address->id)->update(['is_primary' => false]);
            }
            if (($data['is_installation_point'] ?? false) === true) {
                $customer->addresses()->where('id', '!=', $address->id)->update(['is_installation_point' => false]);
            }

            $address->update($data);

            // Invariant A: a non-empty collection must keep exactly one primary
            // and one installation point marker.
            $address->refresh();
            $siblings = $customer->addresses()->where('id', '!=', $address->id)->get(['id', 'is_primary', 'is_installation_point']);

            if (! $address->is_primary && $siblings->where('is_primary', true)->isEmpty()) {
                if ($siblings->isEmpty()) {
                    $address->update(['is_primary' => true]);
                } else {
                    $customer->addresses()->whereKey($siblings->first()->id)->update(['is_primary' => true]);
                }
            }

            if (! $address->is_installation_point && $siblings->where('is_installation_point', true)->isEmpty()) {
                if ($siblings->isEmpty()) {
                    $address->update(['is_installation_point' => true]);
                } else {
                    $customer->addresses()->whereKey($siblings->first()->id)->update(['is_installation_point' => true]);
                }
            }
        });

        return back()->with('success', 'Alamat diperbarui.');
    }

    public function destroy(Customer $customer, CustomerAddress $address): RedirectResponse
    {
        $this->ensureSameCompany($customer);
        abort_unless($address->customer_id === $customer->id, 404);
        Gate::authorize('customer.address.manage');

        if ($address->subscriptions()->exists()) {
            return back()->withErrors(['address' => 'Alamat yang dipakai langganan tidak bisa dihapus.']);
        }

        DB::transaction(function () use ($customer, $address) {
            $customer->addresses()->lockForUpdate()->get();

            $wasPrimary = $address->is_primary;
            $wasInstallationPoint = $address->is_installation_point;

            $address->delete();

            // Invariant A: promote the earliest remaining row to keep exactly
            // one primary / installation-point marker in a non-empty collection.
            $remaining = $customer->addresses()->orderBy('id')->get();

            if ($remaining->isNotEmpty()) {
                if ($wasPrimary && $remaining->where('is_primary', true)->isEmpty()) {
                    $customer->addresses()->whereKey($remaining->first()->id)->update(['is_primary' => true]);
                }
                if ($wasInstallationPoint && $remaining->where('is_installation_point', true)->isEmpty()) {
                    $customer->addresses()->whereKey($remaining->first()->id)->update(['is_installation_point' => true]);
                }
            }
        });

        return back()->with('success', 'Alamat dihapus.');
    }

    private function ensureSameCompany(Customer $customer): void
    {
        abort_unless($customer->company_id === CompanyService::currentId(), 404);
    }

    /** @return Builder<CustomerAddress> */
    private function filteredQuery(Customer $customer, Request $request): Builder
    {
        $query = $customer->addresses()
            ->getQuery()
            ->with(['province', 'regionCity', 'district', 'village'])
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
