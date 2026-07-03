<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\Payment;
use Modules\Billing\Services\BillingService;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class PaymentReconciliationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCompanyUser;

    public function test_partial_and_full_payment_update_invoice_status(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $invoice = InvoiceFactory::new()->create([
            'company_id' => $user->company_id,
            'status' => 'sent',
            'total' => 100000,
            'paid_amount' => 0,
        ]);

        BillingService::recordPayment($invoice, 40000, 'transfer', 'PAY-001');
        $this->assertSame('partial', $invoice->fresh()->status);
        $this->assertEquals(40000.00, (float) $invoice->fresh()->paid_amount);

        BillingService::recordPayment($invoice->fresh(), 60000, 'transfer', 'PAY-002');
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertEquals(100000.00, (float) $invoice->fresh()->paid_amount);
    }

    public function test_rejects_zero_negative_and_overpayment(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $invoice = InvoiceFactory::new()->create([
            'company_id' => $user->company_id,
            'status' => 'sent',
            'total' => 100000,
            'paid_amount' => 0,
        ]);

        foreach ([0, -1, 100001] as $amount) {
            try {
                BillingService::recordPayment($invoice, $amount, 'cash');
                $this->fail("Amount {$amount} should be rejected.");
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }

        $this->assertSame(0, Payment::count());
    }

    public function test_sets_payment_company_id_and_prevents_duplicate_reference_per_company(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $invoice = InvoiceFactory::new()->create([
            'company_id' => $user->company_id,
            'status' => 'sent',
            'total' => 100000,
            'paid_amount' => 0,
        ]);

        $payment = BillingService::recordPayment($invoice, 10000, 'transfer', 'BANK-123');
        $this->assertSame($user->company_id, $payment->company_id);

        try {
            BillingService::recordPayment($invoice->fresh(), 10000, 'transfer', 'BANK-123');
            $this->fail('Duplicate reference should be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_user_cannot_record_payment_for_other_company_invoice(): void
    {
        $user = $this->createCompanyUser();
        $otherUser = $this->createCompanyUser();
        $this->actingAs($user);
        $invoice = InvoiceFactory::new()->create([
            'company_id' => $otherUser->company_id,
            'status' => 'sent',
            'total' => 100000,
            'paid_amount' => 0,
        ]);

        try {
            BillingService::recordPayment($invoice, 10000, 'cash');
            $this->fail('Cross-company payment should be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }
}
