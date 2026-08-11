<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Core\Customer;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use App\Services\Core\NumberSequenceService;
use App\Support\ExportQuery;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Billing\Http\Requests\CreateFromSpkRequest;
use Modules\Billing\Http\Requests\RecordPaymentRequest;
use Modules\Billing\Http\Requests\StoreInvoiceItemRequest;
use Modules\Billing\Http\Requests\StoreInvoiceRequest;
use Modules\Billing\Http\Requests\UpdateInvoiceRequest;
use Modules\Billing\Http\Resources\InvoiceResource;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceItem;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Support\Terbilang;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    private const SORTABLE = ['number', 'status', 'issue_date', 'due_date', 'total', 'created_at'];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', Invoice::class);

        $invoices = Invoice::query()
            ->with(['customer', 'subscription', 'items'])
            ->when($request->input('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('source'), fn ($q, $v) => $q->where('source', $v))
            ->when($request->input('customer_id'), fn ($q, $v) => $q->where('customer_id', $v))
            ->when($request->input('search'), fn ($q, $v) => $q->where(fn ($sq) => $sq
                ->where('number', 'like', "%{$v}%")))
            ->latest('issue_date')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Admin/Billing/Invoices/Index', [
            'invoices' => InvoiceResource::collection($invoices),
            'customers' => CustomerResource::collection(Customer::query()->where('is_active', true)->orderBy('name')->get()),
            'filters' => $request->only(['type', 'status', 'source', 'customer_id', 'search']),
            'statusOptions' => ['draft', 'issued', 'partial', 'paid', 'void', 'written_off'],
            'can' => [
                'create' => $request->user()?->can('billing.create') ?? false,
                'export' => $request->user()?->can('billing.export') ?? false,
            ],
        ]);
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
        $data['number'] = NumberSequenceService::generate('invoice', 'INV', $request->user()->company_id);
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

        $query = Invoice::query()->with('customer:id,code,name');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $export = ExportQuery::make($query)
            ->for(
                $request,
                self::SORTABLE,
                ['number', 'status', 'notes'],
                'created_at',
                'desc'
            )
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

    public function show(Invoice $invoice): InertiaResponse
    {
        $this->ensureSameCompany($invoice);
        Gate::authorize('view', $invoice);

        $invoice->load(['customer', 'subscription', 'items', 'payments']);

        return Inertia::render('Admin/Billing/Invoices/Show', [
            'invoice' => new InvoiceResource($invoice),
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

    public function receivables(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', Invoice::class);

        return Inertia::render('Admin/Billing/Receivables', [
            'rows' => BillingService::receivables(),
            'can' => ['suspend' => $request->user()?->can('customer.subscription.suspend') ?? false],
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
        // ponytail: status/ownership checks deferred to BillingService::removeItem
        // which re-fetches both rows with lockForUpdate inside a transaction.
        BillingService::removeItem($invoice, $item->id);

        return back()->with('success', 'Item removed.');
    }

    private function ensureSameCompany(Invoice $invoice): void
    {
        abort_unless($invoice->company_id === CompanyService::currentId(), 404);
    }
}
