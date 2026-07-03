<?php

namespace Tests\Feature\Billing;

use App\Models\Core\ServiceSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\BillingService;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class RecurringBillingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCompanyUser;

    private function makeSubscription(array $attrs = []): ServiceSubscription
    {
        return ServiceSubscription::factory()->create(array_merge([
            'status' => 'active',
            'activation_date' => '2026-05-10',
            'mrc_amount' => 300000,
        ], $attrs));
    }

    public function test_generates_full_month_invoice_for_active_subscription(): void
    {
        $this->actingAs($this->createCompanyUser());
        $sub = $this->makeSubscription();

        $result = BillingService::generateForPeriod('2026-06');

        $this->assertSame(1, $result['created']);
        $invoice = Invoice::where('subscription_id', $sub->id)->first();
        $this->assertNotNull($invoice);
        $this->assertSame('recurring', $invoice->type);
        $this->assertSame('sent', $invoice->status);
        $this->assertSame('2026-06-01', $invoice->billing_period_start->toDateString());
        $this->assertSame('2026-06-30', $invoice->billing_period_end->toDateString());
        $this->assertEquals(300000.00, (float) $invoice->subtotal);
        // seeded global PPN 11%
        $this->assertEquals(33000.00, (float) $invoice->tax_amount);
        $this->assertEquals(333000.00, (float) $invoice->total);
        $this->assertNull($invoice->created_by);
    }

    public function test_is_idempotent(): void
    {
        $this->actingAs($this->createCompanyUser());
        $this->makeSubscription();

        BillingService::generateForPeriod('2026-06');
        $second = BillingService::generateForPeriod('2026-06');

        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, Invoice::where('type', 'recurring')->count());
    }

    public function test_prorates_mid_month_activation(): void
    {
        $this->actingAs($this->createCompanyUser());
        // activated June 16 → 15 active days of 30
        $sub = $this->makeSubscription(['activation_date' => '2026-06-16']);

        BillingService::generateForPeriod('2026-06');

        $invoice = Invoice::where('subscription_id', $sub->id)->first();
        $this->assertEquals(150000.00, (float) $invoice->subtotal); // 15/30 × 300000
    }

    public function test_prorates_mid_month_termination(): void
    {
        $this->actingAs($this->createCompanyUser());
        // terminated June 20 → 20 active days of 30 (termination day counts)
        $sub = $this->makeSubscription([
            'status' => 'terminated',
            'terminated_at' => '2026-06-20 10:00:00',
        ]);

        BillingService::generateForPeriod('2026-06');

        $invoice = Invoice::where('subscription_id', $sub->id)->first();
        $this->assertEquals(200000.00, (float) $invoice->subtotal); // 20/30 × 300000
    }

    public function test_skips_not_yet_active_and_terminated_before_period(): void
    {
        $this->actingAs($this->createCompanyUser());
        $this->makeSubscription(['activation_date' => '2026-07-05']); // future
        $this->makeSubscription([
            'status' => 'terminated',
            'activation_date' => '2026-01-10',
            'terminated_at' => '2026-05-20 00:00:00', // before June
        ]);
        $this->makeSubscription(['status' => 'suspended']); // isolir

        $result = BillingService::generateForPeriod('2026-06');

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, Invoice::count());
    }

    public function test_dry_run_writes_nothing_but_returns_rows(): void
    {
        $this->actingAs($this->createCompanyUser());
        $this->makeSubscription();

        $result = BillingService::generateForPeriod('2026-06', dryRun: true);

        $this->assertSame(0, $result['created']);
        $this->assertCount(1, $result['rows']);
        $this->assertEquals(300000.00, $result['rows'][0]['amount']);
        $this->assertSame(0, Invoice::count());
    }

    public function test_uses_company_tax_rate(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $user->company->update(['settings' => ['tax_ppn_rate' => 0]]);
        $sub = $this->makeSubscription();

        BillingService::generateForPeriod('2026-06');

        $invoice = Invoice::where('subscription_id', $sub->id)->first();
        $this->assertEquals(0.00, (float) $invoice->tax_amount);
        $this->assertEquals(300000.00, (float) $invoice->total);
    }
}
