<?php

namespace Modules\Billing\Services;

use App\Services\Core\AuditService;
use App\Services\Core\NumberSequenceService;
use App\Services\Core\SettingService;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceItem;
use Modules\Billing\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class BillingService
{
    public static function createRecurring(array $data): Invoice
    {
        return DB::transaction(function () use ($data) {
            $data['number'] = NumberSequenceService::generate('invoice', 'INV', $data['company_id'] ?? null);
            $data['type'] = 'recurring';
            $data['source'] = 'subscription';
            $data['status'] = $data['status'] ?? 'draft';
            $data['created_by'] = Auth::id();

            $invoice = Invoice::create($data);

            // Create MRC line item
            $subscription = $invoice->subscription;
            $taxRate = (float) SettingService::get('default_tax_ppn_rate', 0);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => 'MRC ' . ($subscription?->servicePackage?->name ?? 'Subscription') . ' ' . $data['billing_period_start'] . ' to ' . $data['billing_period_end'],
                'quantity' => 1,
                'unit_price' => $subscription?->mrc_amount ?? 0,
                'tax_rate' => $taxRate,
                'line_total' => $subscription?->mrc_amount ?? 0,
            ]);

            self::recalculate($invoice);

            AuditService::log('invoice', 'recurring_generated', ['number' => $invoice->number], $invoice);

            return $invoice->fresh();
        });
    }

    public static function createFromSpk(int $workOrderId): Invoice
    {
        $wo = \Modules\SPK\Models\WorkOrder::findOrFail($workOrderId);

        abort_if($wo->status !== 'completed', 422, 'SPK must be completed to create invoice.');

        $existing = Invoice::where('work_order_id', $workOrderId)->first();
        abort_if($existing, 422, 'Invoice already exists for this SPK.');

        return DB::transaction(function () use ($wo) {
            $subscription = $wo->subscription;
            $taxRate = (float) SettingService::get('default_tax_ppn_rate', 0);

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

            // Map consumable items from SPK
            foreach ($wo->items as $item) {
                if ($item->quantity_used > 0 && $item->product) {
                    $lineTotal = (float) $item->quantity_used * (float) $item->product->sell_price;
                    InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'product_id' => $item->product_id,
                        'description' => $item->product->name,
                        'quantity' => $item->quantity_used,
                        'unit_price' => $item->product->sell_price,
                        'tax_rate' => $taxRate,
                        'line_total' => $lineTotal,
                    ]);
                }
            }

            // Installation fee line
            if ($subscription && $subscription->otc_installation_fee > 0) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => 'Biaya Instalasi ' . $wo->type,
                    'quantity' => 1,
                    'unit_price' => $subscription->otc_installation_fee,
                    'tax_rate' => $taxRate,
                    'line_total' => $subscription->otc_installation_fee,
                ]);
            }

            self::recalculate($invoice);

            AuditService::log('invoice', 'created_from_spk', ['number' => $invoice->number, 'spk_id' => $wo->id], $invoice);

            return $invoice->fresh();
        });
    }

    public static function send(Invoice $invoice): Invoice
    {
        abort_if($invoice->status !== 'draft', 422, 'Invoice must be draft to send.');

        return DB::transaction(function () use ($invoice) {
            $invoice->update(['status' => 'sent', 'sent_at' => now()]);

            AuditService::log('invoice', 'sent', ['number' => $invoice->number], $invoice);

            return $invoice->fresh();
        });
    }

    public static function recordPayment(Invoice $invoice, float $amount, string $method, ?string $reference = null, ?string $notes = null): Payment
    {
        $sisa = $invoice->sisa;

        abort_if($amount > $sisa, 422, 'Payment amount exceeds remaining balance.');
        abort_if($amount <= 0, 422, 'Payment amount must be positive.');

        return DB::transaction(function () use ($invoice, $amount, $method, $reference, $notes, $sisa) {
            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'method' => $method,
                'reference' => $reference,
                'paid_at' => now(),
                'received_by' => Auth::id(),
                'notes' => $notes,
            ]);

            $newPaid = (float) $invoice->paid_amount + $amount;

            if ($newPaid >= (float) $invoice->total) {
                $invoice->update(['status' => 'paid', 'paid_amount' => $newPaid]);
            } else {
                $invoice->update(['status' => 'partial', 'paid_amount' => $newPaid]);
            }

            AuditService::log('invoice', 'payment_recorded', [
                'number' => $invoice->number, 'amount' => $amount, 'method' => $method,
            ], $invoice);

            return $payment;
        });
    }

    public static function cancel(Invoice $invoice, string $reason): Invoice
    {
        abort_if($invoice->status === 'cancelled', 422, 'Invoice already cancelled.');
        abort_if($invoice->payments()->whereNull('cancelled_at')->exists(), 422, 'Cannot cancel invoice with active payments. Reverse payments first.');

        return DB::transaction(function () use ($invoice, $reason) {
            $invoice->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            AuditService::log('invoice', 'cancelled', ['number' => $invoice->number, 'reason' => $reason], $invoice);

            return $invoice->fresh();
        });
    }

    public static function recalculate(Invoice $invoice): void
    {
        $invoice->load('items');
        $subtotal = 0;
        $taxAmount = 0;

        foreach ($invoice->items as $item) {
            $lineTotal = (float) $item->quantity * (float) $item->unit_price - (float) $item->discount_amount;
            $item->update(['line_total' => $lineTotal]);
            $subtotal += $lineTotal;
            $taxAmount += $lineTotal * (float) $item->tax_rate / 100;
        }

        $total = $subtotal + $taxAmount - (float) $invoice->discount_amount;

        $invoice->update([
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
        ]);
    }

    public static function checkOverdue(): void
    {
        Invoice::withoutCompany()
            ->whereIn('status', ['sent', 'partial'])
            ->whereDate('due_date', '<', now())
            ->whereColumn('paid_amount', '<', 'total')
            ->update(['status' => 'overdue']);
    }
}
