<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Customer;
use App\Models\User;
use App\Support\OptimisticLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Invoice;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\RunsConcurrentWorkers;

/**
 * A draft invoice is edited and deleted under its row lock: a save made from an older copy is refused, and a request that
 * waited for the draft to be sent finds it sent and changes nothing.
 */
class InvoiceDraftLockTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;
    use RunsConcurrentWorkers;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
        $this->customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
    }

    /** A draft last saved five minutes ago, so a save made now is a different version of it (versions keep whole seconds). */
    private function draft(string $status = 'draft'): Invoice
    {
        $invoice = Invoice::factory()->create([
            'company_id' => $this->admin->company_id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->admin->id,
            'status' => $status,
            'notes' => 'as rendered',
            // A draft with no total cannot be sent (F5-D04), and the races below send it.
            'total' => 100000,
        ]);
        DB::table('invoices')->where('id', $invoice->id)->update(['updated_at' => now()->subMinutes(5)]);

        return $invoice->fresh();
    }

    public function test_a_draft_save_from_an_older_copy_is_refused(): void
    {
        $invoice = $this->draft();
        $rendered = OptimisticLock::version($invoice);

        $this->actingAs($this->admin)
            ->put(route('admin.invoices.update', $invoice), ['notes' => 'first save', 'expected_updated_at' => $rendered])
            ->assertRedirect(route('admin.invoices.index'))
            ->assertSessionHas('success', 'Tagihan diperbarui.');

        $this->putJson(route('admin.invoices.update', $invoice), ['notes' => 'second save', 'expected_updated_at' => $rendered])
            ->assertStatus(409)
            ->assertJsonPath('error', 'conflict')
            ->assertJsonPath('message', OptimisticLock::MESSAGE);

        // A page visit (X-Inertia) gets the redirect back with the message the toast and the banner show.
        $this->withHeaders(['X-Inertia' => 'true'])
            ->put(route('admin.invoices.update', $invoice), ['notes' => 'third save', 'expected_updated_at' => $rendered])
            ->assertStatus(303)
            ->assertSessionHasErrors(['conflict' => OptimisticLock::MESSAGE]);

        $this->assertSame('first save', $invoice->fresh()->notes, 'the refused saves changed nothing');
    }

    public function test_a_save_needs_the_version_the_form_was_rendered_from(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->admin)
            ->putJson(route('admin.invoices.update', $invoice), ['notes' => 'no version'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(OptimisticLock::FIELD);

        $this->putJson(route('admin.invoices.update', $invoice), ['notes' => 'bad version', OptimisticLock::FIELD => '2026-10-18'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(OptimisticLock::FIELD);

        $this->assertSame('as rendered', $invoice->fresh()->notes);
    }

    public function test_a_save_recalculates_the_totals_inside_the_same_write(): void
    {
        $invoice = $this->draft();
        $this->actingAs($this->admin)->post(route('admin.invoices.items.store', $invoice), ['description' => 'Kabel', 'quantity' => 2, 'unit_price' => 50000, 'tax_rate' => 10])->assertRedirect();
        $invoice = $invoice->fresh();
        DB::table('invoices')->where('id', $invoice->id)->update(['updated_at' => now()->subMinutes(5)]);

        $this->put(route('admin.invoices.update', $invoice), ['discount_amount' => 10000, OptimisticLock::FIELD => OptimisticLock::version($invoice->fresh())])->assertRedirect();

        $saved = $invoice->fresh();
        $this->assertSame('10000.00', $saved->discount_amount);
        $this->assertSame('100000.00', $saved->subtotal);
        $this->assertSame('10000.00', $saved->tax_amount);
        $this->assertSame('100000.00', $saved->total, '100000 + 10000 tax - 10000 discount');
    }

    public function test_a_draft_is_deleted_and_a_sent_invoice_is_neither_saved_nor_deleted(): void
    {
        $draft = $this->draft();
        $sent = $this->draft('sent');

        $this->actingAs($this->admin)
            ->putJson(route('admin.invoices.update', $sent), ['notes' => 'late edit', OptimisticLock::FIELD => OptimisticLock::version($sent)])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Hanya tagihan draf yang bisa diubah.');
        $this->deleteJson(route('admin.invoices.destroy', $sent))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Hanya tagihan draf yang bisa dihapus.');
        $this->delete(route('admin.invoices.destroy', $draft))->assertRedirect()->assertSessionHas('success', 'Tagihan dihapus.');

        $this->assertSame('as rendered', $sent->fresh()->notes);
        $this->assertNotSoftDeleted($sent);
        $this->assertSoftDeleted($draft);
    }

    public function test_two_saves_racing_on_one_draft_let_exactly_one_win(): void
    {
        $invoice = $this->draft();
        $rendered = OptimisticLock::version($invoice);
        $this->commitFixture();

        try {
            $results = $this->race([
                ['company_id' => $invoice->company_id, 'invoice_id' => $invoice->id, 'expected' => $rendered, 'notes' => 'saved by A'],
                ['company_id' => $invoice->company_id, 'invoice_id' => $invoice->id, 'expected' => $rendered, 'notes' => 'saved by B'],
            ], <<<'PHP'
                config(['logging.default' => 'null']);

                Modules\Billing\Services\BillingService::update(
                    Modules\Billing\Models\Invoice::query()->findOrFail($args['invoice_id']),
                    ['notes' => $args['notes']],
                    $args['expected'],
                );

                return ['saved' => $args['notes']];
            PHP);

            $saved = array_values(array_filter($results, fn (array $result): bool => $result['ok']));
            $refused = array_values(array_filter($results, fn (array $result): bool => ! $result['ok']));

            $this->assertCount(1, $saved, json_encode($results));
            $this->assertCount(1, $refused, json_encode($results));
            $this->assertSame(409, $refused[0]['status']);
            $this->assertSame(OptimisticLock::MESSAGE, $refused[0]['message']);
            $this->assertSame($saved[0]['result']['saved'], Invoice::withoutCompany()->findOrFail($invoice->id)->notes, 'the winner is what the row holds');
        } finally {
            $this->purgeCompany($invoice->company_id);
        }
    }

    public function test_an_update_that_waited_for_send_is_refused(): void
    {
        $invoice = $this->draft();
        $rendered = OptimisticLock::version($invoice);
        $this->commitFixture();

        try {
            $results = $this->raceAgainstASender($invoice, <<<'PHP'
                Modules\Billing\Services\BillingService::update($draft, ['discount_amount' => 50000], $args['expected']);

                return ['updated' => true];
            PHP, $rendered);

            $this->assertSame(['sent' => true], $results[0]['result']);
            $this->assertFalse($results[1]['ok'], json_encode($results));
            $this->assertSame(422, $results[1]['status']);
            $this->assertSame('Hanya tagihan draf yang bisa diubah.', $results[1]['message']);

            $row = Invoice::withoutCompany()->findOrFail($invoice->id);
            $this->assertSame('sent', $row->status);
            $this->assertSame('0.00', $row->discount_amount, 'no discount lands on an invoice that was already sent');
            $this->assertSame('100000.00', $row->total, 'the total was not recalculated from the discount either');
        } finally {
            $this->purgeCompany($invoice->company_id);
        }
    }

    public function test_a_delete_that_waited_for_send_is_refused(): void
    {
        $invoice = $this->draft();
        $this->commitFixture();

        try {
            $results = $this->raceAgainstASender($invoice, <<<'PHP'
                Modules\Billing\Services\BillingService::destroyDraft($draft);

                return ['deleted' => true];
            PHP);

            $this->assertSame(['sent' => true], $results[0]['result']);
            $this->assertFalse($results[1]['ok'], json_encode($results));
            $this->assertSame(422, $results[1]['status']);
            $this->assertSame('Hanya tagihan draf yang bisa dihapus.', $results[1]['message']);

            $row = Invoice::withoutCompany()->withTrashed()->findOrFail($invoice->id);
            $this->assertSame('sent', $row->status);
            $this->assertNull($row->deleted_at, 'a sent invoice is never removed by a request that saw it as a draft');
        } finally {
            $this->purgeCompany($invoice->company_id);
        }
    }

    /**
     * Worker 0 locks the draft, sends it and holds the lock for 800 ms. Worker 1 loads the draft first, starts 200 ms
     * later and runs $change with that stale copy in $draft.
     *
     * @return list<array{ok: bool, status?: int, result?: mixed, message?: string}>
     */
    private function raceAgainstASender(Invoice $invoice, string $change, ?string $expected = null): array
    {
        $payload = ['company_id' => $invoice->company_id, 'invoice_id' => $invoice->id, 'expected' => $expected];

        return $this->race([
            [...$payload, 'role' => 'sender'],
            [...$payload, 'role' => 'changer'],
        ], <<<PHP
            config(['logging.default' => 'null']);

            if (\$args['role'] === 'sender') {
                Illuminate\\Support\\Facades\\DB::transaction(function () use (\$args) {
                    Modules\\Billing\\Models\\Invoice::query()->whereKey(\$args['invoice_id'])->lockForUpdate()->firstOrFail();
                    Modules\\Billing\\Services\\BillingService::send(Modules\\Billing\\Models\\Invoice::query()->findOrFail(\$args['invoice_id']));
                    usleep(800000);
                });

                return ['sent' => true];
            }

            \$draft = Modules\\Billing\\Models\\Invoice::query()->findOrFail(\$args['invoice_id']);
            usleep(200000);

            {$change}
            PHP);
    }
}
