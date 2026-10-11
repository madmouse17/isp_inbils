<?php

namespace Modules\Billing\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCustomerAccess;
use App\Models\Core\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Billing\Http\Requests\Api\V1\Customer\ListInvoicesRequest;
use Modules\Billing\Http\Resources\PortalInvoiceResource;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\MidtransGateway;
use Modules\Billing\Services\PaymentAttemptService;

class InvoiceController extends Controller
{
    private const UNPAID_STATUSES = ['sent', 'partial', 'overdue'];

    public function index(ListInvoicesRequest $request): AnonymousResourceCollection
    {
        $query = Invoice::query()
            ->whereBelongsTo($this->customer($request))
            ->whereIn('status', Invoice::CUSTOMER_VISIBLE_STATUSES);

        if ($request->status() === 'unpaid') {
            $query->whereIn('status', self::UNPAID_STATUSES)->orderBy('due_date')->orderBy('id');
        } elseif ($request->status() === 'paid') {
            $query->where('status', 'paid')->orderByDesc('issue_date')->orderByDesc('id');
        } else {
            $query->orderByDesc('issue_date')->orderByDesc('id');
        }

        return PortalInvoiceResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(Request $request, int $id, MidtransGateway $gateway): JsonResponse
    {
        $invoice = $this->ownInvoice($request, $id)->load([
            'items',
            'payments' => fn ($payments) => $payments->whereNull('cancelled_at')->orderBy('paid_at'),
        ]);
        $enabled = $gateway->isEnabled();
        $blockedReason = PaymentAttemptService::unpayableReason($invoice);

        return response()->json(['data' => [
            ...((new PortalInvoiceResource($invoice))->resolve($request)),
            'payment' => [
                'enabled' => $enabled,
                'can_pay' => $enabled && $blockedReason === null,
                'blocked_reason' => $enabled ? $blockedReason : null,
            ],
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
