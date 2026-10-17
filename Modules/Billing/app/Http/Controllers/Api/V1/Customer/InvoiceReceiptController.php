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
use Modules\Billing\Support\Terbilang;

class InvoiceReceiptController extends Controller
{
    /**
     * The invoice as a PDF receipt (`application/pdf`). Only a paid invoice of the token's customer has one; any other id is a 404.
     *
     * @response string
     */
    public function __invoke(Request $request, int $id): Response
    {
        $customer = $request->attributes->get(EnsureCustomerAccess::CUSTOMER_ATTRIBUTE);
        abort_unless($customer instanceof Customer, 403);

        $invoice = Invoice::query()
            ->whereBelongsTo($customer)
            ->where('status', 'paid')
            ->with(['customer', 'subscription.servicePackage', 'items'])
            ->findOrFail($id);
        $company = CompanyService::current();

        return Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'company' => $company,
            'bankInfo' => ($company?->settings ?? [])['bank_account_info'] ?? '',
            'terbilang' => Terbilang::make((float) $invoice->total),
        ])->download($invoice->number.'.pdf');
    }
}
