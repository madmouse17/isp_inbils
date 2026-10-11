<?php

namespace Database\Seeders\Demo;

use App\Services\Core\NumberSequenceService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Services\BillingService;

/**
 * The billing calendar the scheduler would have kept (billing:generate at 02:00, billing:check-overdue at
 * 03:00, both daily), customers paying on their own habits, and the odd manual invoice.
 */
final class BillingDemo
{
    private const METHODS = ['transfer' => 62, 'cash' => 28, 'other' => 10];

    private const BANKS = ['BCA', 'BRI', 'MANDIRI', 'BNI'];

    public static function plan(DemoContext $c): void
    {
        // A run that would fall after the seeding moment has not happened yet.
        for ($month = $c->start->startOfMonth()->addMonth(); $month->setTime(2, 0)->lessThanOrEqualTo($c->now->subMinutes(2)); $month = $month->addMonth()) {
            $period = $month->subMonth()->format('Y-m');
            $c->timeline->at($month->setTime(2, 0), fn (DemoContext $c) => self::generate($c, $period), "billing:generate {$period}");
        }

        for ($day = $c->start->addDay(); $day->setTime(3, 0)->lessThanOrEqualTo($c->now->subMinutes(5)); $day = $day->addDay()) {
            $c->timeline->at($day->setTime(3, 0), fn (DemoContext $c) => BillingService::checkOverdue($c->companyId()), 'billing:check-overdue');
        }

        self::planSpecials($c);
    }

    /** A month's recurring invoices, and when each customer will pay theirs. */
    private static function generate(DemoContext $c, string $period): void
    {
        BillingService::generateForPeriod($period, false, $c->companyId());

        $invoices = Invoice::query()
            ->where('type', 'recurring')
            ->whereDate('billing_period_start', $period.'-01')
            ->pluck('id');

        foreach ($invoices as $id) {
            self::schedulePayments($c, (int) $id);
        }
    }

    /** Plans the payments of an invoice that was just issued, from the customer's payment habit. */
    public static function schedulePayments(DemoContext $c, int $invoiceId): void
    {
        if ($c->has('scheduled', (string) $invoiceId)) {
            return;
        }

        $c->put('scheduled', (string) $invoiceId, true);

        $invoice = Invoice::query()->findOrFail($invoiceId);
        $habit = $c->has('payer', (string) $invoice->customer_id) ? $c->get('payer', (string) $invoice->customer_id) : 'prompt';
        $issued = CarbonImmutable::now();

        if (str_starts_with($habit, 'delinq:')) {
            $count = ($c->has('billed', (string) $invoice->customer_id) ? $c->get('billed', (string) $invoice->customer_id) : 0) + 1;
            $c->put('billed', (string) $invoice->customer_id, $count);

            if ($count > (int) substr($habit, 7)) {
                return;
            }

            $habit = 'prompt';
        }

        // Now and then a good payer lets a monthly bill slip, which gives the receivables their older buckets.
        if ($invoice->type === 'recurring' && $habit !== 'partial' && $c->chance(7)) {
            return;
        }

        match ($habit) {
            'late' => self::payOn($c, $invoiceId, $issued, $c->int(17, 30), null),
            'partial' => self::payInTwo($c, $invoiceId, $issued),
            default => self::payOn($c, $invoiceId, $issued, $c->int(0, 6), null),
        };
    }

    private static function payInTwo(DemoContext $c, int $invoiceId, CarbonImmutable $issued): void
    {
        self::payOn($c, $invoiceId, $issued, $c->int(4, 9), 0.5);
        self::payOn($c, $invoiceId, $issued, $c->int(26, 40), null);
    }

    /** A payment of $fraction of the total (null: whatever is still due) $days after $issued. */
    private static function payOn(DemoContext $c, int $invoiceId, CarbonImmutable $issued, int $days, ?float $fraction): void
    {
        $when = $c->dayAfter($issued, $days, $c->int(8, 16), $c->int(0, 59));

        if ($when->greaterThanOrEqualTo($c->now->subMinutes(3))) {
            return;
        }

        $c->timeline->at($when, fn (DemoContext $c) => self::pay($c, $invoiceId, $fraction), "pay invoice {$invoiceId}");
    }

    private static function pay(DemoContext $c, int $invoiceId, ?float $fraction): void
    {
        $invoice = Invoice::query()->find($invoiceId);

        if ($invoice === null || ! in_array($invoice->status, ['sent', 'partial', 'overdue'], true)) {
            return;
        }

        $amount = $invoice->sisa;

        if ($fraction !== null) {
            $part = Money::round(Money::mul($invoice->total, $fraction));
            $amount = Money::compare($part, $amount) < 0 ? $part : $amount;
        }

        $method = $c->weighted(self::METHODS);

        $c->as($c->pick(['staff', 'staff', 'sales_dewi', 'sales_rizky']), fn () => BillingService::recordPayment(
            $invoice,
            $amount,
            $method,
            self::reference($c, $method),
            $fraction !== null ? 'Pembayaran termin pertama' : null,
        ));
    }

    private static function reference(DemoContext $c, string $method): ?string
    {
        $sequence = ($c->has('reference', 'sequence') ? $c->get('reference', 'sequence') : 0) + 1;
        $c->put('reference', 'sequence', $sequence);

        return match ($method) {
            'transfer' => sprintf('TRF-%s-%s-%04d', $c->pick(self::BANKS), now()->format('ymd'), $sequence),
            'other' => sprintf('QRIS-%s-%04d', now()->format('ymd'), $sequence),
            default => null,
        };
    }

