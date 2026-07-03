<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\BillingService;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class CheckOverdueTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCompanyUser;

    public function test_only_past_due_sent_or_partial_become_overdue(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);

        $pastDueSent = InvoiceFactory::new()->create([
            'status' => 'sent', 'due_date' => now()->subDays(3), 'total' => 100, 'paid_amount' => 0,
        ]);
        $futureSent = InvoiceFactory::new()->create([
            'status' => 'sent', 'due_date' => now()->addDays(3), 'total' => 100, 'paid_amount' => 0,
        ]);
        $pastDuePartial = InvoiceFactory::new()->create([
            'status' => 'partial', 'due_date' => now()->subDays(3), 'total' => 100, 'paid_amount' => 50,
        ]);
        $pastDueDraft = InvoiceFactory::new()->create([
            'status' => 'draft', 'due_date' => now()->subDays(3), 'total' => 100, 'paid_amount' => 0,
        ]);

        BillingService::checkOverdue();

        $this->assertSame('overdue', $pastDueSent->fresh()->status);
        $this->assertSame('sent', $futureSent->fresh()->status);
        $this->assertSame('overdue', $pastDuePartial->fresh()->status);
        $this->assertSame('draft', $pastDueDraft->fresh()->status);
    }
}
