<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\BillingService;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class ReceivablesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCompanyUser;

    public function test_buckets_computed_per_customer(): void
    {
        $this->actingAs($this->createCompanyUser());
        $customer = Customer::factory()->create(['name' => 'Budi']);

        Invoice::factory()->create(['customer_id' => $customer->id, 'status' => 'sent', 'due_date' => now()->addDays(5), 'total' => 100000, 'paid_amount' => 0]);
        Invoice::factory()->create(['customer_id' => $customer->id, 'status' => 'overdue', 'due_date' => now()->subDays(10), 'total' => 200000, 'paid_amount' => 0]);
        Invoice::factory()->create(['customer_id' => $customer->id, 'status' => 'partial', 'due_date' => now()->subDays(45), 'total' => 250000, 'paid_amount' => 100000]);
        Invoice::factory()->create(['customer_id' => $customer->id, 'status' => 'overdue', 'due_date' => now()->subDays(100), 'total' => 50000, 'paid_amount' => 0]);
        Invoice::factory()->create(['customer_id' => $customer->id, 'status' => 'paid', 'due_date' => now()->subDays(10), 'total' => 99999, 'paid_amount' => 99999]);

        $rows = BillingService::receivables();

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('Budi', $row['customer']);
        $this->assertEquals(100000.0, $row['current']);
        $this->assertEquals(200000.0, $row['b1_30']);
        $this->assertEquals(150000.0, $row['b31_60']);
        $this->assertEquals(0.0, $row['b61_90']);
        $this->assertEquals(50000.0, $row['b90_plus']);
        $this->assertEquals(500000.0, $row['total']);
        $this->assertSame(4, $row['invoice_count']);
    }

    public function test_page_renders(): void
    {
        $this->actingAs($this->createCompanyUser());

        $this->get(route('admin.billing.receivables'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Billing/Receivables'));
    }
}
