<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Core\Customer;
use App\Models\Core\ServiceSubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Billing\Http\Requests\StoreInvoiceRequest;
use Modules\Billing\Http\Requests\UpdateInvoiceRequest;
use Modules\Billing\Http\Requests\RecordPaymentRequest;
use Modules\Billing\Http\Resources\InvoiceResource;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceItem;
use Modules\Billing\Services\BillingService;

class InvoiceController extends Controller
{
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
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Admin/Billing/Invoices/Index', [
            'invoices' => InvoiceResource::collection($invoices),
            'customers' => \App\Http\Resources\CustomerResource::collection(Customer::query()->where('is_active', true)->orderBy('name')->get()),
            'filters' => $request->only(['type', 'status', 'source', 'customer_id', 'search']),
            'can' => ['create' => $request->user()?->can('billing.create') ?? false],
        ]);
    }

    public function create(): InertiaResponse
    {
        Gate::authorize('create', Invoice::class);

        return Inertia::render('Admin/Billing/Invoices/Create', [
            'customers' => \App\Http\Resources\CustomerResource::collection(Customer::query()->where('is_active', true)->orderBy('name')->get()),
            'subscriptions' => \App\Http\Resources\SubscriptionResource::collection(ServiceSubscription::query()->whereIn('status', ['pending', 'active'])->orderBy('code')->get()),
        ]);
    }

    public function store(StoreInvoiceRequest $request): RedirectResponse
    {
        Gate::authorize('store', Invoice::class);

        $data = $request->validated();
        $data['number'] = BillingService::generateNumber();
        $data['type'] = 'one_time';
        $data['source'] = 'manual';
        $data['status'] = 'draft';
        $data['issue_date'] = $data['issue_date'] ?? now()->toDateString();
        $data['due_date'] = $data['due_date'] ?? now()->addDays(14)->toDateString();
        $data['created_by'] = $request->user()->id;

        $invoice = Invoice::create($data);

        return redirect()->route('admin.invoices.show', $invoice)
            ->with('success', 'Invoice created.');
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

        return back()->with('success', 'Invoice updated.');
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

    public function createFromSpk(Request $request): RedirectResponse
    {
        Gate::authorize('billing.create');

        $request->validate(['work_order_id' => ['required', 'exists:work_orders,id']]);
        $invoice = BillingService::createFromSpk($request->integer('work_order_id'));

        return redirect()->route('admin.invoices.show', $invoice)
            ->with('success', 'Invoice created from SPK.');
    }

    public function addItem(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->ensureSameCompany($invoice);
        Gate::authorize('billing.update');
        abort_if($invoice->status !== 'draft', 422, 'Can only add items to draft invoices.');

        $request->validate([
            'product_id' => ['nullable', 'exists:products,id'],
            'description' => ['required', 'string', 'max:500'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $lineTotal = $request->float('quantity') * $request->float('unit_price') - $request->float('discount_amount', 0);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $request->input('product_id'),
            'description' => $request->input('description'),
            'quantity' => $request->float('quantity'),
            'unit_price' => $request->float('unit_price'),
            'discount_amount' => $request->float('discount_amount', 0),
            'tax_rate' => $request->float('tax_rate', 0),
            'line_total' => $lineTotal,
        ]);

        BillingService::recalculate($invoice);

        return back()->with('success', 'Item added.');
    }

    public function removeItem(Invoice $invoice, InvoiceItem $item): RedirectResponse
    {
        $this->ensureSameCompany($invoice);
        abort_unless($item->invoice_id === $invoice->id, 404);
        Gate::authorize('billing.update');
        abort_if($invoice->status !== 'draft', 422, 'Can only remove items from draft invoices.');

        $item->delete();
        BillingService::recalculate($invoice);

        return back()->with('success', 'Item removed.');
    }

    private function ensureSameCompany(Invoice $invoice): void
    {
        abort_unless($invoice->company_id === \App\Services\Core\CompanyService::currentId(), 404);
    }
}
