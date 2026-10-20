<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Database\Factories\PaymentAttemptFactory;
use Modules\Billing\Models\Invoice;
use Tests\TestCase;
use Tests\Traits\AsksInertiaForProps;
use Tests\Traits\CreatesCompanyUser;

/**
 * F5-U13: `?online_pending=1` lists the invoices whose Snap page was opened over an hour ago and is still open, the
 * same rows the dashboard card counts. The index and the export share the filter.
 */
class InvoiceOnlinePendingFilterTest extends TestCase
{
    use AsksInertiaForProps;
    use CreatesCompanyUser;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
        $this->actingAs($this->admin);
        $this->travelTo('2026-10-20 12:00:00');
    }

    private function invoice(string $number, ?Company $company = null): Invoice
    {
        $companyId = $company->id ?? $this->admin->company_id;

        return InvoiceFactory::new()->create([
            'company_id' => $companyId,
            'customer_id' => Customer::factory()->create(['company_id' => $companyId])->id,
            'number' => $number,
            'status' => 'sent',
            'total' => 100000,
        ]);
    }

    /** A page for the invoice, opened at `$openedAt` and open until `$expiresAt`. */
    private function page(Invoice $invoice, string $openedAt, string $status = 'pending', string $expiresAt = '2026-10-21 12:00:00'): void
    {
        PaymentAttemptFactory::new()->forInvoice($invoice)->status($status)->create(['created_at' => $openedAt, 'expires_at' => $expiresAt]);
    }

    /** @return list<string> the invoice numbers the index lists for the query, sorted */
    private function listed(string $query = 'online_pending=1'): array
    {
        $response = $this->get('/admin/invoices?'.$query, $this->partialReload('Admin/Billing/Invoices/Index', 'invoices'))->assertOk();

        return collect($response->json('props.invoices.data'))->pluck('number')->sort()->values()->all();
    }

    public function test_lists_invoices_with_pending_attempt_older_than_an_hour(): void
    {
        $this->page($this->invoice('INV-STALE'), '2026-10-20 09:00:00');
        // Exactly an hour is stale; a second less is not.
        $this->page($this->invoice('INV-EDGE'), '2026-10-20 11:00:00');
        $this->page($this->invoice('INV-JUST-UNDER'), '2026-10-20 11:00:01');
        $this->invoice('INV-NO-PAGE');

        $this->assertSame(['INV-EDGE', 'INV-STALE'], $this->listed());
        $this->get('/admin/invoices?online_pending=1', $this->partialReload('Admin/Billing/Invoices/Index', 'invoices,filters'))
            ->assertJsonPath('props.filters.online_pending', '1')
            ->assertJsonPath('props.invoices.meta.total', 2);
    }

    public function test_recent_or_expired_pending_is_not_listed(): void
    {
        $this->page($this->invoice('INV-RECENT'), '2026-10-20 11:30:00');
        // The page ran out an hour ago: nobody can pay on it any more.
        $this->page($this->invoice('INV-RAN-OUT'), '2026-10-19 10:00:00', expiresAt: '2026-10-20 11:00:00');
        foreach (['paid', 'failed', 'expired', 'exception', 'cancelled_locally', 'refunded'] as $status) {
            $this->page($this->invoice('INV-STATUS-'.strtoupper($status)), '2026-10-20 08:00:00', $status);
        }

        $this->assertSame([], $this->listed());
    }

    public function test_an_invoice_with_several_old_pages_is_listed_once(): void
    {
        $invoice = $this->invoice('INV-TWICE');
        $this->page($invoice, '2026-10-20 08:00:00');
        $this->page($invoice, '2026-10-20 09:00:00');

        $this->assertSame(['INV-TWICE'], $this->listed());
    }

    public function test_the_filter_stays_inside_the_company(): void
    {
        $this->page($this->invoice('INV-MINE'), '2026-10-20 09:00:00');
        $this->page($this->invoice('INV-THEIRS', Company::factory()->create(['is_active' => true])), '2026-10-20 09:00:00');

        $this->assertSame(['INV-MINE'], $this->listed());
    }

    public function test_it_combines_with_the_other_filters(): void
    {
        $this->page($this->invoice('INV-AAA'), '2026-10-20 09:00:00');
        $this->page($this->invoice('INV-BBB'), '2026-10-20 09:00:00');

        $this->assertSame(['INV-BBB'], $this->listed('online_pending=1&search=BBB'));
    }

    public function test_other_values_are_not_a_filter(): void
    {
        $this->invoice('INV-PLAIN');
        $this->page($this->invoice('INV-STALE'), '2026-10-20 09:00:00');

        $this->assertSame(['INV-PLAIN', 'INV-STALE'], $this->listed('online_pending=0'));
        $this->assertSame(['INV-PLAIN', 'INV-STALE'], $this->listed(''));
        $this->get('/admin/invoices?online_pending=0', $this->partialReload('Admin/Billing/Invoices/Index', 'filters'))
            ->assertJsonPath('props.filters.online_pending', null);
    }

    public function test_export_uses_the_same_filter(): void
    {
        $this->page($this->invoice('INV-EXPORT-STALE'), '2026-10-20 09:00:00');
        $this->page($this->invoice('INV-EXPORT-RECENT'), '2026-10-20 11:45:00');
        $this->invoice('INV-EXPORT-NO-PAGE');

        $csv = $this->get('/admin/invoices/export?format=csv&online_pending=1')->assertOk()->streamedContent();

        $this->assertStringContainsString('INV-EXPORT-STALE', $csv);
        $this->assertStringNotContainsString('INV-EXPORT-RECENT', $csv);
        $this->assertStringNotContainsString('INV-EXPORT-NO-PAGE', $csv);
    }
}
