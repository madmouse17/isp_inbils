<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\DateRangeIndexRequest;
use App\Models\Core\Customer;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use App\Services\Core\NumberSequenceService;
use App\Support\ExportQuery;
use App\Support\OptimisticLock;
use App\Support\PublicId;
use App\Support\RecordNotFound;
use App\Support\SearchOptions;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Billing\Http\Requests\CancelInvoiceRequest;
use Modules\Billing\Http\Requests\CreateFromSpkRequest;
use Modules\Billing\Http\Requests\GenerateInvoicesRequest;
use Modules\Billing\Http\Requests\RecordPaymentRequest;
use Modules\Billing\Http\Requests\ReversePaymentRequest;
use Modules\Billing\Http\Requests\StoreInvoiceItemRequest;
use Modules\Billing\Http\Requests\StoreInvoiceRequest;
use Modules\Billing\Http\Requests\UpdateInvoiceRequest;
use Modules\Billing\Http\Resources\InvoiceResource;
use Modules\Billing\Http\Resources\PaymentAttemptResource;
use Modules\Billing\Http\Resources\ReceivableResource;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceItem;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentAttempt;
use Modules\Billing\Queries\ReceivablesQuery;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Services\MidtransGateway;
use Modules\Billing\Services\PaymentAttemptService;
use Modules\Billing\Support\InvoiceParty;
use Modules\Billing\Support\Terbilang;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['number', 'status', 'issue_date', 'due_date', 'total', 'created_at'];

    public function index(DateRangeIndexRequest $request): InertiaResponse
    {
        Gate::authorize('viewAny', Invoice::class);

        $invoices = $this->applySort(
            $this->filteredQuery($request)->with(['customer', 'subscription', 'items']),
            $request,
            'issue_date',
            'desc'
        )
            ->paginate($this->perPage($request))
            ->withQueryString();

        $customerKey = $request->query('customer_id');
        $customerKey = is_string($customerKey) && $customerKey !== '' ? $customerKey : null;
        $selectedCustomer = $customerKey === null ? null : Customer::query()->wherePublicId($customerKey)->first();

        return Inertia::render('Admin/Billing/Invoices/Index', [
            'invoices' => InvoiceResource::collection($invoices),
            'customerOptions' => SearchOptions::for(
                $request,
                'customer',
                Customer::query(),
                ['name', 'code'],
                $this->presentCustomer(...),
                $customerKey,
            ),
            'selectedCustomer' => $selectedCustomer ? $this->presentCustomer($selectedCustomer) : null,
            'filters' => [
                ...$request->only(['type', 'status', 'source', 'customer_id', 'search', 'date_from', 'date_to', 'sort_by', 'sort_dir', 'per_page']),
                // Normalised, so the page shows its chip exactly when the list is filtered.
                'online_pending' => $request->boolean('online_pending') ? '1' : null,
            ],
            'can' => [
                'create' => $request->user()?->can('billing.create') ?? false,
                'export' => $request->user()?->can('billing.export') ?? false,
                'update' => $request->user()?->can('billing.update') ?? false,
                'delete' => $request->user()?->can('billing.delete') ?? false,
            ],
        ]);
    }

    /** @return Builder<Invoice> */
    private function filteredQuery(Request $request): Builder
    {
        return Invoice::query()
            ->when($request->input('type'), fn ($query, $value) => $query->where('type', $value))
            ->lifecycleStatus($request->input('status'))
            ->when($request->input('source'), fn ($query, $value) => $query->where('source', $value))
            ->when($request->input('customer_id'), fn ($query, $value) => $query->where('customer_id', PublicId::resolveOne(Customer::class, $value) ?? 0))
            ->when($request->input('date_from'), fn ($query, $value) => $query->whereDate('issue_date', '>=', $value))
            ->when($request->input('date_to'), fn ($query, $value) => $query->whereDate('issue_date', '<=', $value))
            ->when($request->input('search'), fn ($query, $value) => $query->where(fn ($search) => $search
                ->where('number', 'like', "%{$value}%")
                ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$value}%"))))
            ->when($request->boolean('online_pending'), fn (Builder $query) => $query->whereHas('paymentAttempts', fn (Builder $attempts) => $attempts->stalePending()));
    }

    public function create(Request $request): InertiaResponse
    {
        Gate::authorize('create', Invoice::class);

        // The page sends the customer it picked, so only that customer's subscriptions are offered.
        $customerId = PublicId::resolveOne(Customer::class, $request->query('customer_id'));

        return Inertia::render('Admin/Billing/Invoices/Create', [
            'customerOptions' => SearchOptions::for(
                $request,
                'customer',
                Customer::query()->where('is_active', true),
                ['name', 'code'],
                $this->presentCustomer(...),
            ),
            'subscriptionOptions' => SearchOptions::for(
                $request,
                'subscription',
                // Joined so the search also finds a subscription by its customer's name; every column is qualified because both tables have `status`, `code` and `company_id`.
                ServiceSubscription::query()
                    ->join('customers', 'customers.id', '=', 'service_subscriptions.customer_id')
                    ->whereNull('customers.deleted_at')
                    ->whereIn('service_subscriptions.status', ['pending', 'active'])
                    ->when($request->filled('customer_id'), fn (Builder $query) => $query->where('service_subscriptions.customer_id', $customerId ?? 0))
                    ->select('service_subscriptions.*', 'customers.name as customer_name'),
                ['service_subscriptions.code', 'customers.name'],
                fn (ServiceSubscription $subscription): array => [
                    'value' => $subscription->public_id,
                    'label' => $subscription->code,
                    'description' => $subscription->customer_name,
                ],
            ),
            // Same default the store applies when due_date is empty.
            'due_days' => BillingService::dueDaysFor((int) CompanyService::currentId()),
        ]);
    }

    /** @return array{value: string, label: string, description: string|null} */
    private function presentCustomer(Customer $customer): array
    {
        return ['value' => $customer->public_id, 'label' => $customer->name, 'description' => $customer->code];
    }

    public function generatePreview(GenerateInvoicesRequest $request): JsonResponse
    {
        Gate::authorize('billing.create');

        $period = $request->input('period');

        return response()->json([
            ...BillingService::generateForPeriod($period, dryRun: true),
            'is_current_period' => $period === BillingService::currentPeriodFor((int) CompanyService::currentId()),
        ]);
    }

    public function generate(GenerateInvoicesRequest $request): RedirectResponse
    {
        Gate::authorize('billing.create');

        $result = BillingService::generateForPeriod($request->input('period'));
        $failed = $result['failed'] > 0 ? ", {$result['failed']} gagal" : '';

        return back()->with('success', "Tagihan {$request->input('period')}: {$result['created']} dibuat, {$result['skipped']} dilewati{$failed}.");
    }

    public function store(StoreInvoiceRequest $request): RedirectResponse
    {
        Gate::authorize('store', Invoice::class);

        $data = $request->validated();
        $data['type'] = 'one_time';
        $data['source'] = 'manual';
        $data['status'] = 'draft';
        $data['issue_date'] = $data['issue_date'] ?? now()->toDateString();
        $data['due_date'] = $data['due_date'] ?? now()->addDays(BillingService::dueDaysFor(CompanyService::currentId()))->toDateString();
        $data['created_by'] = $request->user()->id;

        // The number is taken inside the transaction that inserts the invoice: an insert that fails gives it back, so numbers have no gaps.
        DB::transaction(fn () => Invoice::create($data + ['number' => NumberSequenceService::generate('invoice', CompanyService::currentId())]));

        return redirect()->route('admin.invoices.index')
            ->with('success', 'Tagihan dibuat.');
    }

    public function export(Request $request): Response|StreamedResponse
    {
        Gate::authorize('viewAny', Invoice::class);

        abort_unless($request->user()?->can('billing.export') ?? false, 403);

        $query = $this->filteredQuery($request)->with('customer:id,code,name');

        $export = ExportQuery::make($query)
            ->forRequest($request, self::SORTABLE, 'created_at', 'desc')
            ->maxRows(ExportQuery::resolveMaxRows(config('exports.max_rows', ExportQuery::DEFAULT_MAX_ROWS)));

        $columns = [
            'Nomor tagihan' => 'number',
            'Pelanggan' => 'customer.name',
            'Status' => 'status',
            'Total' => 'total',
            'Tanggal terbit' => 'issue_date',
            'Jatuh tempo' => 'due_date',
            'Dibuat' => 'created_at',
        ];

        $map = static function (Invoice $invoice): array {
            return [
                'Nomor tagihan' => $invoice->number,
                'Pelanggan' => $invoice->customer?->name,
                'Status' => $invoice->status,
                'Total' => $invoice->total,
                'Tanggal terbit' => optional($invoice->issue_date)->format('Y-m-d') ?? (string) $invoice->issue_date,
                'Jatuh tempo' => optional($invoice->due_date)->format('Y-m-d') ?? (string) $invoice->due_date,
                'Dibuat' => optional($invoice->created_at)?->toDateTimeString(),
            ];
        };

        $stamp = now()->format('Ymd-His');
        $format = strtolower((string) $request->input('format', 'csv'));

        return $format === 'pdf'
            ? $export->downloadPdf('Tagihan', $columns, $map, "invoices-export-{$stamp}.pdf")
            : $export->streamCsv($columns, $map, "invoices-export-{$stamp}.csv");
    }

    public function show(Request $request, Invoice $invoice, MidtransGateway $gateway): InertiaResponse
    {
        $this->ensureSameCompany($invoice);
        Gate::authorize('view', $invoice);

        $invoice->load(['customer', 'subscription', 'items', 'payments']);

        return Inertia::render('Admin/Billing/Invoices/Show', [
            'invoice' => new InvoiceResource($invoice),
            'onlinePayments' => [
                'enabled' => $gateway->isEnabled(),
                'attempts' => PaymentAttemptResource::collection($invoice->paymentAttempts()->latest('id')->limit(20)->get()),
            ],
            'can' => [
                'update' => $request->user()?->can('update', $invoice) ?? false,
                'send' => $request->user()?->can('billing.send') ?? false,
                'cancel' => $request->user()?->can('billing.cancel') ?? false,
                'record_payment' => $request->user()?->can('billing.payment.record') ?? false,
                'reverse_payment' => $request->user()?->can('billing.payment.reverse') ?? false,
                'refund' => $request->user()?->can('billing.payment.reverse') ?? false,
            ],
        ]);
    }

    public function pdf(Invoice $invoice)
    {
        $this->ensureSameCompany($invoice);
        Gate::authorize('view', $invoice);

        $invoice->load(['subscription', 'items']);
        $company = CompanyService::current();

        return Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'party' => InvoiceParty::of($invoice),
            'company' => $company,
            'bankInfo' => ($company?->settings ?? [])['bank_account_info'] ?? '',
            'terbilang' => Terbilang::make((float) $invoice->total),
        ])->download($invoice->number.'.pdf');
    }

    public function receivables(Request $request, ReceivablesQuery $query): InertiaResponse
    {
        Gate::authorize('viewAny', Invoice::class);

        $result = $query->run($request);

        return Inertia::render('Admin/Billing/Receivables', [
            'rows' => ReceivableResource::collection($result['rows']),
            'totals' => $result['totals'],
            'filters' => $result['filters'],
            'paymentExceptions' => $this->paymentExceptions(),
            // The default of OverdueSuspensionService::settings(): the isolir dialog tells which way the customer comes back.
            'autoReactivate' => filter_var(CompanyService::current()?->settings['auto_reactivate_on_payment'] ?? true, FILTER_VALIDATE_BOOL),
            'can' => [
                'suspend' => $request->user()?->can('customer.subscription.suspend') ?? false,
                'block' => $request->user()?->can('customer.subscription.block') ?? false,
                'refund' => $request->user()?->can('billing.payment.reverse') ?? false,
            ],
        ]);
    }

    /**
     * The online payments that only a person can settle: money Midtrans took and Inbils could not book, or booked money it
     * took back. The Tunggakan page counts them and lists the newest ten.
     *
     * @return array{count: int, items: list<array<string, mixed>>}
     */
    private function paymentExceptions(): array
    {
        return [
            'count' => PaymentAttemptService::openExceptions()->count(),
            'items' => PaymentAttemptService::openExceptions()
                ->with('invoice:id,public_id,number')
                ->latest('created_at')
                ->limit(10)
                ->get()
                ->map(fn (PaymentAttempt $attempt): array => [
                    'id' => $attempt->public_id,
                    'invoice_id' => $attempt->invoice?->public_id,
                    'invoice_number' => $attempt->invoice?->number,
                    'order_id' => $attempt->order_id,
                    'amount' => $attempt->amount,
                    'reason' => $attempt->status_reason,
                    'updated_at' => $attempt->updated_at->toIso8601String(),
                ])
                ->all(),
        ];
    }

    public function edit(Invoice $invoice): InertiaResponse
    {
        $this->ensureSameCompany($invoice);
        Gate::authorize('edit', $invoice);
        abort_if($invoice->status !== 'draft', 422, 'Hanya tagihan draf yang bisa diubah.');

        $invoice->load(['customer', 'subscription', 'items']);

        return Inertia::render('Admin/Billing/Invoices/Edit', [
            'invoice' => new InvoiceResource($invoice),
        ]);
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice): RedirectResponse
    {
        $this->ensureSameCompany($invoice);
        Gate::authorize('update', $invoice);

        BillingService::update($invoice, $request->safe()->except(OptimisticLock::FIELD), $request->validated(OptimisticLock::FIELD));

        return redirect()->route('admin.invoices.index')->with('success', 'Tagihan diperbarui.');
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        $this->ensureSameCompany($invoice);
        Gate::authorize('delete', $invoice);

        BillingService::destroyDraft($invoice);

        return back()->with('success', 'Tagihan dihapus.');
    }

    public function send(Invoice $invoice): RedirectResponse
    {
        $this->ensureSameCompany($invoice);
        Gate::authorize('billing.send');
        BillingService::send($invoice);

        return back()->with('success', 'Tagihan dikirim.');
    }

    public function recordPayment(RecordPaymentRequest $request, Invoice $invoice): RedirectResponse
    {
        $this->ensureSameCompany($invoice);
        Gate::authorize('billing.payment.record');

        BillingService::recordPayment(
            $invoice,
            $request->float('amount'),
            $request->input('method'),
            $request->input('reference'),
            $request->input('notes'),
            $request->paidAt(),
        );

        return back()->with('success', 'Pembayaran dicatat.');
    }

    public function reversePayment(ReversePaymentRequest $request, Invoice $invoice, Payment $payment): RedirectResponse
    {
        $this->ensureSameCompany($invoice);
        RecordNotFound::abortUnless($payment->invoice_id === $invoice->id);

        BillingService::reversePayment($payment, $request->input('reason'));

        return back()->with('success', 'Pembayaran dibatalkan.');
    }

    public function cancel(CancelInvoiceRequest $request, Invoice $invoice): RedirectResponse
    {
        $this->ensureSameCompany($invoice);
        Gate::authorize('billing.cancel');

        BillingService::cancel($invoice, $request->input('reason'));

        return back()->with('success', 'Tagihan dibatalkan.');
    }

    public function createFromSpk(CreateFromSpkRequest $request): RedirectResponse
    {
        Gate::authorize('billing.create');

        BillingService::createFromSpk($request->integer('work_order_id'));

        return redirect()->route('admin.invoices.index')
            ->with('success', 'Tagihan dibuat dari SPK.');
    }

    public function addItem(StoreInvoiceItemRequest $request, Invoice $invoice): RedirectResponse
    {
        $this->ensureSameCompany($invoice);
        Gate::authorize('update', $invoice);
        abort_if($invoice->status !== 'draft', 422, 'Hanya tagihan draf yang bisa diberi item.');

        BillingService::addItem($invoice, $request->validated());

        return back()->with('success', 'Item ditambahkan.');
    }

    public function removeItem(Invoice $invoice, InvoiceItem $item): RedirectResponse
    {
        $this->ensureSameCompany($invoice);
        Gate::authorize('update', $invoice);
        BillingService::removeItem($invoice, $item->id);

        return back()->with('success', 'Item dihapus.');
    }

    private function ensureSameCompany(Invoice $invoice): void
    {
        RecordNotFound::abortUnless($invoice->company_id === CompanyService::currentId());
    }
}