    private static function planSpecials(DemoContext $c): void
    {
        // A payment keyed in twice: reversed by the manager and booked again correctly.
        $c->timeline->at($c->day(118, 11, 0), function (DemoContext $c): void {
            $payment = self::latestPayment($c, 'DEMO-C005');
            $invoice = Invoice::query()->findOrFail($payment->invoice_id);

            $c->as('manager', fn () => BillingService::reversePayment($payment, 'Nominal tercatat dobel, dikoreksi oleh manajer'));
            $c->as('staff', fn () => BillingService::recordPayment(
                $invoice->refresh(),
                $payment->amount,
                'transfer',
                self::reference($c, 'transfer'),
                'Dicatat ulang setelah koreksi pembayaran dobel',
            ));
        }, 'reverse and rebook payment');

        // A transfer the bank bounced: reversed, and the invoice is open again.
        $c->timeline->at($c->day(28, 14, 0), function (DemoContext $c): void {
            $payment = self::latestPayment($c, 'DEMO-C012');

            $c->as('manager', fn () => BillingService::reversePayment($payment, 'Dana transfer tidak masuk, ditolak bank pengirim'));
        }, 'reverse bounced payment');

        self::manualInvoice($c, 55, 'DEMO-C016', 'Biaya survey ulang dan relokasi ONT', [['Biaya survey ulang titik instalasi', 1, 150000], ['Relokasi ONT ke lantai dua', 1, 250000]], 'cancel', 'Tagihan dobel, sudah digabung ke tagihan bulanan');
        // Recent enough to still be inside the 14 days of credit whenever the seeder runs, so the books always hold a part-paid and a sent invoice.
        self::manualInvoice($c, 9, 'DEMO-C023', 'Sewa access point tambahan', [['Access Point TP-Link EAP225, sewa 3 bulan', 3, 120000]], 'part-pay');
        self::manualInvoice($c, 3, 'DEMO-C029', 'Biaya survey perluasan jaringan kantor', [['Survey dan desain jalur fiber tambahan', 1, 350000]], 'sent');
        self::manualInvoice($c, 20, 'DEMO-C008', 'Penggantian adaptor ONT', [['Adaptor 12V 1A ONT (hilang)', 1, 85000]], 'draft');
        self::manualInvoice($c, 9, 'DEMO-C020', 'Biaya pindah titik instalasi', [['Biaya pindah titik instalasi ke ruang lain', 1, 200000], ['Kabel dropcore tambahan', 40, 1500]], 'draft');
    }

    private static function latestPayment(DemoContext $c, string $customerCode): Payment
    {
        return Payment::query()
            ->whereNull('cancelled_at')
            ->whereIn('invoice_id', Invoice::query()->where('customer_id', $c->get('customer', $customerCode))->select('id'))
            ->latest('paid_at')
            ->firstOrFail();
    }

    /**
     * A manual invoice staff draws up. $outcome: draft, sent, cancel (sent, then cancelled by the manager)
     * or part-pay (sent, half paid).
     *
     * @param  list<array{0: string, 1: int, 2: int}>  $lines  description, quantity, unit price
     */
    private static function manualInvoice(DemoContext $c, int $daysAgo, string $customerCode, string $note, array $lines, string $outcome, ?string $cancelReason = null): void
    {
        $chain = $c->timeline->chain($c->day($daysAgo, 10, 20));

        $chain->step(function (DemoContext $c) use ($customerCode, $note, $lines, $outcome): void {
            $c->as('staff', function ($staff) use ($c, $customerCode, $note, $lines, $outcome): void {
                $customerId = (int) $c->get('customer', $customerCode);
                $invoice = Invoice::query()->create([
                    'number' => NumberSequenceService::generate('invoice', 'INV', $c->companyId()),
                    'type' => 'one_time',
                    'source' => 'manual',
                    'customer_id' => $customerId,
                    'subscription_id' => $c->get('subscription', $customerCode),
                    'issue_date' => now()->toDateString(),
                    'due_date' => now()->addDays(14)->toDateString(),
                    'status' => 'draft',
                    'notes' => $note,
                    'created_by' => $staff->id,
                ]);

                foreach ($lines as [$description, $quantity, $price]) {
                    BillingService::addItem($invoice, [
                        'description' => $description,
                        'quantity' => $quantity,
                        'unit_price' => $price,
                        'tax_rate' => BillingService::taxRateFor($c->companyId()),
                    ]);
                }

                $c->put('manual_invoice', $customerCode, $invoice->id);

                if ($outcome !== 'draft') {
                    BillingService::send($invoice->refresh());
                }
            });
        }, "manual invoice {$customerCode}");

        if ($outcome === 'cancel') {
            $chain->afterDays(1, fn (DemoContext $c) => $c->as('manager', fn () => BillingService::cancel(
                Invoice::query()->findOrFail($c->get('manual_invoice', $customerCode)),
                (string) $cancelReason,
            )), "cancel invoice {$customerCode}");
        }

        if ($outcome === 'part-pay') {
            $chain->afterDays(3, fn (DemoContext $c) => self::pay($c, (int) $c->get('manual_invoice', $customerCode), 0.5), "part payment {$customerCode}");
        }
    }
}
