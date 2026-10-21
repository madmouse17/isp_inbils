<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Services\Core\CompanyService;
use App\Support\OptimisticLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Database\Factories\PaymentAttemptFactory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentAttempt;
use Modules\Billing\Services\BillingService;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/**
 * F5-D04: a draft with nothing to bill cannot be sent, and the buttons follow what the user may do. The person who
 * drafts an invoice (billing.create) composes it without billing.update, which stays the key to issued invoices.
 */
class InvoiceSendGuardTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $user->givePermissionTo($permissions);
        $this->grantPermissions($user, ['app.web']);

        return $user;
    }

    private function draft(string $status = 'draft', ?User $maker = null): Invoice
    {
        return InvoiceFactory::new()->create([
            'company_id' => $this->admin->company_id,
            'status' => $status,
            'created_by' => ($maker ?? $this->admin)->id,
        ]);
    }

    public function test_empty_or_zero_draft_cannot_be_sent(): void
    {
        $this->actingAs($this->admin);
        $empty = $this->draft();
        $zero = $this->draft();
        BillingService::addItem($zero, ['description' => 'Gratis', 'quantity' => 1, 'unit_price' => 0]);
        $worth = $this->draft();
        BillingService::addItem($worth, ['description' => 'Kabel', 'quantity' => 1, 'unit_price' => 50000]);

        foreach ([$empty, $zero] as $unsendable) {
            $this->postJson(route('admin.invoices.send', $unsendable))
                ->assertUnprocessable()
                ->assertJsonPath('message', 'Tagihan tanpa item atau bernilai Rp 0 tidak bisa dikirim.');
            $this->assertSame('draft', $unsendable->fresh()->status);
            $this->assertNull($unsendable->fresh()->sent_at);
        }

        $this->post(route('admin.invoices.send', $worth))->assertRedirect()->assertSessionHas('success', 'Tagihan dikirim.');
        $this->assertSame('sent', $worth->fresh()->status);
    }

    public function test_creator_can_add_items_to_own_draft_without_billing_update(): void
    {
        $maker = $this->userWith(['billing.view', 'billing.create']);
        $draft = $this->draft('draft', $maker);
        $this->assertFalse($maker->can('billing.update'));

        $this->actingAs($maker)
            ->post(route('admin.invoices.items.store', $draft), ['description' => 'Kabel', 'quantity' => 2, 'unit_price' => 25000])
            ->assertRedirect()
            ->assertSessionHas('success', 'Item ditambahkan.');

        $item = $draft->items()->sole();
        $this->assertSame('55500.00', $draft->fresh()->total, '50.000 and the 11% PPN of the company, which the line takes whatever a request names');

        $this->delete(route('admin.invoices.items.destroy', [$draft, $item]))->assertRedirect()->assertSessionHas('success', 'Item dihapus.');
        $this->assertSame(0, $draft->items()->count());
    }

    public function test_billing_create_alone_opens_no_issued_invoice_and_view_alone_opens_no_draft(): void
    {
        $maker = $this->userWith(['billing.view', 'billing.create']);
        $viewer = $this->userWith(['billing.view']);
        $sent = $this->draft('sent', $maker);
        $draft = $this->draft('draft', $maker);

        $this->actingAs($maker)
            ->post(route('admin.invoices.items.store', $sent), ['description' => 'Kabel', 'quantity' => 1, 'unit_price' => 1000])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->post(route('admin.invoices.items.store', $draft), ['description' => 'Kabel', 'quantity' => 1, 'unit_price' => 1000])
            ->assertForbidden();

        $this->assertSame(0, $draft->items()->count() + $sent->items()->count());
    }

    public function test_a_maker_edits_own_draft_discount_and_lines_but_not_anothers_or_an_approval_draft(): void
    {
        $maker = $this->userWith(['billing.view', 'billing.create']);
        $own = $this->draft('draft', $maker);
        BillingService::addItem($own, ['description' => 'Kabel', 'quantity' => 1, 'unit_price' => 50000]);

        $this->actingAs($maker)
            ->put(route('admin.invoices.update', $own), ['discount_amount' => 10000, OptimisticLock::FIELD => OptimisticLock::version($own->fresh())])
            ->assertRedirect()
            ->assertSessionHas('success', 'Tagihan diperbarui.');
        $this->assertSame('10000.00', $own->fresh()->discount_amount);

        // The draft the approval of an installation SPK makes is the approver's, and a draft made by the system has no maker.
        $others = [$this->draft('draft'), $this->draft('draft', $this->userWith(['billing.view', 'billing.create']))];
        $others[] = tap($this->draft(), fn (Invoice $invoice) => $invoice->forceFill(['created_by' => null])->save());

        foreach ($others as $draft) {
            $this->put(route('admin.invoices.update', $draft), ['discount_amount' => 50000, OptimisticLock::FIELD => OptimisticLock::version($draft->fresh())])->assertForbidden();
            $this->post(route('admin.invoices.items.store', $draft), ['description' => 'Kabel', 'quantity' => 1, 'unit_price' => 1000])->assertForbidden();
            $this->assertSame('0.00', $draft->fresh()->discount_amount);
            $this->assertSame(0, $draft->items()->count());
        }
    }

    public function test_show_exposes_can_flags(): void
    {
        $maker = $this->userWith(['billing.view', 'billing.create', 'billing.send']);
        $draft = $this->draft('draft', $maker);
        $sent = $this->draft('sent', $maker);

        $this->actingAs($maker)->get(route('admin.invoices.show', $draft))->assertInertia(fn ($page) => $page
            ->where('can', ['update' => true, 'send' => true, 'cancel' => false, 'record_payment' => false, 'reverse_payment' => false, 'refund' => false, 'receipt' => false]));
        $this->get(route('admin.invoices.show', $sent))->assertInertia(fn ($page) => $page->where('can.update', false));

        $this->actingAs($this->admin)->get(route('admin.invoices.show', $sent))->assertInertia(fn ($page) => $page
            ->where('can', ['update' => true, 'send' => true, 'cancel' => true, 'record_payment' => true, 'reverse_payment' => true, 'refund' => true, 'receipt' => true]));
    }

    public function test_receivables_expose_attempt_ids_the_refund_flag_and_the_reactivation_rule(): void
    {
        $invoice = $this->draft('sent');
        $attempt = PaymentAttemptFactory::new()->forInvoice($invoice)->status(PaymentAttempt::STATUS_EXCEPTION)->create(['amount' => 100000]);
        $viewer = $this->userWith(['billing.view']);

        $this->actingAs($this->admin)->get(route('admin.billing.receivables'))->assertInertia(fn ($page) => $page
            ->where('paymentExceptions.items.0.id', $attempt->public_id)
            ->where('can.refund', true)
            ->where('autoReactivate', true));

        $this->actingAs($viewer)->get(route('admin.billing.receivables'))->assertInertia(fn ($page) => $page->where('can.refund', false));

        $company = $this->admin->company;
        $company->update(['settings' => [...($company->settings ?? []), 'auto_reactivate_on_payment' => false]]);
        // Within one test the company is cached per process; a real request reads it fresh.
        CompanyService::resetCache();
        CompanyService::useCompanyForTests((int) $company->id);

        $this->actingAs($this->admin)->get(route('admin.billing.receivables'))->assertInertia(fn ($page) => $page->where('autoReactivate', false));
    }
}
