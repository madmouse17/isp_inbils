<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\DateRangeIndexRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Core\Customer;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use App\Services\Core\NumberSequenceService;
use App\Support\ExportQuery;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Billing\Http\Requests\CreateFromSpkRequest;
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
use Modules\Billing\Queries\ReceivablesQuery;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Services\MidtransGateway;
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

        return Inertia::render('Admin/Billing/Invoices/Index', [
            'invoices' => InvoiceResource::collection($invoices),
            'customers' => CustomerResource::collection(Customer::query()->where('is_active', true)->orderBy('name')->get()),
            'filters' => $request->only(['type', 'status', 'source', 'customer_id', 'search', 'date_from', 'date_to', 'sort_by', 'sort_dir', 'per_page']),
            'statusOptions' => ['draft', 'issued', 'partial', 'paid', 'void', 'written_off'],
            'can' => [
                'create' => $request->user()?->can('billing.create') ?? false,
                'export' => $request->user()?->can('billing.export') ?? false,
            ],
        ]);
    }

    /** @return Builder<Invoice> */
    private function filteredQuery(Request $request): Builder
    {
        return Invoice::query()
            ->when($request->input('type'), fn ($query, $value) => $query->where('type', $value))
            ->when($request->input('status'), fn ($query, $value) => $query->where('status', $value))
            ->when($request->input('source'), fn ($query, $value) => $query->where('source', $value))
            ->when($request->input('customer_id'), fn ($query, $value) => $query->where('customer_id', $value))
            ->when($request->input('date_from'), fn ($query, $value) => $query->whereDate('issue_date', '>=', $value))
            ->when($request->input('date_to'), fn ($query, $value) => $query->whereDate('issue_date', '<=', $value))
            ->when($request->input('search'), fn ($query, $value) => $query->where(fn ($search) => $search
                ->where('number', 'like', "%{$value}%")
                ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$value}%"))));
    }

    public function create(): InertiaResponse
    {
        Gate::authorize('create', Invoice::class);

        return Inertia::render('Admin/Billing/Invoices/Create', [
            'customers' => CustomerResource::collection(Customer::query()->where('is_active', true)->orderBy('name')->get()),
            'subscriptions' => SubscriptionResource::collection(ServiceSubscription::query()->whereIn('status', ['pending', 'active'])->orderBy('code')->get()),
        ]);
    }

    public function generatePreview(Request $request): JsonResponse
    {
        Gate::authorize('billing.create');
        $request->validate(['period' => ['required', 'date_format:Y-m']]);

        return response()->json(BillingService::generateForPeriod($request->input('period'), dryRun: true));
    }

    public function generate(Request $request): RedirectResponse
    {
        Gate::authorize('billing.create');
        $request->validate(['period' => ['required', 'date_format:Y-m']]);

        $result = BillingService::generateForPeriod($request->input('period'));

        return back()->with('success', "Tagihan {$request->input('period')}: {$result['created']} dibuat, {$result['skipped']} dilewati.");
    }

    public function store(StoreInvoiceRequest $request): RedirectResponse
    {
        Gate::authorize('store', Invoice::class);

        $data = $request->validated();
        $data['number'] = NumberSequenceService::generate('invoice', CompanyService::currentId());
        $data['type'] = 'one_time';
        $data['source'] = 'manual';
        $data['status'] = 'draft';
        $data['issue_date'] = $data['issue_date'] ?? now()->toDateString();
        $data['due_date'] = $data['due_date'] ?? now()->addDays(14)->toDateString();
        $data['created_by'] = $request->user()->id;

        Invoice::create($data);

        return redirect()->route('admin.invoices.index')
            ->with('success', 'Invoice created.');
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
            'Number' => 'number',
            'Customer' => 'customer.name',
            'Status' => 'status',
            'Total' => 'total',
            'Issue Date' => 'issue_date',
            'Due Date' => 'due_date',
            'Created' => 'created_at',
        ];

        $map = static function (Invoice $invoice): array {
            return [
                'Number' => $invoice->number,
                'Customer' => $invoice->customer?->name,
                'Status' => $invoice->status,
                'Total' => $invoice->total,
                'Issue Date' => optional($invoice->issue_date)->format('Y-m-d') ?? (string) $invoice->issue_date,
                'Due Date' => optional($invoice->due_date)->format('Y-m-d') ?? (string) $invoice->due_date,
                'Created' => optional($invoice->created_at)?->toDateTimeString(),
            ];
        };

        $stamp = now()->format('Ymd-His');
        $format = strtolower((string) $request->input('format', 'csv'));

        return $format === 'pdf'
            ? $export->downloadPdf('Invoices', $columns, $map, "invoices-export-{$stamp}.pdf")
            : $export->streamCsv($columns, $map, "invoices-export-{$stamp}.csv");
    }

    public function show(Invoice $invoice, MidtransGateway $gateway): InertiaResponse
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
        ]);
    }

    public function pdf(Invoice $invoice)
    {
        $this->ensureSameCompany($invoice);
        Gate::authorize('view', $invoice);

        $invoice->load(['customer', 'subscription.servicePackage', 'items']);
        $company = CompanyService::current();

        return Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
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
            'can' => [
                'suspend' => $request->user()?->can('customer.subscription.suspend') ?? false,
                'block' => $request->user()?->can('customer.subscription.block') ?? false,
            ],
        ]);
    }

    public function edit(Invoice $invoice): InertiaResponse
    {
        $this->ensureSameCompany($invoice);
        Gate::authorize('edit', $invoice);
        abort_if($invoice->status !== 'draft', 422, 'Can only edit draft invoices.');

        $invoice->load(['customer', 'subscription', 'items']);

        return Inertia::render('Admin/Billing/Invoices/Edit', [
            'invoice' => new InvoiceResource($invoice),
        ]);
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice): RedirectResponse
    {
        $this->ensureSameCompany($invoice);
        Gate::authorize('update', $invoice);
        abort_if($invoice->status !== 'draft', 422, 'Can only edit draft invoices.');

        $invoice->update($request->validated());
        BillingService::recalculate($invoice);

        return redirect()->route('admin.invoices.index')->with('success', 'Invoice updated.');
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        $this->ensureSameCompany($invoice);
        Gate::authorize('delete', $invoice);
        abort_if($invoice->status !== 'draft', 422, 'Can only delete draft invoices.');

        $invoice->delete();

        return back()->with('success', 'Invoice deleted.');
    }

    public function send(Invoice $invoice): RedirectResponse
    {
        $this->ensureSameCompany($invoice);
        Gate::authorize('billing.send');
        BillingService::send($invoice);

        return back()->with('success', 'Invoice sent.');
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
        );

        return back()->with('success', 'Payment recorded.');
    }

    public function reversePayment(ReversePaymentRequest $request, Invoice $invoice, Payment $payment): RedirectResponse
    {
        $this->ensureSameCompany($invoice);
        abort_unless($payment->invoice_id === $invoice->id, 404);

        BillingService::reversePayment($payment, $request->input('reason'));

        return back()->with('success', 'Payment reversed.');
    }

    public function cancel(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->ensureSameCompany($invoice);
        Gate::authorize('billing.cancel');

        $request->validate(['reason' => ['required', 'string', 'max:500']]);
        BillingService::cancel($invoice, $request->input('reason'));

        return back()->with('success', 'Invoice cancelled.');
    }

    public function createFromSpk(CreateFromSpkRequest $request): RedirectResponse
    {
        Gate::authorize('billing.create');

        BillingService::createFromSpk($request->integer('work_order_id'));

        return redirect()->route('admin.invoices.index')
            ->with('success', 'Invoice created from SPK.');
    }

    public function addItem(StoreInvoiceItemRequest $request, Invoice $invoice): RedirectResponse
    {
        $this->ensureSameCompany($invoice);
        Gate::authorize('billing.update');
        abort_if($invoice->status !== 'draft', 422, 'Can only add items to draft invoices.');

        BillingService::addItem($invoice, $request->validated());

        return back()->with('success', 'Item added.');
    }

    public function removeItem(Invoice $invoice, InvoiceItem $item): RedirectResponse
    {
        $this->ensureSameCompany($invoice);
        Gate::authorize('billing.update');
        BillingService::removeItem($invoice, $item->id);

        return back()->with('success', 'Item removed.');
    }

    private function ensureSameCompany(Invoice $invoice): void
    {
        abort_unless($invoice->company_id === CompanyService::currentId(), 404);
    }
}
