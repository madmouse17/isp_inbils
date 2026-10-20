<?php

namespace App\Actions;

use App\Models\Core\Company;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use App\Services\Core\NumberSequenceService;
use App\Support\Money;
use App\Support\Notifications\StaffRecipients;
use App\Support\RecordNotFound;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceItem;
use Modules\Billing\Notifications\InstallationInvoiceDraftNotification;
use Modules\Billing\Services\BillingService;
use Modules\Inventory\Models\Product;
use Modules\SPK\Actions\CompleteSpkAction;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderItem;

/**
 * Cross-module write orchestration: SPK -> Billing.
 * Lives in app/Actions (not Modules/SPK or Modules/Billing) so neither
 * module's Controller/Service imports the other's Model directly.
 */
class CreateInvoiceFromSpkAction
{
    /**
     * What an SPK bills, as invoice lines in whole rupiah (K1). Writes nothing.
     *
     * The installation fee is one line "Biaya pemasangan", and only an installation has one. The materials the technician
     * used are part of that fee unless the company bills them (K7): then each is a line "Bahan terpakai: {barang}" at its
     * sell price. A line that rounds to Rp 0 is left out, so a draft is never an invoice that cannot be sent.
     *
     * @return list<array{product_id: int|null, description: string, quantity: string, unit_price: string, tax_rate: float, line_total: string, tax_amount: string}>
     */
    public static function linesFor(WorkOrder $wo): array
    {
        $wo->loadMissing(['subscription', 'items.product']);
        $taxRate = BillingService::taxRateFor($wo->company_id);
        $lines = [];

        if (self::switchedOn($wo, 'bill_installation_materials')) {
            foreach ($wo->items as $item) {
                // A product deleted since is still billed: its materials were used (the relation keeps it for history).
                /** @var WorkOrderItem $item */
                /** @var Product|null $product */
                $product = $item->product;

                if ($product !== null && $item->quantity_used > 0) {
                    $lines[] = self::line($item->product_id, 'Bahan terpakai: '.$product->name, (string) $item->quantity_used, Money::round((string) $product->sell_price), $taxRate);
                }
            }
        }

        // The relation still returns a deleted subscription, for the history pages: its installation fee is not billed.
        $subscription = $wo->subscription?->trashed() ? null : $wo->subscription;

        if ($wo->type === 'installation' && $subscription !== null && Money::compare($subscription->otc_installation_fee, '0') > 0) {
            $lines[] = self::line(null, 'Biaya pemasangan', '1.00', Money::rupiah($subscription->otc_installation_fee), $taxRate);
        }

        return array_values(array_filter($lines, fn (array $line): bool => Money::compare($line['line_total'], '0') > 0));
    }

    /** The draft invoice of a completed SPK, or null when the SPK bills nothing (see linesFor). */
    public static function execute(int $workOrderId): ?Invoice
    {
        return DB::transaction(function () use ($workOrderId): ?Invoice {
            $wo = WorkOrder::withoutCompany()->whereKey($workOrderId)->lockForUpdate()->firstOrFail();
            RecordNotFound::abortUnless($wo->company_id === CompanyService::currentId());
            abort_if($wo->status !== 'completed', 422, 'SPK harus selesai sebelum tagihan dibuat.');

            $existing = Invoice::withoutCompany()->where('work_order_id', $workOrderId)->lockForUpdate()->first();
            abort_if($existing !== null, 422, 'Tagihan untuk SPK ini sudah ada.');

            CompleteSpkAction::reconcileUsedQuantitiesFromMaterialReturns($wo);
            $wo->load(['subscription', 'items.product']);
            $lines = self::linesFor($wo);

            if ($lines === []) {
                return null;
            }

            $invoice = Invoice::create([
                'number' => NumberSequenceService::generate('invoice', $wo->company_id),
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

            foreach ($lines as $line) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $line['product_id'],
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'tax_rate' => $line['tax_rate'],
                    'line_total' => $line['line_total'],
                ]);
            }

            BillingService::recalculate($invoice);

            AuditService::log('invoice', 'created_from_spk', ['number' => $invoice->number, 'spk_id' => $wo->id], $invoice);

            return $invoice->fresh();
        });
    }

    /**
     * What the approval of an installation SPK leaves behind (K17): a draft, and the billing staff hear of it, or an
     * invoice already sent when the company sends installation invoices by itself. It runs in the approval's transaction,
     * so a failure keeps the SPK in review instead of completing it with no bill.
     */
    public static function afterApproval(WorkOrder $wo): void
    {
        $invoice = self::execute($wo->id);

        if ($invoice === null) {
            return;
        }

        if (self::switchedOn($wo, 'auto_send_installation_invoice')) {
            BillingService::send($invoice);

            return;
        }

        // A notice that cannot be queued is reported, never allowed to undo an approval that has committed.
        DB::afterCommit(fn () => rescue(fn () => Notification::send(
            StaffRecipients::holding((int) $invoice->company_id, 'billing.send'),
            new InstallationInvoiceDraftNotification((int) $invoice->company_id, $invoice->id),
        )));
    }

    /** @return array{product_id: int|null, description: string, quantity: string, unit_price: string, tax_rate: float, line_total: string, tax_amount: string} */
    private static function line(?int $productId, string $description, string $quantity, string $unitPrice, float $taxRate): array
    {
        $lineTotal = BillingService::lineTotal($quantity, $unitPrice);

        return [
            'product_id' => $productId,
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_rate' => $taxRate,
            'line_total' => $lineTotal,
            'tax_amount' => BillingService::lineTax($lineTotal, $taxRate),
        ];
    }

    /** A switch of the company's Tagihan settings; off while the company has never saved it. */
    private static function switchedOn(WorkOrder $wo, string $key): bool
    {
        /** @var Company|null $company */
        $company = $wo->company;

        return filter_var($company?->settings[$key] ?? false, FILTER_VALIDATE_BOOL);
    }
}
