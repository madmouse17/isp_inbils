<?php

namespace Modules\Billing\Services;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\AuditService;
use App\Services\Core\NumberSequenceService;
use App\Services\Core\SettingService;
use Carbon\CarbonImmutable;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceItem;
use Modules\Billing\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class BillingService
{
    public static function taxRateFor(int $companyId): float
    {
        $settings = Company::find($companyId)?->settings ?? [];

        return (float) ($settings['tax_ppn_rate'] ?? SettingService::get('default_tax_ppn_rate', 11));
    }

    public static function dueDaysFor(int $companyId): int
    {
        $settings = Company::find($companyId)?->settings ?? [];

        return (int) ($settings['invoice_due_days'] ?? 14);
    }

    /**
     * Generate postpaid recurring invoices for a period ('YYYY-MM').
     * Idempotent; safe to re-run. $dryRun computes rows without writing.
     */
    public static function generateForPeriod(string $period, bool $dryRun = false): array
    {
        $periodStart = CarbonImmutable::createFromFormat('Y-m', $period)->startOfMonth()->startOfDay();
        $periodEnd = $periodStart->endOfMonth()->startOfDay();

        $subscriptions = ServiceSubscription::withoutCompany()
            ->with(['customer', 'servicePackage'])
            ->whereIn('status', ['active', 'terminated'])
            ->whereDate('activation_date', '<=', $periodEnd)
            ->where(fn ($q) => $q->whereNull('terminated_at')
                ->orWhereDate('terminated_at', '>=', $periodStart))
            ->get();

        $rows = [];
        $created = 0;
        $skipped = 0;

        foreach ($subscriptions as $sub) {
            $exists = Invoice::withoutCompany()
                ->where('subscription_id', $sub->id)
                ->where('type', 'recurring')
                ->where('status', '!=', 'cancelled')
                ->whereDate('billing_period_start', $periodStart)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            [$activeDays, $daysInPeriod, $amount] = self::prorationFor($sub, $periodStart, $periodEnd);

            if ($amount <= 0) {
                $skipped++;
                continue;
            }

            $taxRate = self::taxRateFor($sub->company_id);
            $tax = round($amount * $taxRate / 100, 2);

            $rows[] = [
                'subscription_id' => $sub->id,
                'subscription_code' => $sub->code,
                'customer' => $sub->customer?->name ?? '-',
                'package' => $sub->servicePackage?->name ?? '-',
                'active_days' => $activeDays,
                'days_in_period' => $daysInPeriod,
                'amount' => $amount,
                'tax' => $tax,
                'total' => round($amount + $tax, 2),
            ];

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($sub, $periodStart, $periodEnd, $activeDays, $daysInPeriod, $amount, $taxRate) {
                $invoice = Invoice::create([
                    'company_id' => $sub->company_id,
                    'number' => NumberSequenceService::generate('invoice', 'INV', $sub->company_id),
                    'type' => 'recurring',
                    'source' => 'subscription',
                    'customer_id' => $sub->customer_id,
                    'subscription_id' => $sub->id,
                    'issue_date' => now()->toDateString(),
                    'due_date' => now()->addDays(self::dueDaysFor($sub->company_id))->toDateString(),
                    'billing_period_start' => $periodStart->toDateString(),
                    'billing_period_end' => $periodEnd->toDateString(),
                    'status' => 'sent',
                    'sent_at' => now(),
                    'created_by' => null, // ponytail: batch job, no human actor
                ]);

                $label = 'MRC ' . ($sub->servicePackage?->name ?? 'Subscription')
                    . ' ' . $periodStart->toDateString() . ' s/d ' . $periodEnd->toDateString();
                if ($activeDays < $daysInPeriod) {
                    $label .= " (prorata {$activeDays}/{$daysInPeriod} hari)";
                }

                InvoiceItem::create([
                    'company_id' => $sub->company_id,
                    'invoice_id' => $invoice->id,
                    'description' => $label,
                    'quantity' => 1,
                    'unit_price' => $amount,
                    'tax_rate' => $taxRate,
                    'line_total' => $amount,
                ]);

                self::recalculate($invoice);

                AuditService::log('invoice', 'recurring_generated', ['number' => $invoice->number], $invoice);
            });

            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped, 'rows' => $rows];
    }

    /** @return array{0:int,1:int,2:float} [activeDays, daysInPeriod, amount] */
    private static function prorationFor(ServiceSubscription $sub, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $daysInPeriod = $periodStart->daysInMonth;

        $activation = CarbonImmutable::parse($sub->activation_date)->startOfDay();
        $from = $activation->gt($periodStart) ? $activation : $periodStart;

        $termination = $sub->terminated_at
            ? CarbonImmutable::parse($sub->terminated_at)->startOfDay()
            : null;
        $until = ($termination && $termination->lt($periodEnd)) ? $termination : $periodEnd;

        if ($from->gt($until)) {
            return [0, $daysInPeriod, 0.0];
        }

        $activeDays = (int) $from->diffInDays($until) + 1;
        $amount = $activeDays >= $daysInPeriod
            ? round((float) $sub->mrc_amount, 2)
            : round(($activeDays / $daysInPeriod) * (float) $sub->mrc_amount, 2);

        return [$activeDays, $daysInPeriod, $amount];
    }

    public static function createFromSpk(int $workOrderId): Invoice
    {
        $wo = \Modules\SPK\Models\WorkOrder::findOrFail($workOrderId);

        abort_if($wo->status !== 'completed', 422, 'SPK must be completed to create invoice.');

        $existing = Invoice::where('work_order_id', $workOrderId)->first();
        abort_if($existing, 422, 'Invoice already exists for this SPK.');

        return DB::transaction(function () use ($wo) {
            $subscription = $wo->subscription;
            $taxRate = self::taxRateFor($wo->company_id);

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
        abort_unless($invoice->company_id === (int) Auth::user()?->company_id, 404);
        $sisa = $invoice->sisa;

        abort_if($amount > $sisa, 422, 'Payment amount exceeds remaining balance.');
        abort_if($amount <= 0, 422, 'Payment amount must be positive.');
        abort_if(
            $reference && Payment::withoutCompany()
                ->where('company_id', $invoice->company_id)
                ->where('reference', $reference)
                ->whereNull('cancelled_at')
                ->exists(),
            422,
            'Payment reference already exists.'
        );

        return DB::transaction(function () use ($invoice, $amount, $method, $reference, $notes, $sisa) {
            $payment = Payment::create([
                'company_id' => $invoice->company_id,
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

    public static function receivables(): array
    {
        $invoices = Invoice::query()
            ->with('customer:id,name')
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->whereColumn('paid_amount', '<', 'total')
            ->get();

        $subsByCustomer = ServiceSubscription::query()
            ->whereIn('customer_id', $invoices->pluck('customer_id')->unique())
            ->where('status', 'active')
            ->get(['id', 'code', 'status', 'customer_id'])
            ->groupBy('customer_id');

        return $invoices
            ->groupBy('customer_id')
            ->map(function ($group) use ($subsByCustomer) {
                $buckets = ['current' => 0.0, 'b1_30' => 0.0, 'b31_60' => 0.0, 'b61_90' => 0.0, 'b90_plus' => 0.0];

                foreach ($group as $invoice) {
                    $outstanding = (float) $invoice->total - (float) $invoice->paid_amount;
                    $daysPast = (int) $invoice->due_date->startOfDay()->diffInDays(now()->startOfDay(), false);

                    $key = match (true) {
                        $daysPast <= 0 => 'current',
                        $daysPast <= 30 => 'b1_30',
                        $daysPast <= 60 => 'b31_60',
                        $daysPast <= 90 => 'b61_90',
                        default => 'b90_plus',
                    };

                    $buckets[$key] += $outstanding;
                }

                $first = $group->first();

                return [
                    'customer_id' => $first->customer_id,
                    'customer' => $first->customer?->name ?? '-',
                    ...$buckets,
                    'total' => array_sum($buckets),
                    'invoice_count' => $group->count(),
                    'subscriptions' => ($subsByCustomer[$first->customer_id] ?? collect())
                        ->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'status' => $s->status])
                        ->values()->all(),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }
}
