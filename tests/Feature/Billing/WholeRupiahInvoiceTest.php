<?php

namespace Tests\Feature\Billing;

use App\Models\Core\ServiceSubscription;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Support\InvoiceParty;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/**
 * K1 (flow audit F5-D01): an invoice is built in whole rupiah, half up, so what the web, the apps and the PDF show is one
 * figure that Midtrans can charge. A stored amount with cents (an invoice issued before) is still printed exactly.
 */
class WholeRupiahInvoiceTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    public function test_money_rupiah_rounds_half_up_without_double_rounding(): void
    {
        $this->assertSame('147702.00', Money::rupiah('147701.5'));
        $this->assertSame('147701.00', Money::rupiah('147701.4999'));
        // Through cents 147701.495 would become 147701.50 and round up a second time.
        $this->assertSame('147701.00', Money::rupiah('147701.495'));
        $this->assertSame('1.00', Money::rupiah('0.5'));
        $this->assertSame('0.00', Money::rupiah('0.49'));
        $this->assertSame('100.00', Money::rupiah(100));
        $this->assertSame('1235.00', Money::rupiah(1234.5));
        $this->assertSame('-1.00', Money::rupiah('-0.5'));
        $this->assertSame('0.00', Money::rupiah('-0.49'));
        $this->assertSame('-147702.00', Money::rupiah('-147701.5'));
    }

    public function test_money_display_prints_cents_only_when_the_amount_has_them(): void
    {
        $this->assertSame('147.702', Money::display('147702.00'));
        $this->assertSame('147.701,62', Money::display('147701.62'));
        $this->assertSame('1.234.567,50', Money::display('1234567.50'));
        $this->assertSame('0', Money::display('0.00'));
        $this->assertSame('999', Money::display(999));
        $this->assertSame('-5.000', Money::display('-5000'));
    }

    public function test_prorata_line_and_tax_are_whole_rupiah(): void
    {
        $this->actingAs($this->createCompanyUser());
        // 17 of 30 days of 333.333 is 188888.7: the line, its tax (20777.79) and the total all end in .00.
        $subscription = ServiceSubscription::factory()->create(['status' => 'active', 'activation_date' => '2026-06-14', 'mrc_amount' => 333333]);

        $preview = BillingService::generateForPeriod('2026-06', dryRun: true)['rows'][0];
        BillingService::generateForPeriod('2026-06');

        $this->assertSame(['188889.00', '20778.00', '209667.00'], [$preview['amount'], $preview['tax'], $preview['total']]);
        $invoice = Invoice::query()->where('subscription_id', $subscription->id)->sole();
        $this->assertSame(['188889.00', '20778.00', '209667.00'], [$invoice->subtotal, $invoice->tax_amount, $invoice->total]);
        $line = $invoice->items()->sole();
        $this->assertSame(['188889.00', '188889.00'], [$line->unit_price, $line->line_total]);
    }

    public function test_manual_items_recalculate_to_whole_rupiah(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $invoice = InvoiceFactory::new()->create(['company_id' => $user->company_id, 'status' => 'draft']);

        BillingService::addItem($invoice, ['description' => 'Kabel', 'quantity' => 3, 'unit_price' => '33333.33', 'tax_rate' => 11]);
        BillingService::addItem($invoice, ['description' => 'Konektor', 'quantity' => 1, 'unit_price' => '0.40']);

        $invoice->refresh();
        $this->assertSame(['100000.00', '0.00'], $invoice->items()->orderBy('id')->pluck('line_total')->all());
        $this->assertSame(['100000.00', '11000.00', '111000.00'], [$invoice->subtotal, $invoice->tax_amount, $invoice->total]);

        // A discount typed with cents never leaves cents on the total: 111000 - 1000.50 = 109999.50, half up.
        $invoice->update(['discount_amount' => '1000.50']);
        BillingService::recalculate($invoice);

        $this->assertSame('110000.00', $invoice->fresh()->total);
    }

    public function test_payment_amount_with_cents_is_refused(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $invoice = InvoiceFactory::new()->create(['company_id' => $user->company_id, 'status' => 'sent', 'total' => 100000, 'paid_amount' => 0]);

        $this->postJson(route('admin.invoices.payments.store', $invoice), ['amount' => '100.50', 'method' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'Jumlah pembayaran harus rupiah penuh, tanpa sen.');
        $this->assertSame(0, Payment::query()->count());

        $this->post(route('admin.invoices.payments.store', $invoice), ['amount' => '1000.00', 'method' => 'cash'])->assertRedirect();
        $this->post(route('admin.invoices.payments.store', $invoice), ['amount' => 500, 'method' => 'cash'])->assertRedirect();

        $this->assertSame('98500.00', $invoice->fresh()->sisa);
    }

    public function test_an_invoice_issued_with_cents_can_still_be_paid_off_by_its_exact_balance(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        // OD2: invoices issued before whole-rupiah billing stay as they are; the one amount that clears them has cents.
        $legacy = InvoiceFactory::new()->create(['company_id' => $user->company_id, 'status' => 'sent', 'total' => '147701.62', 'paid_amount' => 0]);

        $this->postJson(route('admin.invoices.payments.store', $legacy), ['amount' => '147701.61', 'method' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'Jumlah pembayaran harus rupiah penuh, tanpa sen.');

        $this->post(route('admin.invoices.payments.store', $legacy), ['amount' => '147701.62', 'method' => 'cash'])->assertRedirect();

        $this->assertSame('paid', $legacy->fresh()->status);
    }

    public function test_pdf_shows_cents_only_when_present(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $print = function (string $total) use ($user): string {
            $invoice = InvoiceFactory::new()->create(['company_id' => $user->company_id, 'status' => 'sent', 'subtotal' => $total, 'total' => $total, 'paid_amount' => 0]);

            return view('pdf.invoice', [
                'invoice' => $invoice->load(['subscription', 'items']),
                'company' => $user->company,
                'bankInfo' => '',
                'terbilang' => 'nol rupiah',
                'party' => InvoiceParty::of($invoice),
            ])->render();
        };

        $whole = $print('147702.00');
        $this->assertStringContainsString('Rp 147.702<', $whole);
        $this->assertStringNotContainsString(',00', $whole);

        $legacy = $print('147701.62');
        $this->assertStringContainsString('Rp 147.701,62<', $legacy);
    }
}
