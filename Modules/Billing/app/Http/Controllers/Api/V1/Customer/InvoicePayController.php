<?php

namespace Modules\Billing\Http\Controllers\Api\V1\Customer;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCustomerAccess;
use App\Models\Core\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\MidtransGateway;
use Modules\Billing\Services\PaymentAttemptService;

class InvoicePayController extends Controller
{
    public function __invoke(Request $request, int $id, MidtransGateway $gateway, PaymentAttemptService $attempts): JsonResponse
    {
        $invoice = $this->ownInvoice($request, $id);

        if (! $gateway->isEnabled()) {
            throw new ApiException(404, 'payment_unavailable', 'Pembayaran online belum tersedia.');
        }

        if (($reason = PaymentAttemptService::unpayableReason($invoice)) !== null) {
            throw new ApiException(422, 'invoice_not_payable', $reason);
        }

        $attempt = $attempts->openFor($invoice, route('payments.finished'));

        return response()->json(['data' => [
            'redirect_url' => $attempt->redirect_url,
            'expires_at' => $attempt->expires_at->toIso8601String(),
        ]]);
    }

    private function ownInvoice(Request $request, int $id): Invoice
    {
        return Invoice::query()
            ->whereBelongsTo($this->customer($request))
            ->whereIn('status', Invoice::CUSTOMER_VISIBLE_STATUSES)
            ->findOrFail($id);
    }

    private function customer(Request $request): Customer
    {
        return $request->attributes->get(EnsureCustomerAccess::CUSTOMER_ATTRIBUTE);
    }
}
