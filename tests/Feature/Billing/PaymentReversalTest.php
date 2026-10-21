<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Services\BillingService;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class PaymentReversalTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private function makeInvoice(User $user, float $total = 100000, string $status = 'sent'): Invoice
    {
        return InvoiceFactory::new()->create([
            'company_id' => $user->company_id,
            'status' => $status,
            'total' => $total,
            'paid_amount' => 0,
        ]);
    }

    private function userWithRole(string $role, int $companyId): User
    {
        $user = User::factory()->create([
            'company_id' => $companyId,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->grantRole($user, $role);

        return $user;
    }

    private function managerInSameCompany(User $user): User
    {
        return $this->userWithRole('manager', (int) $user->company_id);
    }

    // AC1: reversal records actor and decrements paid amount, status re-derived
    public function test_reverse_sets_cancelled_fields_and_decrements_paid_amount(): void
    {
        $recorder = $this->createCompanyUser();
        $this->actingAs($recorder);
        $reverser = $this->managerInSameCompany($recorder);
        $invoice = $this->makeInvoice($recorder, 100000);
        $payment = BillingService::recordPayment($invoice, 40000, 'transfer', 'PAY-REV-1');

        $this->actingAs($reverser)
            ->post(route('admin.invoices.payments.reverse', [$invoice, $payment]), ['reason' => 'wrong amount'])
            ->assertRedirect();

        $fresh = $payment->fresh();
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertSame('wrong amount', $fresh->cancel_reason);
        $this->assertSame($reverser->id, $fresh->cancelled_by);
        $this->assertNotSame($recorder->id, $fresh->cancelled_by);

        $invoice = $invoice->fresh();
        $this->assertEquals(0.0, (float) $invoice->paid_amount);
        $this->assertSame('sent', $invoice->status);
    }

    // AC5: full reversal paid -> sent
    public function test_full_reversal_paid_to_sent(): void
    {
        $recorder = $this->createCompanyUser();
        $this->actingAs($recorder);
        $reverser = $this->managerInSameCompany($recorder);
        $invoice = $this->makeInvoice($recorder, 100000);
        $payment = BillingService::recordPayment($invoice, 100000, 'cash', 'PAY-FULL');

        $this->assertSame('paid', $invoice->fresh()->status);

        $this->actingAs($reverser)
            ->post(route('admin.invoices.payments.reverse', [$invoice, $payment]), ['reason' => 'duplicate'])
            ->assertRedirect();

        $invoice = $invoice->fresh();
        $this->assertEquals(0.0, (float) $invoice->paid_amount);
        $this->assertSame('sent', $invoice->status);
    }

    // AC5: partial reversal of a paid invoice -> partial
    public function test_partial_reversal_paid_to_partial(): void
    {
        $recorder = $this->createCompanyUser();
        $this->actingAs($recorder);
        $reverser = $this->managerInSameCompany($recorder);
        $invoice = $this->makeInvoice($recorder, 100000);

        BillingService::recordPayment($invoice, 60000, 'transfer', 'PAY-P1');
        $payment = BillingService::recordPayment($invoice->fresh(), 40000, 'transfer', 'PAY-P2');
        $this->assertSame('paid', $invoice->fresh()->status);

        $this->actingAs($reverser)
            ->post(route('admin.invoices.payments.reverse', [$invoice, $payment]), ['reason' => 'overcharge'])
            ->assertRedirect();

        $invoice = $invoice->fresh();
        $this->assertEquals(60000.0, (float) $invoice->paid_amount);
        $this->assertSame('partial', $invoice->status);
    }

    // AC2: double reverse -> 422
    public function test_cannot_reverse_already_cancelled_payment(): void
    {
        $recorder = $this->createCompanyUser();
        $this->actingAs($recorder);
        $reverser = $this->managerInSameCompany($recorder);
        $invoice = $this->makeInvoice($recorder, 100000);
        $payment = BillingService::recordPayment($invoice, 40000, 'cash', 'PAY-2X');

        $this->actingAs($reverser)
            ->post(route('admin.invoices.payments.reverse', [$invoice, $payment]), ['reason' => 'first'])
            ->assertRedirect();

        $this->actingAs($reverser)
            ->post(route('admin.invoices.payments.reverse', [$invoice, $payment]), ['reason' => 'second'])
            ->assertStatus(422);
    }

    // AC3: same-actor maker/checker denial -> 422
    public function test_recorder_cannot_reverse_own_payment(): void
    {
        $recorder = $this->createCompanyUser();
        $this->actingAs($recorder);
        $invoice = $this->makeInvoice($recorder, 100000);
        $payment = BillingService::recordPayment($invoice, 40000, 'cash', 'PAY-SELF');

        $this->actingAs($recorder)
            ->post(route('admin.invoices.payments.reverse', [$invoice, $payment]), ['reason' => 'oops'])
            ->assertStatus(422);

        $this->assertNull($payment->fresh()->cancelled_at);
    }

    // AC4: cross-tenant reversal -> 404
    public function test_cross_tenant_reversal_denied(): void
    {
        $recorder = $this->createCompanyUser();
        $this->actingAs($recorder);
        $invoice = $this->makeInvoice($recorder, 100000);
        $payment = BillingService::recordPayment($invoice, 40000, 'cash', 'PAY-XT');

        $otherCompanyUser = $this->createCompanyUser();
        $this->actingAs($otherCompanyUser);

        try {
            BillingService::reversePayment($payment, 'cross-tenant');
            $this->fail('Cross-tenant reversal should be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->actingAs($otherCompanyUser)
            ->post(route('admin.invoices.payments.reverse', [$invoice, $payment]), ['reason' => 'cross-tenant'])
            ->assertNotFound();
    }

    // AC6: invoice cancellation records cancelled_by
    public function test_invoice_cancel_records_cancelled_by(): void
    {
        $user = $this->createCompanyUser();
        $invoice = $this->makeInvoice($user, 100000);

        $this->actingAs($user)
            ->post(route('admin.invoices.cancel', $invoice), ['reason' => 'customer request'])
            ->assertRedirect();

        $fresh = $invoice->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertSame($user->id, $fresh->cancelled_by);
    }

    // AC7: after reversal the invoice is cancellable again (dead-end resolved)
    public function test_invoice_cancellable_after_reversal(): void
    {
        $recorder = $this->createCompanyUser();
        $this->actingAs($recorder);
        $reverser = $this->managerInSameCompany($recorder);
        $invoice = $this->makeInvoice($recorder, 100000);
        $payment = BillingService::recordPayment($invoice, 40000, 'cash', 'PAY-DEADEND');

        $this->actingAs($recorder)
            ->post(route('admin.invoices.cancel', $invoice), ['reason' => 'nope'])
            ->assertStatus(422);

        $this->actingAs($reverser)
            ->post(route('admin.invoices.payments.reverse', [$invoice, $payment]), ['reason' => 'no longer needed'])
            ->assertRedirect();

        $this->actingAs($recorder)
            ->post(route('admin.invoices.cancel', $invoice), ['reason' => 'customer request'])
            ->assertRedirect();

        $this->assertSame('cancelled', $invoice->fresh()->status);
    }

    // AC8: staff cannot reverse -> 403; a technician (app-only) never reaches the admin routes at all
    public function test_staff_and_technician_cannot_reverse(): void
    {
        $recorder = $this->createCompanyUser();
        $this->actingAs($recorder);
        $invoice = $this->makeInvoice($recorder, 100000);
        $payment = BillingService::recordPayment($invoice, 40000, 'cash', 'PAY-RBAC');
        $url = route('admin.invoices.payments.reverse', [$invoice, $payment]);

        $this->actingAs($this->userWithRole('staff', (int) $recorder->company_id))->post($url, ['reason' => 'sneaky'])->assertForbidden();
        $this->actingAs($this->userWithRole('technician', (int) $recorder->company_id))->post($url, ['reason' => 'sneaky'])->assertRedirect(route('app-only'));

        $this->assertNull($payment->fresh()->cancelled_at);
    }

    // AC9: reversal writes an audited activity log entry
    public function test_reversal_writes_audit_log(): void
    {
        $recorder = $this->createCompanyUser();
        $this->actingAs($recorder);
        $reverser = $this->managerInSameCompany($recorder);
        $invoice = $this->makeInvoice($recorder, 100000);
        $payment = BillingService::recordPayment($invoice, 40000, 'transfer', 'PAY-AUDIT');

        $this->actingAs($reverser)
            ->post(route('admin.invoices.payments.reverse', [$invoice, $payment]), ['reason' => 'audited reason'])
            ->assertRedirect();

        $activity = Activity::query()
            ->where('log_name', 'invoice')
            ->where('description', 'payment_reversed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($reverser->id, $activity->causer_id);
        $this->assertSame($invoice->number, $activity->properties['number'] ?? null);
        $this->assertSame('40000.00', (string) ($activity->properties['amount'] ?? ''));
        $this->assertSame('audited reason', $activity->properties['reason'] ?? null);
    }

    // AC10: reversal recomputes from the locked fresh paid_amount, not a stale model
    public function test_reversal_uses_locked_fresh_paid_amount(): void
    {
        $recorder = $this->createCompanyUser();
        $this->actingAs($recorder);
        $reverser = $this->managerInSameCompany($recorder);
        $invoice = $this->makeInvoice($recorder, 100000);
        $payment = BillingService::recordPayment($invoice, 40000, 'transfer', 'PAY-STALE');

        // Simulate a concurrent payment landing after the model snapshot.
        DB::table('invoices')
            ->where('id', $invoice->id)
            ->update(['paid_amount' => 100000]);

        $this->actingAs($reverser);
        $result = BillingService::reversePayment($payment->fresh(), 'stale-balance lock');

        $this->assertNotNull($result->cancelled_at);
        $fresh = $invoice->fresh();
        $this->assertEquals(60000.0, (float) $fresh->paid_amount);
        $this->assertSame('partial', $fresh->status);
    }
}
