<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\Payment;
use Modules\Billing\Services\BillingService;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class PaymentStatusGuardTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function blockedStatuses(): array
    {
        return ['draft' => ['draft'], 'cancelled' => ['cancelled'], 'paid' => ['paid']];
    }

    /** @return array<string, array{string}> */
    public static function payableStatuses(): array
    {
        return ['sent' => ['sent'], 'partial' => ['partial'], 'overdue' => ['overdue']];
    }

    #[DataProvider('blockedStatuses')]
    public function test_payment_rejected_for_non_payable_status(string $status): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $invoice = InvoiceFactory::new()->create([
            'company_id' => $user->company_id,
            'status' => $status,
            'total' => 100000,
            'paid_amount' => $status === 'paid' ? 100000 : 0,
        ]);

        try {
            BillingService::recordPayment($invoice, 1000, 'cash');
            $this->fail("Status {$status} should reject payments.");
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, Payment::count());
        $this->assertSame($status, $invoice->fresh()->status);
    }

    #[DataProvider('payableStatuses')]
    public function test_payment_accepted_for_open_status(string $status): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $invoice = InvoiceFactory::new()->create([
            'company_id' => $user->company_id,
            'status' => $status,
            'total' => 100000,
            'paid_amount' => $status === 'partial' ? 30000 : 0,
        ]);

        BillingService::recordPayment($invoice, 1000, 'cash');

        $this->assertSame(1, Payment::count());
    }
}
