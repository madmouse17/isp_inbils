<?php

namespace Modules\Billing\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCustomerAccess;
use App\Models\Core\Customer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Billing\Http\Resources\PortalInvoiceResource;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\MidtransGateway;
use Modules\Billing\Services\PaymentAttemptService;
use Symfony\Component\HttpFoundation\Response;

/**
 * A customer's own invoices. The invoice is always looked up through the customer the middleware
 * resolved, so anyone else's id is a plain 404: no 403 that would confirm it exists.
 */
class InvoiceController extends Controller
{
    public function index(Request $request, MidtransGateway $gateway): InertiaResponse
    {
        $invoices = Invoice::query()
            ->whereBelongsTo($this->customer($request))
            ->whereIn('status', Invoice::CUSTOMER_VISIBLE_STATUSES)
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Portal/Invoices/Index', [
            'invoices' => PortalInvoiceResource::collection($invoices),
            'paymentEnabled' => $gateway->isEnabled(),
        ]);
    }

    public function show(Request $request, int $invoice, MidtransGateway $gateway): InertiaResponse
    {
        $invoice = $this->ownInvoice($request, $invoice)->load([
            'items',
            'payments' => fn ($payments) => $payments->whereNull('cancelled_at')->orderBy('paid_at'),
        ]);
        $blockedReason = PaymentAttemptService::unpayableReason($invoice);

        return Inertia::render('Portal/Invoices/Show', [
            'invoice' => new PortalInvoiceResource($invoice),
            'payment' => [
                'enabled' => $gateway->isEnabled(),
                'can_pay' => $gateway->isEnabled() && $blockedReason === null,
                'blocked_reason' => $blockedReason,
            ],
        ]);
    }

    /** Sends the browser to the hosted payment page, reusing the open one instead of starting another. */
    public function pay(Request $request, int $invoice, PaymentAttemptService $attempts): Response
    {
        $attempt = $attempts->openFor($this->ownInvoice($request, $invoice), route('portal.payments.return'));

        return Inertia::location($attempt->redirect_url);
    }

    private function customer(Request $request): Customer
    {
        return $request->attributes->get(EnsureCustomerAccess::CUSTOMER_ATTRIBUTE);
    }

    private function ownInvoice(Request $request, int $id): Invoice
    {
        return Invoice::query()
            ->whereBelongsTo($this->customer($request))
            ->whereIn('status', Invoice::CUSTOMER_VISIBLE_STATUSES)
            ->findOrFail($id);
    }
}
