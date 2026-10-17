<?php

namespace Modules\Billing\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCustomerAccess;
use App\Models\Core\Customer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentAttempt;

/**
 * Where Midtrans sends the customer back. Nothing Midtrans appends to the URL is believed: the order id
 * only picks one of the customer's own attempts, and the state shown is what the server holds.
 */
class PaymentReturnController extends Controller
{
    public function __invoke(Request $request): InertiaResponse
    {
        /** @var Customer $customer */
        $customer = $request->attributes->get(EnsureCustomerAccess::CUSTOMER_ATTRIBUTE);
        $orderId = $request->query('order_id');

        $attempt = PaymentAttempt::query()
            ->with('invoice:id,public_id,number')
            ->whereIn('invoice_id', Invoice::query()->whereBelongsTo($customer)->select('id'))
            ->when(is_string($orderId), fn ($query) => $query->where('order_id', $orderId))
            ->latest('id')
            ->first();

        return Inertia::render('Portal/Payments/Return', [
            'attempt' => $attempt === null ? null : [
                'status' => $attempt->status,
                'amount' => $attempt->amount,
                'settled_at' => $attempt->settled_at?->toIso8601String(),
                'invoice' => ['id' => $attempt->invoice->public_id, 'number' => $attempt->invoice->number],
            ],
        ]);
    }
}
