<?php

namespace App\Http\Controllers\Admin;

use App\Actions\AssignCustomerAcquirerAction;
use App\Actions\CreateCustomerOnboardingAction;
use App\Actions\SetCustomerWhatsAppConsentAction;
use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCustomerRequest;
use App\Http\Requests\Admin\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\WhatsAppMessage;
use App\Models\User;
use App\Queries\Admin\CustomerAcquirerQuery;
use App\Queries\Admin\CustomerHistoryQuery;
use App\Services\Core\CompanyService;
use App\Services\Core\IndonesiaRegionService;
use App\Services\Core\NumberSequenceService;
use App\Services\Core\OpenStreetMapGeocoder;
use App\Support\ExportQuery;
use App\Support\PublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\NetworkAsset\Queries\OdnOccupancyQuery;
use Modules\Service\Services\ServicePackageOptions;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['code', 'name', 'type', 'phone', 'is_active', 'customer_status', 'created_at'];

    /** Export labels; the stored values stay as they are (`lib/statuses/customer.ts` carries the same words for the pages). */
    private const TYPE_LABELS = ['Individual' => 'Perorangan', 'Company' => 'Perusahaan'];

    private const STATUS_LABELS = ['prospective' => 'Prospek', 'active' => 'Aktif', 'inactive' => 'Nonaktif', 'suspended' => 'Ditangguhkan', 'terminated' => 'Berhenti'];

    public function __construct(private readonly OpenStreetMapGeocoder $geocoder) {}

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $canViewAcquirer = $this->canViewAcquirer($request);
        $customers = $this->filteredQuery($request)
            ->paginate($this->perPage($request))
            ->withQueryString();

        return Inertia::render('Admin/Customers/Index', [
            'customers' => CustomerResource::collection($customers),
            'filters' => $request->only(['type', 'status', 'account', 'search', 'sort', 'direction', 'per_page', ...($canViewAcquirer ? ['acquired_by'] : [])]),
            'acquirerOptions' => $canViewAcquirer ? CustomerAcquirerQuery::filterOptions((int) CompanyService::currentId()) : [],
            'can' => [
                'create' => $this->canOnboard($request),
                'export' => $request->user()?->can('customer.export') ?? false,
                'update' => $request->user()?->can('customer.update') ?? false,
                'delete' => $request->user()?->can('customer.delete') ?? false,
                'viewAcquirer' => $canViewAcquirer,
            ],
        ]);
    }

    public function create(Request $request): InertiaResponse
    {
        Gate::authorize('create', Customer::class);
        $this->authorizeOnboarding();

        $canAssignAcquirer = $request->user()?->can('assignAcquirer', Customer::class) ?? false;
        $canAssignDrop = $request->user()?->can('odn.assign') ?? false;

        return Inertia::render('Admin/Customers/Create', [
            'packages' => DB::table('service_packages')
                ->where('company_id', CompanyService::currentId())
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get(['public_id as id', 'code', 'name', 'price_mrc', 'price_otc', 'contract_min_months']),
            'packageGroups' => ServicePackageOptions::grouped($request->string('customer_type')->toString()),
            'locations' => DB::table('locations')
                ->where('company_id', CompanyService::currentId())
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get(['public_id as id', 'code', 'name', 'type', 'lat', 'lng'])
                ->map(fn (object $location): object => (object) [
                    ...(array) $location,
                    'lat' => $location->lat === null ? null : (float) $location->lat,
                    'lng' => $location->lng === null ? null : (float) $location->lng,
                ]),
            'regions' => fn () => IndonesiaRegionService::options(
                $request->input('region_provinces', []),
                $request->input('region_cities', []),
                $request->input('region_districts', []),
            ),
            'geocodeResults' => Inertia::optional(
                fn () => $this->geocoder->search((string) $request->input('geocode_query')),
            ),
            'geocodeIndex' => Inertia::optional(fn () => $request->integer('geocode_index')),
            'acquirerOptions' => $canAssignAcquirer ? CustomerAcquirerQuery::options((int) CompanyService::currentId()) : [],
            'acquirerDefaultId' => $canAssignAcquirer && CustomerAcquirerQuery::defaultId($request->user()) !== null ? $request->user()->public_id : null,
            // Free ODP ports under the chosen serving POP; evaluated only on a partial reload that asks for it (query serving_pop_id).
            ...($canAssignDrop ? ['dropPortOptions' => OdnOccupancyQuery::dropPortOptions($request, PublicId::resolveOne(Location::class, $request->query('serving_pop_id')))] : []),
            'can' => ['assignAcquirer' => $canAssignAcquirer, 'assignDrop' => $canAssignDrop],
            'codeSequence' => self::codeSequence(),
        ]);
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        Gate::authorize('store', Customer::class);
        $this->authorizeOnboarding();

        $customer = CreateCustomerOnboardingAction::execute($request->validated(), $request->user());

        return redirect()->route('admin.customers.show', $customer)
            ->with('success', 'Pelanggan, langganan, dan SPK pemasangan dibuat. Link akses dikirim ke '.$customer->email.'.');
    }

    public function show(Request $request, int|string $customer): InertiaResponse
    {
        $customer = $this->findForCompany($request, $customer);
        Gate::authorize('view', $customer);

        $customer->load(['addresses.province', 'addresses.regionCity', 'addresses.district', 'addresses.village', 'contacts', 'subscriptions.servicePackage']);
        $canViewAcquirer = $this->canViewAcquirer($request);
        if ($canViewAcquirer) {
            $customer->load('acquirer');
        }
        $historyAccess = [
            'billing' => $request->user()?->can('billing.view') ?? false,
            'tickets' => $request->user()?->can('ticket.view') ?? false,
            'spk' => $request->user()?->can('spk.view') ?? false,
        ];

        return Inertia::render('Admin/Customers/Show', [
            'customer' => new CustomerResource($customer),
            'history' => CustomerHistoryQuery::execute($customer, $historyAccess),
            'historyAccess' => $historyAccess,
            'whatsappMessages' => $this->whatsAppMessages($customer),
            'can' => [
                'viewAcquirer' => $canViewAcquirer,
                'update' => $request->user()?->can('update', $customer) ?? false,
            ],
        ]);
    }

    public function edit(Request $request, int|string $customer): InertiaResponse
    {
        $customer = $this->findForCompany($request, $customer);
        Gate::authorize('edit', $customer);
        $customer->load(['addresses.province', 'addresses.regionCity', 'addresses.district', 'addresses.village', 'contacts', 'subscriptions.servicePackage']);
        $canViewAcquirer = $this->canViewAcquirer($request);
        if ($canViewAcquirer) {
            $customer->load('acquirer');
        }

        $canManageAddress = $request->user()?->can('customer.address.manage') ?? false;

        $savedProvinceCodes = $customer->addresses->pluck('province_code')->all();
        $savedCityCodes = $customer->addresses->pluck('city_code')->all();
        $savedDistrictCodes = $customer->addresses->pluck('district_code')->all();

        $provinceCodes = array_merge($savedProvinceCodes, $request->array('region_provinces'));
        $cityCodes = array_merge($savedCityCodes, $request->array('region_cities'));
        $districtCodes = array_merge($savedDistrictCodes, $request->array('region_districts'));

        return Inertia::render('Admin/Customers/Edit', [
            'customer' => new CustomerResource($customer),
            'regions' => fn () => IndonesiaRegionService::options($provinceCodes, $cityCodes, $districtCodes),
            'geocodeResults' => Inertia::optional(
                fn () => $canManageAddress
                    ? $this->geocoder->search((string) $request->input('geocode_query'))
                    : [],
            ),
            'geocodeIndex' => Inertia::optional(fn () => $request->integer('geocode_index')),
            'can' => [
                'update' => $request->user()?->can('update', $customer) ?? false,
                'address' => $canManageAddress,
                'contact' => $canManageAddress,
                'viewAcquirer' => $canViewAcquirer,
                'assignAcquirer' => $request->user()?->can('assignAcquirer', Customer::class) ?? false,
                'correctAcquirer' => $request->user()?->can('correctAcquirer', Customer::class) ?? false,
            ],
            'acquirerOptions' => $canViewAcquirer ? CustomerAcquirerQuery::options((int) CompanyService::currentId()) : [],
            'codeSequence' => self::codeSequence(),
        ]);
    }

    public function update(UpdateCustomerRequest $request, int|string $customer): RedirectResponse
    {
        $customer = $this->findForCompany($request, $customer);
        Gate::authorize('update', $customer);

        DB::transaction(function () use ($request, $customer): void {
            $customer->update($request->safe()->only([
                'code',
                'name',
                'type',
                'email',
                'phone',
                'tax_id',
                'contact_person',
                'area_coverage_id',
                'notes',
                'is_active',
            ]));

            AssignCustomerAcquirerAction::onUpdate(
                $customer,
                $request->user(),
                $request->safe()->only(['acquired_by_user_id', 'acquirer_correction_reason']),
            );

            if ($request->safe()->has('whatsapp_opt_in')) {
                SetCustomerWhatsAppConsentAction::execute($customer, $request->boolean('whatsapp_opt_in'), $request->user());
            }
        });

        return redirect()->route('admin.customers.index')
            ->with('success', 'Pelanggan diperbarui.');
    }

    /**
     * The customer's latest WhatsApp rows for the customer page: status and reason, never more of the number
     * than its last four digits. Scoped to the current company by the model.
     *
     * @return list<array<string, mixed>>
     */
    private function whatsAppMessages(Customer $customer): array
    {
        $messages = WhatsAppMessage::query()
            ->where('recipient_type', 'customer')
            ->where('recipient_id', $customer->id)
            ->latest()
            ->latest('id')
            ->limit(20)
            ->get(['id', 'public_id', 'event', 'subject_type', 'subject_id', 'status', 'skip_reason', 'error_code', 'recipient_last4', 'created_at', 'delivered_at', 'read_at', 'failed_at']);
        $subjects = PublicId::ofPairs(WhatsAppMessage::subjectModels(), $messages->map(fn (WhatsAppMessage $message): array => [$message->subject_type, $message->subject_id]));

        return $messages->map(fn (WhatsAppMessage $message): array => [
            'id' => $message->public_id,
            ...$message->only(['event', 'subject_type', 'status', 'skip_reason', 'error_code', 'recipient_last4', 'created_at', 'delivered_at', 'read_at', 'failed_at']),
            'subject_id' => $subjects[$message->subject_type.':'.$message->subject_id] ?? null,
        ])->all();
    }

    public function destroy(Request $request, int|string $customer): RedirectResponse
    {
        $customer = $this->findForCompany($request, $customer);
        Gate::authorize('delete', $customer);

        if ($customer->subscriptions()->whereNotIn('status', ['terminated'])->exists()) {
            return back()->withErrors(['customer' => 'Pelanggan yang masih punya langganan belum berhenti tidak bisa dihapus.']);
        }

        $customer->delete();

        return redirect()->route('admin.customers.index')
            ->with('success', 'Pelanggan dihapus.');
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
            'code' => 'Kode',
            'name' => 'Nama',
            'type' => 'Tipe',
            'email' => 'Email',
            'phone' => 'Telepon',
            'is_active' => 'Akun',
            'customer_status' => 'Status',
        ];
        $acquirers = collect();

        if ($this->canViewAcquirer($request)) {
            $columns['acquired_by'] = 'Diperoleh oleh';
            $acquirers = CustomerAcquirerQuery::credited((int) CompanyService::currentId());
        }

        $map = static fn (Customer $customer): array => [
            'code' => $customer->code,
            'name' => $customer->name,
            'type' => self::TYPE_LABELS[$customer->type] ?? $customer->type,
            'email' => $customer->email ?? '',
            'phone' => $customer->phone ?? '',
            'is_active' => $customer->is_active ? 'Aktif' : 'Nonaktif',
            'customer_status' => self::STATUS_LABELS[$customer->customer_status] ?? $customer->customer_status,
            'acquired_by' => self::acquirerLabel($customer->acquired_by_user_id === null ? null : $acquirers->get($customer->acquired_by_user_id)),
        ];

        return $format === 'pdf'
            ? $export->streamPdf('Pelanggan', $columns, $map, "customers-export-{$stamp}.pdf")
            : $export->streamCsv($columns, $map, "customers-export-{$stamp}.csv");
    }

    /**
     * @return Builder<Customer>
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = Customer::query()
            ->withCount(['addresses', 'subscriptions'])
            ->when($request->input('type'), fn (Builder $query, mixed $value) => is_string($value) ? $query->where('type', $value) : $query)
            ->when($request->input('status'), function (Builder $query, mixed $value): void {
                if (! is_string($value)) {
                    return;
                }

                $normalized = strtolower(trim($value));

                if (in_array($normalized, Customer::STATUSES, true)) {
                    $query->where('customer_status', $normalized);
                }
            })
            ->when($request->input('account'), function (Builder $query, mixed $value): void {
                if (! is_string($value)) {
                    return;
                }

                $normalized = strtolower(trim($value));

                if ($normalized === 'active') {
                    $query->where('is_active', true);
                } elseif ($normalized === 'inactive') {
                    $query->where('is_active', false);
                }
            })
            ->when($request->input('search'), function (Builder $query, mixed $value): void {
                $term = is_string($value) ? trim($value) : '';

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

        if ($this->canViewAcquirer($request)) {
            $this->filterByAcquirer($query->with('acquirer'), $request->input('acquired_by'));
        }

        return $this->applySort($query, $request, 'code');
    }

    private function findForCompany(Request $request, int|string $customer): Customer
    {
        return Customer::forCompany(CompanyService::currentId())
            ->wherePublicId($customer)
            ->firstOrFail();
    }

    private function canOnboard(Request $request): bool
    {
        $user = $request->user();

        return $user?->can('customer.create') === true
            && $user->can('customer.address.manage')
            && $user->can('customer.subscription.manage')
            && $user->can('spk.create');
    }

    private function canViewAcquirer(Request $request): bool
    {
        return $request->user()?->can('viewAcquirer', Customer::class) ?? false;
    }

    /**
     * @param  Builder<Customer>  $query
     */
    private function filterByAcquirer(Builder $query, mixed $acquiredBy): void
    {
        if ($acquiredBy === 'unassigned') {
            $query->whereNull('acquired_by_user_id');
        } elseif (is_string($acquiredBy) && ($acquirerId = PublicId::resolveOne(User::class, $acquiredBy)) !== null) {
            $query->where('acquired_by_user_id', $acquirerId);
        }
    }

    private static function acquirerLabel(?User $acquirer): string
    {
        return $acquirer === null ? 'Belum ditentukan' : $acquirer->name.($acquirer->is_active ? '' : ' (nonaktif)');
    }

    private function authorizeOnboarding(): void
    {
        Gate::authorize('customer.address.manage');
        Gate::authorize('customer.subscription.manage');
        Gate::authorize('spk.create');
    }

    /** @return array{manual: bool, preview: string} */
    private static function codeSequence(): array
    {
        return [
            'manual' => NumberSequenceService::allowsManual('customer'),
            'preview' => NumberSequenceService::peek('customer'),
        ];
    }
}
