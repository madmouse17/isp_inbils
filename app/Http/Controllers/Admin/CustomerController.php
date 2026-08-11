<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCustomerRequest;
use App\Http\Requests\Admin\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Core\Customer;
use App\Services\Core\CustomerService;
use App\Support\ExportQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['code', 'name', 'type', 'phone', 'is_active', 'created_at'];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $customers = $this->filteredQuery($request)
            ->paginate($this->perPage($request))
            ->withQueryString();

        return Inertia::render('Admin/Customers/Index', [
            'customers' => CustomerResource::collection($customers),
            'filters' => $request->only(['type', 'status', 'search', 'sort', 'direction', 'per_page']),
            'can' => [
                'create' => $request->user()?->can('customer.create') ?? false,
                'export' => $request->user()?->can('customer.export') ?? false,
            ],
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

        CustomerService::createWithUser($request->validated());

        return redirect()->route('admin.customers.index')
            ->with('success', 'Customer created.');
    }

    public function show(Request $request, int|string $customer): InertiaResponse
    {
        $customer = $this->findForCompany($request, $customer);
        Gate::authorize('view', $customer);

        $customer->load(['addresses', 'contacts', 'subscriptions.servicePackage']);

        return Inertia::render('Admin/Customers/Show', [
            'customer' => new CustomerResource($customer),
        ]);
    }

    public function edit(Request $request, int|string $customer): InertiaResponse
    {
        $customer = $this->findForCompany($request, $customer);
        Gate::authorize('edit', $customer);
        $customer->load(['addresses', 'contacts', 'subscriptions.servicePackage']);

        return Inertia::render('Admin/Customers/Edit', [
            'customer' => new CustomerResource($customer),
        ]);
    }

    public function update(UpdateCustomerRequest $request, int|string $customer): RedirectResponse
    {
        $customer = $this->findForCompany($request, $customer);
        Gate::authorize('update', $customer);

        $customer->update($request->validated());

        return redirect()->route('admin.customers.index')
            ->with('success', 'Customer updated.');
    }

    public function destroy(Request $request, int|string $customer): RedirectResponse
    {
        $customer = $this->findForCompany($request, $customer);
        Gate::authorize('delete', $customer);

        if ($customer->subscriptions()->whereNotIn('status', ['terminated'])->exists()) {
            return back()->withErrors(['customer' => 'Cannot delete customer with active subscription.']);
        }

        $customer->delete();

        return redirect()->route('admin.customers.index')
            ->with('success', 'Customer deleted.');
    }

    public function export(Request $request): Response|StreamedResponse
    {
        Gate::authorize('customer.export');

        $format = strtolower((string) $request->input('format', 'csv'));
        $stamp = now()->format('Ymd-His');
        $export = ExportQuery::make($this->filteredQuery($request))
            ->defaultSort('code', 'asc')
            ->maxRows((int) config('exports.max_rows', 5000))
            ->fromRequest($request);

        $columns = [
            'code' => 'Code',
            'name' => 'Name',
            'type' => 'Type',
            'email' => 'Email',
            'phone' => 'Phone',
            'is_active' => 'Status',
        ];

        $map = static fn (Customer $customer): array => [
            'code' => $customer->code,
            'name' => $customer->name,
            'type' => $customer->type,
            'email' => $customer->email ?? '',
            'phone' => $customer->phone ?? '',
            'is_active' => $customer->is_active ? 'Active' : 'Inactive',
        ];

        return $format === 'pdf'
            ? $export->streamPdf('Customers', $columns, $map, "customers-export-{$stamp}.pdf")
            : $export->streamCsv($columns, $map, "customers-export-{$stamp}.csv");
    }

    /**
     * @return Builder<Customer>
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = Customer::query()
            ->withCount(['addresses', 'subscriptions'])
            ->when($request->input('type'), fn (Builder $query, string $value) => $query->where('type', $value))
            ->when($request->input('status'), function (Builder $query, string $value): void {
                $normalized = strtolower(trim($value));

                if ($normalized === 'active') {
                    $query->where('is_active', true);
                } elseif ($normalized === 'inactive') {
                    $query->where('is_active', false);
                }
            })
            ->when($request->input('search'), function (Builder $query, string $value): void {
                $term = trim($value);

                if ($term === '') {
                    return;
                }

                $query->where(function (Builder $sub) use ($term): void {
                    $like = '%'.$term.'%';
                    $sub->where('name', 'like', $like)
                        ->orWhere('code', 'like', $like)
                        ->orWhere('phone', 'like', $like);
                });
            });

        return $this->applySort($query, $request, 'code');
    }

    private function findForCompany(Request $request, int|string $customer): Customer
    {
        return Customer::forCompany($request->user()?->company_id)
            ->findOrFail($customer);
    }
}
