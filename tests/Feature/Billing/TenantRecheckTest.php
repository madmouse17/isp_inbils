<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Inventory\Item;
use App\Models\Inventory\ItemCategory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Modules\Billing\Jobs\CheckOverdueInvoices;
use Modules\Billing\Jobs\GenerateRecurringInvoices;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\RecurringInvoice;
use Modules\Billing\Models\RecurringInvoiceItem;
use Modules\Billing\Services\BillingService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class TenantRecheckTest extends TestCase
{
    use RefreshDatabase;

    // ── cancel() tenant recheck ─────────────────────────────────────

    public function test_cancel_rejects_invoice_from_other_tenant(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $userA = User::factory()->create(['company_id' => $companyA->id]);
        $userB = User::factory()->create(['company_id' => $companyB->id]);

        Auth::login($userB);
        $invoiceB = BillingService::createInvoice(['type' => 'invoice', 'issue_date' => '2026-01-01']);

        Auth::login($userA);
        $this->expectException(NotFoundHttpException::class);
        BillingService::cancel($invoiceB, 'cross-tenant cancel attempt');
    }

    public function test_cancel_allows_own_tenant_invoice(): void
    {
        $companyA = Company::factory()->create();
        $userA = User::factory()->create(['company_id' => $companyA->id]);

        Auth::login($userA);
        $invoice = BillingService::createInvoice(['type' => 'invoice', 'issue_date' => '2026-01-01']);
        $result = BillingService::cancel($invoice, 'valid reason');

        $this->assertEquals('cancelled', $result->status);
    }

    // ── addItem() tenant recheck ─────────────────────────────────────

    public function test_add_item_rejects_invoice_from_other_tenant(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $userA = User::factory()->create(['company_id' => $companyA->id]);
        $userB = User::factory()->create(['company_id' => $companyB->id]);

        Auth::login($userB);
        $category = ItemCategory::factory()->create(['company_id' => $companyB->id]);
        $item = Item::factory()->create([
            'company_id' => $companyB->id,
            'category_id' => $category->id,
            'unit_price' => 100.00,
        ]);
        $invoiceB = BillingService::createInvoice(['type' => 'invoice', 'issue_date' => '2026-01-01']);

        Auth::login($userA);
        $this->expectException(NotFoundHttpException::class);
        BillingService::addItem($invoiceB, $item, 1);
    }

    // ── send() tenant recheck ────────────────────────────────────────

    public function test_send_rejects_invoice_from_other_tenant(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $userA = User::factory()->create(['company_id' => $companyA->id]);
        $userB = User::factory()->create(['company_id' => $companyB->id]);

        Auth::login($userB);
        $category = ItemCategory::factory()->create(['company_id' => $companyB->id]);
        $item = Item::factory()->create([
            'company_id' => $companyB->id,
            'category_id' => $category->id,
            'unit_price' => 50.00,
        ]);
        $invoiceB = BillingService::createInvoice(['type' => 'invoice', 'issue_date' => '2026-01-01']);
        BillingService::addItem($invoiceB, $item, 2);

        Auth::login($userA);
        $this->expectException(NotFoundHttpException::class);
        BillingService::send($invoiceB);
    }

    // ── Billing commands iterate all active companies ────────────────

    public function test_generate_recurring_command_processes_all_active_companies(): void
    {
        Carbon::setTestNow('2026-03-15');

        $companyA = Company::factory()->create(['is_active' => true]);
        $companyB = Company::factory()->create(['is_active' => true]);
        $userA = User::factory()->create(['company_id' => $companyA->id]);
        $userB = User::factory()->create(['company_id' => $companyB->id]);

        // Set up recurring invoice for company A
        Auth::login($userA);
        $categoryA = ItemCategory::factory()->create(['company_id' => $companyA->id]);
        $itemA = Item::factory()->create([
            'company_id' => $companyA->id,
            'category_id' => $categoryA->id,
            'unit_price' => 100.00,
        ]);
        $recurringA = RecurringInvoice::factory()->create([
            'company_id' => $companyA->id,
            'is_active' => true,
            'next_run_date' => '2026-03-15',
            'start_date' => '2026-01-01',
            'interval' => 'monthly',
            'interval_count' => 1,
        ]);
        RecurringInvoiceItem::factory()->create([
            'recurring_invoice_id' => $recurringA->id,
            'item_id' => $itemA->id,
            'description' => 'Service A',
            'quantity' => 1,
            'unit_price' => 100.00,
            'sort_order' => 1,
        ]);

        // Set up recurring invoice for company B
        Auth::login($userB);
        $categoryB = ItemCategory::factory()->create(['company_id' => $companyB->id]);
        $itemB = Item::factory()->create([
            'company_id' => $companyB->id,
            'category_id' => $categoryB->id,
            'unit_price' => 200.00,
        ]);
        $recurringB = RecurringInvoice::factory()->create([
            'company_id' => $companyB->id,
            'is_active' => true,
            'next_run_date' => '2026-03-15',
            'start_date' => '2026-01-01',
            'interval' => 'monthly',
            'interval_count' => 1,
        ]);
        RecurringInvoiceItem::factory()->create([
            'recurring_invoice_id' => $recurringB->id,
            'item_id' => $itemB->id,
            'description' => 'Service B',
            'quantity' => 1,
            'unit_price' => 200.00,
            'sort_order' => 1,
        ]);

        // Run the job without a specific company (should process all active)
        $job = new GenerateRecurringInvoices();
        $job->handle();

        // Both companies should have generated invoices
        $invoicesA = Invoice::withoutCompany()->where('company_id', $companyA->id)->get();
        $invoicesB = Invoice::withoutCompany()->where('company_id', $companyB->id)->get();

        $this->assertCount(1, $invoicesA, 'Company A should have 1 generated invoice');
        $this->assertCount(1, $invoicesB, 'Company B should have 1 generated invoice');

        // Verify the recurring invoices were advanced
        $recurringA->refresh();
        $recurringB->refresh();
        $this->assertEquals('2026-04-15', $recurringA->next_run_date->toDateString());
        $this->assertEquals('2026-04-15', $recurringB->next_run_date->toDateString());
    }

    public function test_check_overdue_command_processes_all_active_companies(): void
    {
        Carbon::setTestNow('2026-03-15');

        $companyA = Company::factory()->create(['is_active' => true]);
        $companyB = Company::factory()->create(['is_active' => true]);
        $userA = User::factory()->create(['company_id' => $companyA->id]);
        $userB = User::factory()->create(['company_id' => $companyB->id]);

        // Create overdue invoice for company A
        Auth::login($userA);
        $invoiceA = BillingService::createInvoice(['type' => 'invoice', 'issue_date' => '2026-01-01']);
        $invoiceA->update(['status' => 'sent', 'due_date' => '2026-03-01', 'total' => 100]);

        // Create overdue invoice for company B
        Auth::login($userB);
        $invoiceB = BillingService::createInvoice(['type' => 'invoice', 'issue_date' => '2026-01-01']);
        $invoiceB->update(['status' => 'sent', 'due_date' => '2026-03-01', 'total' => 200]);

        // Run the job without a specific company
        $job = new CheckOverdueInvoices();
        $job->handle();

        // Both should be marked overdue
        $invoiceA->refresh();
        $invoiceB->refresh();
        $this->assertEquals('overdue', $invoiceA->status);
        $this->assertEquals('overdue', $invoiceB->status);
    }

    public function test_check_overdue_command_with_specific_company_only_processes_that_company(): void
    {
        Carbon::setTestNow('2026-03-15');

        $companyA = Company::factory()->create(['is_active' => true]);
        $companyB = Company::factory()->create(['is_active' => true]);
        $userA = User::factory()->create(['company_id' => $companyA->id]);
        $userB = User::factory()->create(['company_id' => $companyB->id]);

        // Create overdue invoice for company A
        Auth::login($userA);
        $invoiceA = BillingService::createInvoice(['type' => 'invoice', 'issue_date' => '2026-01-01']);
        $invoiceA->update(['status' => 'sent', 'due_date' => '2026-03-01', 'total' => 100]);

        // Create overdue invoice for company B
        Auth::login($userB);
        $invoiceB = BillingService::createInvoice(['type' => 'invoice', 'issue_date' => '2026-01-01']);
        $invoiceB->update(['status' => 'sent', 'due_date' => '2026-03-01', 'total' => 200]);

        // Run the job for company A only
        $job = new CheckOverdueInvoices($companyA->id);
        $job->handle();

        $invoiceA->refresh();
        $invoiceB->refresh();
        $this->assertEquals('overdue', $invoiceA->status);
        $this->assertEquals('sent', $invoiceB->status, 'Company B invoice should not be processed');
    }
}
