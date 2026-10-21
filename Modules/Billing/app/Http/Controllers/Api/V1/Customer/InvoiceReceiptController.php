<?php

namespace Modules\Billing\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCustomerAccess;
use App\Models\Core\Customer;
use App\Services\Core\CompanyService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Support\InvoiceParty;
use Modules\Billing\Support\Terbilang;

class InvoiceReceiptController extends Controller
{
    /**
     * The invoice as a PDF receipt (`application/pdf`). Only a paid invoice of the token's customer has one; any other id is a 404.
     *
     * @response string
     */
    public function __invoke(Request $request, string $id): Response
    {
        $customer = $request->attributes->get(EnsureCustomerAccess::CUSTOMER_ATTRIBUTE);
        abort_unless($customer instanceof Customer, 403);

        $invoice = Invoice::query()
            ->whereBelongsTo($customer)
            ->where('status', 'paid')
            ->with('subscription')
            ->wherePublicId($id)
            ->firstOrFail();

        return Pdf::loadView('pdf.receipt', [
            'invoice' => $invoice,
            // The customer's tax id never goes to a customer app (SecretKeyWalkTest), so the receipt prints none.
            'party' => [...InvoiceParty::of($invoice), 'customer_tax_id' => null],
            'payments' => $invoice->payments()->whereNull('cancelled_at')->orderBy('paid_at')->orderBy('id')->get(),
            'company' => CompanyService::current(),
            'terbilang' => Terbilang::make((float) $invoice->paid_amount),
        ])->download('Kuitansi-'.$invoice->number.'.pdf');
    }
}
