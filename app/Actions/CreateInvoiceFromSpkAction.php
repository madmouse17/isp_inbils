<?php

namespace App\Actions;

use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use App\Services\Core\NumberSequenceService;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceItem;
use Modules\Billing\Services\BillingService;
use Modules\SPK\Models\WorkOrder;

/**
 * Cross-module write orchestration: SPK -> Billing.
 * Lives in app/Actions (not Modules/SPK or Modules/Billing) so neither
 * module's Controller/Service imports the other's Model directly.
 */
class CreateInvoiceFromSpkAction
{
    public static function execute(int $workOrderId): Invoice
    {
        return DB::transaction(function () use ($workOrderId) {
            $wo = WorkOrder::withoutCompany()->whereKey($workOrderId)->lockForUpdate()->firstOrFail();
            abort_unless($wo->company_id === CompanyService::currentId(), 404);
            abort_if($wo->status !== 'completed', 422, 'SPK must be completed to create invoice.');

            $existing = Invoice::withoutCompany()->where('work_order_id', $workOrderId)->lockForUpdate()->first();
            abort_if($existing, 422, 'Invoice already exists for this SPK.');

            $wo->loadMissing(['subscription', 'items.product']);
            $subscription = $wo->subscription;
            $taxRate = BillingService::taxRateFor($wo->company_id);

            $invoice = Invoice::create([
                'number' => NumberSequenceService::generate('invoice', 'INV', $wo->company_id),
                'type' => 'one_time',
                'source' => 'spk',
                'customer_id' => $wo->customer_id,
                'work_order_id' => $wo->id,
                'subscription_id' => $wo->subscription_id,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(14)->toDateString(),
                'status' => 'draft',
                'created_by' => Auth::id(),
            ]);

            foreach ($wo->items as $item) {
                if ($item->quantity_used > 0 && $item->product) {
                    $lineTotal = Money::round(Money::mul($item->quantity_used, $item->product->sell_price));
                    InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'product_id' => $item->product_id,
                        'description' => $item->product->name,
                        'quantity' => $item->quantity_used,
                        'unit_price' => Money::round($item->product->sell_price),
                        'tax_rate' => $taxRate,
                        'line_total' => $lineTotal,
                    ]);
                }
            }

            if ($subscription && Money::compare($subscription->otc_installation_fee, '0') > 0) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => 'Biaya Instalasi '.$wo->type,
                    'quantity' => 1,
                    'unit_price' => Money::round($subscription->otc_installation_fee),
                    'tax_rate' => $taxRate,
                    'line_total' => Money::round($subscription->otc_installation_fee),
                ]);
            }

            BillingService::recalculate($invoice);

            AuditService::log('invoice', 'created_from_spk', ['number' => $invoice->number, 'spk_id' => $wo->id], $invoice);

            return $invoice->fresh();
        });
    }
}
