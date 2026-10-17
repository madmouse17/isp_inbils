<?php

namespace Modules\Billing\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCustomerAccess;
use App\Models\Core\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Billing\Http\Requests\Api\V1\Customer\ListPaymentsRequest;
use Modules\Billing\Http\Resources\Api\V1\CustomerPaymentResource;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;

class PaymentController extends Controller
{
    public function index(ListPaymentsRequest $request): AnonymousResourceCollection
    {
        $invoices = Invoice::query()
            ->whereBelongsTo($this->customer($request))
            ->whereIn('status', Invoice::CUSTOMER_VISIBLE_STATUSES)
            ->select('id');

        $payments = Payment::query()
            ->select('id', 'public_id', 'invoice_id', 'amount', 'method', 'reference', 'paid_at')
            ->whereIn('invoice_id', $invoices)
            ->whereNull('cancelled_at')
            ->with('invoice:id,public_id,number')
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return CustomerPaymentResource::collection($payments);
    }

    private function customer(Request $request): Customer
    {
        return $request->attributes->get(EnsureCustomerAccess::CUSTOMER_ATTRIBUTE);
    }
}
