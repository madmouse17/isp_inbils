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
use App\Support\OptimisticLock;
use App\Support\RecordNotFound;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Events\InvoiceIssued;
use Modules\Billing\Events\PaymentRecorded;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceItem;
use Modules\Billing\Models\Payment;
use Modules\Billing\Support\InvoiceParty;
use Modules\Inventory\Models\Product;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

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
     * Generate postpaid recurring invoices for a billing period ('YYYY-MM').
     * Idempotent; safe to re-run. $dryRun computes rows without writing.
     *
     * A recurring invoice of the period in any status counts as issued, a cancelled one too: the schedule never issues a
     * period again behind a person's back (a disputed period is billed by hand as a one-time invoice). One subscription
     * that fails is reported and counted in `failed`; the rest of the company is still billed.
     *
     * @return array{created: int, skipped: int, failed: int, rows: list<array<string, mixed>>}
     */
    public static function generateForPeriod(string $period, bool $dryRun = false, ?int $companyId = null): array
    {
        $companyId ??= CompanyService::currentId();
        abort_if($companyId === null, 403, 'Konteks perusahaan wajib ada.');
        self::assertCompanyId($companyId);

        $periodStart = CarbonImmutable::createFromFormat('Y-m', $period)->startOfMonth()->startOfDay();
        $periodEnd = $periodStart->endOfMonth()->startOfDay();

        $subscriptions = ServiceSubscription::withoutCompany()
            ->with(['customer', 'servicePackage', 'installationAddress.province', 'installationAddress.regionCity', 'installationAddress.district', 'installationAddress.village'])
            ->where('company_id', $companyId)
            ->whereIn('status', ['active', 'terminated'])
            ->whereDate('activation_date', '<=', $periodEnd)
            ->where(fn ($q) => $q->whereNull('terminated_at')
                ->orWhereDate('terminated_at', '>=', $periodStart))
            ->get();

        $rows = [];
        $created = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($subscriptions as $sub) {
            try {
                if (self::recurringIssued($sub->id, $periodStart)) {
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

                $row = [
                    'subscription_id' => $sub->public_id,
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
                    $rows[] = $row;

                    continue;
                }

                if (self::issueRecurring($sub, $periodStart, $periodEnd, $activeDays, $daysInPeriod, $amount, $taxRate)) {
                    $rows[] = $row;
                    $created++;
                } else {
                    $skipped++;
                }
            } catch (UniqueConstraintViolationException) {
                // Another run was first: the unique key on invoices.active_recurring_period refused this one.
                $skipped++;
            } catch (Throwable $e) {
                report($e);
                $failed++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'failed' => $failed, 'rows' => $rows];
    }

    /** Any recurring invoice of the period, whatever its status; $lock waits for a run that is issuing it right now. */
    private static function recurringIssued(int $subscriptionId, CarbonImmutable $periodStart, bool $lock = false): bool
    {
        return Invoice::withoutCompany()
            ->where('subscription_id', $subscriptionId)
            ->where('type', 'recurring')
            ->whereDate('billing_period_start', $periodStart)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->exists();
    }

    /** @return bool whether this call issued the invoice: false when another run issued the period between the check and the lock */
    private static function issueRecurring(ServiceSubscription $sub, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, int $activeDays, int $daysInPeriod, string $amount, float $taxRate): bool
    {
        return DB::transaction(function () use ($sub, $periodStart, $periodEnd, $activeDays, $daysInPeriod, $amount, $taxRate): bool {
            $subscription = ServiceSubscription::withoutCompany()->lockForUpdate()->findOrFail($sub->id);
            self::assertCompanyId($subscription->company_id);

            if (self::recurringIssued($subscription->id, $periodStart, lock: true)) {
                return false;
            }

            $invoice = new Invoice([
                'company_id' => $subscription->company_id,
                'number' => NumberSequenceService::generate('invoice', $subscription->company_id),
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
            // The batch loaded the party with the subscription, so freezing it costs no query per invoice.
            $invoice->setRelation('customer', $sub->customer)->setRelation('subscription', $sub);
            InvoiceParty::freeze($invoice);
            $invoice->save();

            $label = 'MRC '.($invoice->package_name_snapshot ?? 'Subscription')
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

            InvoiceIssued::dispatch($invoice->company_id, $invoice->id);

            return true;
        }, 3);
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
            abort_if($invoice->status !== 'draft', 422, 'Hanya tagihan draf yang bisa dikirim.');

            InvoiceParty::freeze($invoice);
            $invoice->update(['status' => 'sent', 'sent_at' => now()]);

            AuditService::log('invoice', 'sent', ['number' => $invoice->number], $invoice);

            InvoiceIssued::dispatch($invoice->company_id, $invoice->id);

            return $invoice->fresh();
        }, 3);
    }

    public static function recordPayment(Invoice $invoice, string|int|float $amount, string $method, ?string $reference = null, ?string $notes = null): Payment
    {
        RecordNotFound::abortUnless($invoice->company_id === (int) CompanyService::currentId());
        abort_if(Money::compare($amount, '0') <= 0, 422, 'Jumlah pembayaran harus lebih dari nol.');

        return DB::transaction(function () use ($invoice, $amount, $method, $reference, $notes) {
            $invoice = Invoice::withoutCompany()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            RecordNotFound::abortUnless($invoice->company_id === (int) CompanyService::currentId());
            abort_unless(in_array($invoice->status, ['sent', 'partial', 'overdue'], true), 422, 'Tagihan tidak menerima pembayaran pada statusnya saat ini.');
            abort_if(Money::gt($amount, $invoice->sisa), 422, 'Jumlah pembayaran melebihi sisa tagihan.');
            abort_if(
                $reference && Payment::withoutCompany()
                    ->where('company_id', $invoice->company_id)
                    ->where('reference', $reference)
                    ->whereNull('cancelled_at')
                    ->exists(),
                422,
                'Referensi pembayaran sudah ada.'
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

            self::closeOpenPages($invoice, $method === 'midtrans' ? $reference : null);

            AuditService::log('invoice', 'payment_recorded', [
                'number' => $invoice->number, 'amount' => $amount, 'method' => $method,
            ], $invoice);

            PaymentRecorded::dispatch($invoice->company_id, $invoice->id, $payment->id);

            return $payment;
        }, 3);
    }

    public static function cancel(Invoice $invoice, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason) {
            $invoice = Invoice::withoutCompany()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            self::assertCompanyId($invoice->company_id);
            abort_if($invoice->status === 'cancelled', 422, 'Tagihan sudah dibatalkan.');
            abort_if($invoice->payments()->whereNull('cancelled_at')->exists(), 422, 'Tagihan yang punya pembayaran aktif tidak bisa dibatalkan. Batalkan pembayarannya dulu.');
            abort_if($invoice->created_by && (int) Auth::id() === (int) $invoice->created_by, 422, 'Tagihan yang Anda buat sendiri tidak bisa Anda batalkan.');

            // A draft that is cancelled leaves draft too; an issued invoice already has its snapshot, which freeze() keeps.
            InvoiceParty::freeze($invoice);
            $invoice->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
                'cancelled_by' => Auth::id(),
            ]);

            self::closeOpenPages($invoice);

            AuditService::log('invoice', 'cancelled', ['number' => $invoice->number, 'reason' => $reason], $invoice);

            return $invoice->fresh();
        }, 3);
    }

    /**
     * Reverse a recorded payment: mark it cancelled and decrement the invoice
     * paid amount. Maker/checker: the user who recorded the payment cannot
     * reverse it. Tenant + already-cancelled guards run both before and inside
     * the locked transaction (defense in depth).
     */
    public static function reversePayment(Payment $payment, string $reason): Payment
    {
        RecordNotFound::abortUnless($payment->company_id === (int) CompanyService::currentId());
        abort_if($payment->cancelled_at !== null, 422, 'Pembayaran sudah dibatalkan.');
        abort_if(Auth::id() === $payment->received_by, 422, 'Pembayaran yang Anda catat sendiri tidak bisa Anda batalkan.');

        return DB::transaction(function () use ($payment, $reason) {
            $payment = Payment::withoutCompany()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            RecordNotFound::abortUnless($payment->company_id === (int) CompanyService::currentId());
            abort_if($payment->cancelled_at !== null, 422, 'Pembayaran sudah dibatalkan.');
            abort_if(Auth::id() === $payment->received_by, 422, 'Pembayaran yang Anda catat sendiri tidak bisa Anda batalkan.');

            $invoice = Invoice::withoutCompany()->whereKey($payment->invoice_id)->lockForUpdate()->firstOrFail();

            Payment::executeInCanonicalReversal(function () use ($payment, $reason) {
                $payment->update([
                    'cancelled_at' => now(),
                    'cancel_reason' => $reason,
                    'cancelled_by' => Auth::id(),
                ]);
            });

            $newPaid = Money::sub($invoice->paid_amount, $payment->amount);
            $newStatus = Money::compare($newPaid, '0') <= 0
                ? 'sent'
                : (Money::compare($newPaid, $invoice->total) >= 0 ? 'paid' : 'partial');
            $invoice->update(['paid_amount' => Money::round($newPaid), 'status' => $newStatus]);

            AuditService::log('invoice', 'payment_reversed', [
                'number' => $invoice->number, 'amount' => $payment->amount, 'reason' => $reason,
            ], $invoice);

            return $payment->fresh();
        }, 3);
    }

    /**
     * @param  array{product_id?: int|null, description: string, quantity: string|int|float, unit_price: string|int|float, discount_amount?: string|int|float|null, tax_rate?: string|int|float|null}  $data
     */
    public static function addItem(Invoice $invoice, array $data): void
    {
        DB::transaction(function () use ($invoice, $data) {
            $invoice = Invoice::withoutCompany()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            self::assertCompanyId($invoice->company_id);
            abort_if($invoice->status !== 'draft', 422, 'Hanya tagihan draf yang bisa diberi item.');

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
        }, 3);
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
            abort_if($invoice->status !== 'draft', 422, 'Item hanya bisa dihapus dari tagihan draf.');

            $item = InvoiceItem::withoutCompany()
                ->where('id', $itemId)
                ->where('invoice_id', $invoice->id)
                ->where('company_id', $invoice->company_id)
                ->lockForUpdate()
                ->first();

            RecordNotFound::abortUnless($item);

            $item->delete();
            self::recalculate($invoice);
        }, 3);
    }

    /**
     * Save the editable fields of a draft. The draft is read again under its lock: one that was sent since the form was
     * rendered is refused with that reason and nothing is written, and so is a save made from a copy somebody else has
     * saved over. The totals are recalculated in the same transaction.
     *
     * @param  array<string, mixed>  $data  the validated fields, without the version
     *
     * @throws HttpException 422 if the invoice is no longer a draft, 409 if it changed since the version the form carries
     */
    public static function update(Invoice $invoice, array $data, string $expectedUpdatedAt): void
    {
        DB::transaction(function () use ($invoice, $data, $expectedUpdatedAt) {
            $invoice = Invoice::withoutCompany()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            self::assertCompanyId($invoice->company_id);
            abort_if($invoice->status !== 'draft', 422, 'Hanya tagihan draf yang bisa diubah.');
            OptimisticLock::assert($invoice, $expectedUpdatedAt);

            $invoice->update($data);
            self::recalculate($invoice);
        }, 3);
    }

    /** Delete a draft. The status is read again under the lock: an invoice that was sent since the request bound it stays. */
    public static function destroyDraft(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $invoice = Invoice::withoutCompany()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            self::assertCompanyId($invoice->company_id);
            abort_if($invoice->status !== 'draft', 422, 'Hanya tagihan draf yang bisa dihapus.');

            $invoice->delete();
        }, 3);
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
        }, 3);
    }

    public static function checkOverdue(?int $companyId = null): void
    {
        $companyId ??= CompanyService::currentId();
        abort_if($companyId === null, 403, 'Konteks perusahaan wajib ada.');
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
        abort_if($companyId === null, 403, 'Konteks perusahaan wajib ada.');

        $invoices = Invoice::withoutCompany()
            ->with('customer:id,public_id,name')
            ->where('company_id', $companyId)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->whereColumn('paid_amount', '<', 'total')
            // Newest first: the first invoice of a customer names it as it was printed.
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get();

        $subsByCustomer = ServiceSubscription::query()
            ->whereIn('customer_id', $invoices->pluck('customer_id')->unique())
            // Suspended subscriptions only while blocked, so staff can unblock them here.
            ->where(fn ($query) => $query->where('status', 'active')
                ->orWhere(fn ($blocked) => $blocked->where('status', 'suspended')->whereNotNull('blocked_at')))
            ->get(['id', 'public_id', 'code', 'status', 'customer_id', 'blocked_at'])
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
                    'customer_id' => $first->customer?->public_id,
                    'customer' => $first->customer_name_snapshot ?? $first->customer?->name ?? '-',
                    ...$buckets,
                    'total' => Money::round($total),
                    'invoice_count' => $group->count(),
                    'subscriptions' => ($subsByCustomer[$first->customer_id] ?? collect())
                        ->map(fn ($s) => ['id' => $s->public_id, 'code' => $s->code, 'status' => $s->status, 'blocked_at' => $s->blocked_at])
                        ->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /** The invoice changed what can be paid: its open Snap pages are closed inside the caller's transaction, under the invoice's company. */
    private static function closeOpenPages(Invoice $invoice, ?string $exceptOrderId = null): void
    {
        CompanyService::runAs($invoice->company_id, fn () => app(PaymentAttemptService::class)->closeOpenFor($invoice, $exceptOrderId));
    }

    private static function assertCompanyId(int $companyId): void
    {
        $currentId = CompanyService::currentId();

        // In Artisan/queue context there is no authenticated user; trust the
        // explicit company id passed by the command/job. In web context the
        // id must match the authenticated user's company.
        if ($currentId !== null) {
            RecordNotFound::abortUnless($companyId === (int) $currentId);
        }
    }
}
