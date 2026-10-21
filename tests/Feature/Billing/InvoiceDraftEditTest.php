<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use App\Support\OptimisticLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceItem;
use Modules\Billing\Services\BillingService;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/** F7-D26: a draft has its own page for what it still lets staff change: the due date, the discount and the note. */
class InvoiceDraftEditTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = $this->createCompanyUser();
        $this->customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
    }

    /** A draft of one line of 100.000 and a PPN of 10.000: a discount may take 110.000 at most. */
    private function draft(string $status = 'draft'): Invoice
    {
        $invoice = Invoice::factory()->create([
            'company_id' => $this->admin->company_id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->admin->id,
            'status' => $status,
            'issue_date' => '2026-10-01',
            'due_date' => '2026-10-15',
            'notes' => 'catatan awal',
        ]);
        InvoiceItem::query()->create(['company_id' => $invoice->company_id, 'invoice_id' => $invoice->id, 'description' => 'Jasa', 'quantity' => 1, 'unit_price' => 100000, 'tax_rate' => 10, 'line_total' => 100000]);
        BillingService::recalculate($invoice);
        // A save made now is another version of the draft (versions keep whole seconds).
        $invoice->forceFill(['updated_at' => now()->subMinutes(5)])->saveQuietly();

        return $invoice->fresh();
    }

    /** @return array<string, mixed> */
    private function save(Invoice $invoice, array $fields): array
    {
        return $fields + [OptimisticLock::FIELD => OptimisticLock::version($invoice)];
    }

    public function test_the_page_opens_for_a_draft_with_its_note_and_the_version_the_save_will_send_back(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->admin)
            ->get(route('admin.invoices.edit', $invoice))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Billing/Invoices/Edit')
                ->where('invoice.data.number', $invoice->number)
                ->where('invoice.data.notes', 'catatan awal')
                ->where('invoice.data.customer.id', $this->customer->public_id)
                ->where('version', OptimisticLock::version($invoice))
                ->has('invoice.data.items'));
    }

    public function test_an_issued_invoice_goes_back_to_its_page_with_the_reason(): void
    {
        foreach (['sent', 'paid', 'cancelled'] as $status) {
            $invoice = $this->draft($status);

            $this->actingAs($this->admin)
                ->get(route('admin.invoices.edit', $invoice))
                ->assertRedirect(route('admin.invoices.show', $invoice))
                ->assertSessionHas('error', 'Hanya tagihan draf yang bisa diubah.');
        }
    }

    public function test_the_page_is_for_whoever_may_update_and_for_the_maker_of_the_draft(): void
    {
        $invoice = $this->draft();
        $maker = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantPermissions($maker, ['app.web', 'billing.view', 'billing.create']);
        $viewer = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantPermissions($viewer, ['app.web', 'billing.view']);

        $this->actingAs($viewer)->get(route('admin.invoices.edit', $invoice))->assertForbidden();
        // Billing.create alone opens only the maker's own draft: this one was made by the admin.
        $this->actingAs($maker)->get(route('admin.invoices.edit', $invoice))->assertForbidden();

        $invoice->forceFill(['created_by' => $maker->id])->saveQuietly();
        $this->actingAs($maker)->get(route('admin.invoices.edit', $invoice))->assertOk();
    }

    public function test_another_companys_draft_is_not_found(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $foreign = Invoice::factory()->create(['company_id' => $other->id, 'customer_id' => Customer::factory()->create(['company_id' => $other->id])->id, 'status' => 'draft']);

        $this->actingAs($this->admin)->get(route('admin.invoices.edit', $foreign))->assertNotFound();
    }

    public function test_a_save_writes_the_fields_and_audits_which_ones_changed_without_their_content(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->admin)
            ->put(route('admin.invoices.update', $invoice), $this->save($invoice, ['due_date' => '2026-10-31', 'discount_amount' => 10000, 'notes' => 'rahasia: pelanggan menunggak']))
            ->assertRedirect(route('admin.invoices.index'))
            ->assertSessionHas('success', 'Tagihan diperbarui.');

        $saved = $invoice->fresh();
        $this->assertSame('2026-10-31', $saved->due_date->toDateString());
        $this->assertSame('10000.00', $saved->discount_amount);
        $this->assertSame('rahasia: pelanggan menunggak', $saved->notes);

        $row = Activity::query()->where('log_name', 'invoice')->where('description', 'draft_updated')->sole();
        $this->assertSame($invoice->id, $row->subject_id);
        $this->assertSame($this->admin->id, $row->causer_id);
        $this->assertEqualsCanonicalizing(['due_date', 'discount_amount', 'notes'], $row->properties['changed']);
        $this->assertSame($invoice->number, $row->properties['number']);
        $this->assertStringNotContainsString('rahasia', (string) json_encode($row->properties), 'the row names the fields, never their text');
    }

    public function test_a_save_that_changes_nothing_audits_nothing(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->admin)
            ->put(route('admin.invoices.update', $invoice), $this->save($invoice, ['due_date' => '2026-10-15', 'discount_amount' => 0, 'notes' => 'catatan awal']))
            ->assertRedirect(route('admin.invoices.index'));

        $this->assertSame(0, Activity::query()->where('description', 'draft_updated')->count());
    }

    public function test_only_the_fields_sent_are_audited(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->admin)->put(route('admin.invoices.update', $invoice), $this->save($invoice, ['notes' => 'catatan baru']))->assertRedirect();

        $this->assertSame(['notes'], array_values(Activity::query()->where('description', 'draft_updated')->sole()->properties['changed']));
    }

    public function test_a_cleared_due_date_or_discount_is_refused_not_stored_as_nothing(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->admin)
            ->putJson(route('admin.invoices.update', $invoice), $this->save($invoice, ['due_date' => '', 'discount_amount' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['due_date' => 'Jatuh tempo wajib diisi.', 'discount_amount' => 'Diskon harus berupa angka.']);

        $this->putJson(route('admin.invoices.update', $invoice), $this->save($invoice, ['discount_amount' => -5]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['discount_amount' => 'Diskon tidak boleh negatif.']);

        $this->putJson(route('admin.invoices.update', $invoice), $this->save($invoice, ['due_date' => '2026-09-30']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['due_date' => 'Jatuh tempo tidak boleh sebelum tanggal terbit.']);

        $saved = $invoice->fresh();
        $this->assertSame('2026-10-15', $saved->due_date->toDateString());
        $this->assertSame('0.00', $saved->discount_amount);
    }

    public function test_a_discount_above_the_subtotal_and_tax_is_refused_on_its_own_field_and_nothing_is_written(): void
    {
        $invoice = $this->draft();

        foreach (['110000.01', 99999999] as $tooMuch) {
            $this->actingAs($this->admin)
                ->putJson(route('admin.invoices.update', $invoice), $this->save($invoice, ['discount_amount' => $tooMuch, 'notes' => 'tidak boleh tersimpan']))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['discount_amount']);
        }

        $saved = $invoice->fresh();
        $this->assertSame(['0.00', '110000.00', 'catatan awal'], [$saved->discount_amount, $saved->total, $saved->notes], 'the refused save wrote neither the discount nor the note');
        $this->assertSame(0, Activity::query()->where('description', 'draft_updated')->count());

        // Everything the invoice comes to is the most it can take: nothing is left to pay, and the send guard refuses that on its own.
        $this->put(route('admin.invoices.update', $invoice), $this->save($invoice, ['discount_amount' => '110000.00']))->assertRedirect();
        $this->assertSame('110000.00', $invoice->fresh()->discount_amount);
    }

    public function test_a_discount_the_column_cannot_hold_and_one_with_fractions_of_a_cent_are_validation_errors_not_a_500(): void
    {
        $invoice = $this->draft();

        foreach (['1e14', '100000000000000', '100000000001', '10.555'] as $bad) {
            $this->actingAs($this->admin)
                ->putJson(route('admin.invoices.update', $invoice), $this->save($invoice, ['discount_amount' => $bad]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['discount_amount']);
        }

        $this->assertSame('0.00', $invoice->fresh()->discount_amount);
    }

    public function test_a_note_is_at_most_five_thousand_characters(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->admin)
            ->putJson(route('admin.invoices.update', $invoice), $this->save($invoice, ['notes' => str_repeat('a', 5001)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['notes']);
        $this->assertSame('catatan awal', $invoice->fresh()->notes);

        $this->put(route('admin.invoices.update', $invoice), $this->save($invoice, ['notes' => str_repeat('a', 5000)]))->assertRedirect();
        $this->assertSame(5000, mb_strlen($invoice->fresh()->notes));
    }

    public function test_a_draft_saved_by_someone_else_meanwhile_is_a_conflict_and_the_page_shows_why(): void
    {
        $invoice = $this->draft();
        $rendered = OptimisticLock::version($invoice);

        $this->actingAs($this->admin)->put(route('admin.invoices.update', $invoice), ['notes' => 'kolega', OptimisticLock::FIELD => $rendered])->assertRedirect();

        $this->withHeaders(['X-Inertia' => 'true'])
            ->put(route('admin.invoices.update', $invoice), ['notes' => 'saya', OptimisticLock::FIELD => $rendered])
            ->assertStatus(303)
            ->assertSessionHasErrors(['conflict' => OptimisticLock::MESSAGE]);

        $this->assertSame('kolega', $invoice->fresh()->notes);
    }

    public function test_the_draft_page_links_to_the_edit_page_only_for_whoever_may_update(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->admin)
            ->get(route('admin.invoices.show', $invoice))
            ->assertInertia(fn (Assert $page) => $page->where('can.update', true)->where('invoice.data.status', 'draft'));
    }
}
