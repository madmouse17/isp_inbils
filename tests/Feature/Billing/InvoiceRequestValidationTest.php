<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Customer;
use App\Models\User;
use App\Support\OptimisticLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class InvoiceRequestValidationTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createCompanyUser();
        $this->customer = Customer::factory()->create(['company_id' => $this->user->company_id]);
    }

    private function draft(string $status = 'draft'): Invoice
    {
        return Invoice::factory()->create([
            'company_id' => $this->user->company_id,
            'customer_id' => $this->customer->id,
            'status' => $status,
        ]);
    }

    /** @return array<string, array{0: string}> */
    public static function notIsoDates(): array
    {
        return [
            'day first' => ['14/07/2026'],
            'dotted' => ['14.07.2026'],
            'with time' => ['2026-07-14 10:00:00'],
            'text' => ['besok'],
        ];
    }

    #[DataProvider('notIsoDates')]
    public function test_store_rejects_issue_and_due_dates_that_are_not_y_m_d(string $value): void
    {
        $this->actingAs($this->user)
            ->postJson(route('admin.invoices.store'), [
                'customer_id' => $this->customer->public_id,
                'issue_date' => $value,
                'due_date' => $value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['issue_date', 'due_date'])
            ->assertJsonPath('errors.issue_date.0', 'Format tanggal terbit harus tahun-bulan-tanggal (YYYY-MM-DD).')
            ->assertJsonPath('errors.due_date.0', 'Format jatuh tempo harus tahun-bulan-tanggal (YYYY-MM-DD).');
    }

    public function test_store_accepts_iso_dates(): void
    {
        $this->actingAs($this->user)
            ->post(route('admin.invoices.store'), [
                'customer_id' => $this->customer->public_id,
                'issue_date' => '2026-07-01',
                'due_date' => '2026-07-31',
            ])
            ->assertRedirect(route('admin.invoices.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Tagihan dibuat.');

        $this->assertDatabaseHas('invoices', ['customer_id' => $this->customer->id, 'status' => 'draft']);
    }

    public function test_store_requires_a_customer_with_an_indonesian_message(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('admin.invoices.store'), [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.customer_id.0', 'Pelanggan wajib dipilih.');
    }

    public function test_update_rejects_a_due_date_that_is_not_y_m_d_and_accepts_iso(): void
    {
        $invoice = $this->draft();
        $version = OptimisticLock::version($invoice->fresh());

        $this->actingAs($this->user)
            ->putJson(route('admin.invoices.update', $invoice), ['due_date' => '31/07/2026', OptimisticLock::FIELD => $version])
            ->assertUnprocessable()
            ->assertJsonPath('errors.due_date.0', 'Format jatuh tempo harus tahun-bulan-tanggal (YYYY-MM-DD).');

        $this->actingAs($this->user)
            ->put(route('admin.invoices.update', $invoice), ['due_date' => '2026-07-31', OptimisticLock::FIELD => $version])
            ->assertRedirect(route('admin.invoices.index'))
            ->assertSessionHas('success', 'Tagihan diperbarui.');
    }

    /** @return array<string, array{0: string}> */
    public static function notYearMonth(): array
    {
        return [
            'full date' => ['2026-07-01'],
            'slash' => ['2026/07'],
            'month first' => ['07-2026'],
            'text' => ['juli'],
        ];
    }

    #[DataProvider('notYearMonth')]
    public function test_generate_and_preview_reject_a_period_that_is_not_y_m(string $period): void
    {
        $this->actingAs($this->user)
            ->postJson(route('admin.invoices.generate'), ['period' => $period])
            ->assertUnprocessable()
            ->assertJsonPath('errors.period.0', 'Format periode harus tahun-bulan (YYYY-MM).');

        $this->actingAs($this->user)
            ->postJson(route('admin.invoices.generate-preview'), ['period' => $period])
            ->assertUnprocessable()
            ->assertJsonPath('errors.period.0', 'Format periode harus tahun-bulan (YYYY-MM).');
    }

    public function test_generate_requires_a_period_and_accepts_y_m(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('admin.invoices.generate'), [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.period.0', 'Periode wajib dipilih.');

        $this->actingAs($this->user)
            ->post(route('admin.invoices.generate'), ['period' => '2026-07'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Tagihan 2026-07: 0 dibuat, 0 dilewati.');
    }

    public function test_cancel_requires_a_reason_in_indonesian(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('admin.invoices.cancel', $this->draft()), [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'Alasan pembatalan wajib diisi.');
    }

    public function test_record_payment_messages_are_indonesian(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('admin.invoices.payments.store', $this->draft('sent')), ['method' => 'barter'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'Jumlah wajib diisi.')
            ->assertJsonPath('errors.method.0', 'Metode yang dipilih tidak dikenal.');

        $this->actingAs($this->user)
            ->postJson(route('admin.invoices.payments.store', $this->draft('sent')), ['amount' => 0, 'method' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'Jumlah harus lebih dari nol.');
    }

    public function test_reverse_payment_requires_a_reason_in_indonesian(): void
    {
        $invoice = $this->draft('partial');
        $payment = Payment::create([
            'company_id' => $invoice->company_id,
            'invoice_id' => $invoice->id,
            'amount' => '1000.00',
            'method' => 'cash',
            'reference' => 'REF-REVERSE-1',
            'paid_at' => now(),
            'received_by' => User::factory()->create(['company_id' => $invoice->company_id])->id,
        ]);

        $this->actingAs($this->user)
            ->postJson(route('admin.invoices.payments.reverse', [$invoice, $payment]), [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'Alasan pembatalan wajib diisi.');
    }

    public function test_add_item_attributes_are_indonesian(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('admin.invoices.items.store', $this->draft()), [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.description.0', 'Deskripsi wajib diisi.')
            ->assertJsonPath('errors.quantity.0', 'Kuantitas wajib diisi.')
            ->assertJsonPath('errors.unit_price.0', 'Harga satuan wajib diisi.');
    }

    public function test_service_aborts_a_web_user_can_see_are_indonesian(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('admin.invoices.send', $this->draft('sent')))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Hanya tagihan draf yang bisa dikirim.');

        $sent = $this->draft('sent');
        $this->actingAs($this->user)
            ->putJson(route('admin.invoices.update', $sent), [OptimisticLock::FIELD => OptimisticLock::version($sent->fresh())])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Hanya tagihan draf yang bisa diubah.');

        $this->actingAs($this->user)
            ->postJson(route('admin.invoices.payments.store', $this->draft('draft')), ['amount' => 1000, 'method' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Tagihan tidak menerima pembayaran pada statusnya saat ini.');
    }
}
