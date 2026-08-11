<?php

namespace Modules\Billing\Services;

use App\Actions\CreateInvoiceFromSpkAction;
use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use App\Services\Core\NumberSequenceService;
use App\Services\Core\SettingService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceItem;
use Modules\Billing\Models\Payment;
use Modules\Inventory\Models\Product;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BillingService
{
    public static function taxRateFor(int $companyId): string
    {
        $settings = Company::find($companyId)?->settings ?? [];

        return (string) ($settings['tax_ppn_rate'] ?? SettingService::get('default_tax_ppn_rate', 11));
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
    public static function generateForPeriod(string $period, bool $dryRun = false, ?int $companyId = null): array
    {
        $companyId ??= CompanyService::currentId();
        abort_if($companyId === null, 403, 'Company context is required.');
        self::assertCompanyId($companyId);

        $periodStart = CarbonImmutable::createFromFormat('Y-m', $period)->startOfMonth()->startOfDay();
        $periodEnd = $periodStart->endOfMonth()->startOfDay();

        $subscriptions = ServiceSubscription::withoutCompany()
            ->with(['customer', 'servicePackage'])
            ->where('company_id', $companyId)
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

            if (Money::compare($amount, '0') <= 0) {
                $skipped++;

                continue;
            }

            $taxRate = self::taxRateFor($sub->company_id);
            $tax = Money::round(Money::div(Money::mul($amount, $taxRate), '100'));

            $rows[] = [
                'subscription_id' => $sub->id,
                'subscription_code' => $sub->code,
                'customer' => $sub->customer?->name ?? '-',
                'package' => $sub->servicePackage?->name ?? '-',
                'active_days' => $activeDays,
                'days_in_period' => $daysInPeriod,
                'amount' => Money::round($amount),
                'tax' => Money::round($tax),
                'total' => Money::round(Money::add($amount, $tax)),
            ];

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($sub, $periodStart, $periodEnd, $activeDays, $daysInPeriod, $amount, $taxRate) {
                $subscription = ServiceSubscription::withoutCompany()->lockForUpdate()->findOrFail($sub->id);
                self::assertCompanyId($subscription->company_id);

                $exists = Invoice::withoutCompany()
                    ->where('subscription_id', $subscription->id)
                    ->where('type', 'recurring')
                    ->where('status', '!=', 'cancelled')
                    ->whereDate('billing_period_start', $periodStart)
                    ->lockForUpdate()
                    ->exists();
                if ($exists) {
                    return;
                }

                $invoice = Invoice::create([
                    'company_id' => $subscription->company_id,
                    'number' => NumberSequenceService::generate('invoice', 'INV', $subscription->company_id),
                    'type' => 'recurring',
                    'source' => 'subscription',
                    'customer_id' => $subscription->customer_id,
                    'subscription_id' => $subscription->id,
                    'issue_date' => now()->toDateString(),
                    'due_date' => now()->addDays(self::dueDaysFor($subscription->company_id))->toDateString(),
                    'billing_period_start' => $periodStart->toDateString(),
                    'billing_period_end' => $periodEnd->toDateString(),
                    'status' => 'sent',
                    'sent_at' => now(),
                    'created_by' => null, // ponytail: batch job, no human actor
                ]);

                $label = 'MRC '.($subscription->servicePackage?->name ?? 'Subscription')
                    .' '.$periodStart->toDateString().' s/d '.$periodEnd->toDateString();
                if ($activeDays < $daysInPeriod) {
                    $label .= " (prorata {$activeDays}/{$daysInPeriod} hari)";
                }

                InvoiceItem::create([
                    'company_id' => $subscription->company_id,
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

    /** @return array{0:int,1:int,string} [activeDays, daysInPeriod, amount] */
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
            return [0, $daysInPeriod, '0.00'];
        }

        $activeDays = (int) $from->diffInDays($until) + 1;
        $amount = $activeDays >= $daysInPeriod
            ? Money::round($sub->mrc_amount)
            : Money::round(Money::mul(Money::div((string) $activeDays, (string) $daysInPeriod), (string) $sub->mrc_amount));

        return [$activeDays, $daysInPeriod, $amount];
    }

    public static function send(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = Invoice::withoutCompany()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            self::assertCompanyId($invoice->company_id);
            abort_if($invoice->status !== 'draft', 422, 'Invoice must be draft to send.');

            $invoice->update(['status' => 'sent', 'sent_at' => now()]);

            AuditService::log('invoice', 'sent', ['number' => $invoice->number], $invoice);

            return $invoice->fresh();
        });
    }

    public static function recordPayment(Invoice $invoice, string|int|float $amount, string $method, ?string $reference = null, ?string $notes = null): Payment
    {
        abort_unless($invoice->company_id === (int) Auth::user()?->company_id, 404);
        abort_if(Money::compare($amount, '0') <= 0, 422, 'Payment amount must be positive.');

        return DB::transaction(function () use ($invoice, $amount, $method, $reference, $notes) {
            $invoice = Invoice::withoutCompany()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            abort_unless($invoice->company_id === (int) Auth::user()?->company_id, 404);
            abort_if(Money::gt($amount, $invoice->sisa), 422, 'Payment amount exceeds remaining balance.');
            abort_if(
                $reference && Payment::withoutCompany()
                    ->where('company_id', $invoice->company_id)
                    ->where('reference', $reference)
                    ->whereNull('cancelled_at')
                    ->exists(),
                422,
                'Payment reference already exists.'
            );

            $payment = Payment::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'amount' => Money::round($amount),
                'method' => $method,
                'reference' => $reference,
                'paid_at' => now(),
                'received_by' => Auth::id(),
                'notes' => $notes,
            ]);

            $newPaid = Money::add($invoice->paid_amount, $amount);

            if (Money::compare($newPaid, $invoice->total) >= 0) {
                $invoice->update(['status' => 'paid', 'paid_amount' => Money::round($newPaid)]);
            } else {
                $invoice->update(['status' => 'partial', 'paid_amount' => Money::round($newPaid)]);
            }

            AuditService::log('invoice', 'payment_recorded', [
                'number' => $invoice->number, 'amount' => $amount, 'method' => $method,
            ], $invoice);

            return $payment;
        });
    }

    public static function cancel(Invoice $invoice, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason) {
            $invoice = Invoice::withoutCompany()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            self::assertCompanyId($invoice->company_id);
            abort_if($invoice->status === 'cancelled', 422, 'Invoice already cancelled.');
            abort_if($invoice->payments()->whereNull('cancelled_at')->exists(), 422, 'Cannot cancel invoice with active payments. Reverse payments first.');

            $invoice->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            AuditService::log('invoice', 'cancelled', ['number' => $invoice->number, 'reason' => $reason], $invoice);

            return $invoice->fresh();
        });
    }

    /**
     * @param  array{product_id?: int|null, description: string, quantity: string|int|float, unit_price: string|int|float, discount_amount?: string|int|float|null, tax_rate?: string|int|float|null}  $data
     */
    public static function addItem(Invoice $invoice, array $data): void
    {
        DB::transaction(function () use ($invoice, $data) {
            $invoice = Invoice::withoutCompany()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            self::assertCompanyId($invoice->company_id);
            abort_if($invoice->status !== 'draft', 422, 'Can only add items to draft invoices.');

            if ($productId = ($data['product_id'] ?? null)) {
                Product::withoutCompany()
                    ->where('company_id', $invoice->company_id)
                    ->lockForUpdate()
                    ->findOrFail($productId);
            }

            $lineTotal = Money::round(Money::sub(Money::mul($data['quantity'], $data['unit_price']), $data['discount_amount'] ?? 0));

            InvoiceItem::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'product_id' => $data['product_id'] ?? null,
                'description' => $data['description'],
                'quantity' => $data['quantity'],
                'unit_price' => $data['unit_price'],
                'discount_amount' => $data['discount_amount'] ?? 0,
                'tax_rate' => $data['tax_rate'] ?? 0,
                'line_total' => $lineTotal,
            ]);

            self::recalculate($invoice);
        });
    }

    /**
     * Remove an item from a draft invoice inside a transaction-local lock.
     *
     * @throws HttpException 404 if item not found, 422 if invoice not draft
     */
    public static function removeItem(Invoice $invoice, int $itemId): void
    {
        DB::transaction(function () use ($invoice, $itemId) {
            $invoice = Invoice::withoutCompany()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            self::assertCompanyId($invoice->company_id);
            abort_if($invoice->status !== 'draft', 422, 'Can only remove items from draft invoices.');

            $item = InvoiceItem::withoutCompany()
                ->where('id', $itemId)
                ->where('invoice_id', $invoice->id)
                ->where('company_id', $invoice->company_id)
                ->lockForUpdate()
                ->first();

            abort_unless($item, 404);

            $item->delete();
            self::recalculate($invoice);
        });
    }

    public static function createFromSpk(int $workOrderId): Invoice
    {
        return CreateInvoiceFromSpkAction::execute($workOrderId);
    }

    public static function recalculate(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $invoice = Invoice::withoutCompany()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $invoice->load('items');
            $subtotal = '0.00';
            $taxAmount = '0.00';

            foreach ($invoice->items as $item) {
                $lineTotal = Money::round(Money::sub(Money::mul($item->quantity, $item->unit_price), $item->discount_amount));
                $item->update(['line_total' => $lineTotal]);
                $subtotal = Money::add($subtotal, $lineTotal);
                $taxAmount = Money::add($taxAmount, Money::round(Money::div(Money::mul($lineTotal, $item->tax_rate), '100')));
            }

            $total = Money::sub(Money::add($subtotal, $taxAmount), $invoice->discount_amount);

            $invoice->update([
                'subtotal' => Money::round($subtotal),
                'tax_amount' => Money::round($taxAmount),
                'total' => Money::round($total),
            ]);
        });
    }

    public static function checkOverdue(?int $companyId = null): void
    {
        $companyId ??= CompanyService::currentId();
        abort_if($companyId === null, 403, 'Company context is required.');
        self::assertCompanyId($companyId);

        Invoice::withoutCompany()
            ->where('company_id', $companyId)
            ->whereIn('status', ['sent', 'partial'])
            ->whereDate('due_date', '<', now())
            ->whereColumn('paid_amount', '<', 'total')
            ->update(['status' => 'overdue']);
    }

    public static function receivables(): array
    {
        $companyId = CompanyService::currentId();
        abort_if($companyId === null, 403, 'Company context is required.');

        $invoices = Invoice::withoutCompany()
            ->with('customer:id,name')
            ->where('company_id', $companyId)
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
                $buckets = ['current' => '0.00', 'b1_30' => '0.00', 'b31_60' => '0.00', 'b61_90' => '0.00', 'b90_plus' => '0.00'];

                foreach ($group as $invoice) {
                    $outstanding = Money::sub($invoice->total, $invoice->paid_amount);
                    $daysPast = (int) $invoice->due_date->startOfDay()->diffInDays(now()->startOfDay(), false);

                    $key = match (true) {
                        $daysPast <= 0 => 'current',
                        $daysPast <= 30 => 'b1_30',
                        $daysPast <= 60 => 'b31_60',
                        $daysPast <= 90 => 'b61_90',
                        default => 'b90_plus',
                    };

                    $buckets[$key] = Money::add($buckets[$key], $outstanding);
                }

                $first = $group->first();
                $total = array_reduce($buckets, fn (string $carry, string $value) => Money::add($carry, $value), '0.00');

                return [
                    'customer_id' => $first->customer_id,
                    'customer' => $first->customer?->name ?? '-',
                    ...$buckets,
                    'total' => Money::round($total),
                    'invoice_count' => $group->count(),
                    'subscriptions' => ($subsByCustomer[$first->customer_id] ?? collect())
                        ->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'status' => $s->status])
                        ->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    private static function assertCompanyId(int $companyId): void
    {
        $currentId = CompanyService::currentId();

        // In Artisan/queue context there is no authenticated user; trust the
        // explicit company id passed by the command/job. In web context the
        // id must match the authenticated user's company.
        if ($currentId !== null) {
            abort_unless($companyId === (int) $currentId, 404);
        }
    }
}
