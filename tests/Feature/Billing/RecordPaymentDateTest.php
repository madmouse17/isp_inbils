<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/**
 * F5-U01: staff may say on which day the money arrived. The day is read on the company's calendar (Asia/Jakarta, UTC+7, in
 * these tests), kept at the company clock time of recording (OD8), and stored as the instant it stands for (UTC).
 */
class RecordPaymentDateTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
        $this->admin->company->update(['timezone' => 'Asia/Jakarta']);
        CompanyService::resetCache();
        $this->actingAs($this->admin);
        // 17:30 in Jakarta.
        $this->travelTo('2026-10-20 10:30:00');
    }

    /** @param  array<string, mixed>  $attributes */
    private function sentInvoice(array $attributes = []): Invoice
    {
        return InvoiceFactory::new()->create([
            'company_id' => $this->admin->company_id,
            'status' => 'sent',
            'total' => 100000,
            'issue_date' => '2026-10-01',
            ...$attributes,
        ]);
    }

    /** @return array<string, mixed> */
    private function payment(string $paidAt): array
    {
        return ['amount' => 1000, 'method' => 'cash', 'paid_at' => $paidAt];
    }

    /** @return Builder<Activity> the "payment recorded" audit rows */
    private function audit(): Builder
    {
        return Activity::query()->where('log_name', 'invoice')->where('description', 'payment_recorded')->orderBy('id');
    }

    public function test_past_paid_date_is_stored(): void
    {
        $invoice = $this->sentInvoice();

        $this->post(route('admin.invoices.payments.store', $invoice), $this->payment('2026-10-17'))->assertRedirect();

        $payment = Payment::query()->sole();
        // The 17th at the 17:30 Jakarta the clerk recorded it, which is 10:30 UTC.
        $this->assertSame('2026-10-17 10:30:00', $payment->getRawOriginal('paid_at'));
        $this->assertSame('2026-10-17 17:30:00', $payment->paid_at->setTimezone('Asia/Jakarta')->toDateTimeString());
        // The audit row says the payment was back-dated, and to when.
        $this->assertSame('2026-10-17T10:30:00+00:00', $this->audit()->sole()->properties->get('paid_at'));
    }

    public function test_future_paid_date_is_refused(): void
    {
        $invoice = $this->sentInvoice();

        $this->postJson(route('admin.invoices.payments.store', $invoice), $this->payment('2026-10-21'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.paid_at.0', 'Tanggal bayar tidak boleh setelah hari ini.');

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame('sent', $invoice->fresh()->status);
    }

    public function test_paid_date_before_issue_date_is_refused(): void
    {
        $invoice = $this->sentInvoice(['issue_date' => '2026-10-10']);

        $this->postJson(route('admin.invoices.payments.store', $invoice), $this->payment('2026-10-09'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.paid_at.0', 'Tanggal bayar tidak boleh sebelum tanggal terbit tagihan.');
        $this->assertSame(0, Payment::query()->count());

        $this->post(route('admin.invoices.payments.store', $invoice), $this->payment('2026-10-10'))->assertRedirect();
        $this->assertSame('2026-10-10 10:30:00', Payment::query()->sole()->getRawOriginal('paid_at'));
    }

    public function test_missing_paid_date_means_now(): void
    {
        $invoice = $this->sentInvoice();

        foreach ([['amount' => 1000, 'method' => 'cash'], $this->payment(''), $this->payment('2026-10-20')] as $body) {
            $this->post(route('admin.invoices.payments.store', $invoice), $body)->assertRedirect();
        }

        $this->assertSame(
            ['2026-10-20 10:30:00', '2026-10-20 10:30:00', '2026-10-20 10:30:00'],
            Payment::query()->orderBy('id')->get()->map(fn (Payment $payment): string => $payment->getRawOriginal('paid_at'))->all(),
        );
        $this->assertSame([false, false, false], $this->audit()->get()->map(fn (Activity $row): bool => $row->properties->has('paid_at'))->all());
    }

    public function test_today_is_read_on_the_company_clock_not_the_servers(): void
    {
        // 18:00 UTC on 31 October is already 1 November, 01:00, in Jakarta.
        $this->travelTo('2026-10-31 18:00:00');
        $invoice = $this->sentInvoice();

        $this->post(route('admin.invoices.payments.store', $invoice), $this->payment('2026-11-01'))->assertRedirect();
        $this->postJson(route('admin.invoices.payments.store', $invoice), $this->payment('2026-11-02'))
            ->assertJsonPath('errors.paid_at.0', 'Tanggal bayar tidak boleh setelah hari ini.');
        $this->post(route('admin.invoices.payments.store', $invoice), $this->payment('2026-10-31'))->assertRedirect();

        $this->assertSame(
            // Today books now; the 31st keeps the 01:00 Jakarta clock time, which is still the 30th in UTC.
            ['2026-10-31 18:00:00', '2026-10-30 18:00:00'],
            Payment::query()->orderBy('id')->get()->map(fn (Payment $payment): string => $payment->getRawOriginal('paid_at'))->all(),
        );
    }

    public function test_an_invoice_issued_for_a_later_day_can_still_be_paid_today(): void
    {
        $invoice = $this->sentInvoice(['issue_date' => '2026-10-25']);

        $this->postJson(route('admin.invoices.payments.store', $invoice), $this->payment('2026-10-19'))
            ->assertJsonPath('errors.paid_at.0', 'Tanggal bayar tidak boleh sebelum tanggal terbit tagihan.');
        $this->post(route('admin.invoices.payments.store', $invoice), $this->payment('2026-10-20'))->assertRedirect();

        $this->assertSame(1, Payment::query()->count());
    }

    /** @return array<string, array{0: string}> */
    public static function notAnIsoDay(): array
    {
        return [
            'day first' => ['20/10/2026'],
            'with time' => ['2026-10-20 10:00:00'],
            'text' => ['kemarin'],
            'a day that does not exist' => ['2026-02-31'],
        ];
    }

    #[DataProvider('notAnIsoDay')]
    public function test_a_paid_date_that_is_not_y_m_d_is_refused(string $value): void
    {
        $invoice = $this->sentInvoice();

        $this->postJson(route('admin.invoices.payments.store', $invoice), $this->payment($value))
            ->assertUnprocessable()
            ->assertJsonPath('errors.paid_at.0', 'Format tanggal bayar harus tahun-bulan-tanggal (YYYY-MM-DD).');
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_back_dating_needs_the_same_permission_as_recording(): void
    {
        $invoice = $this->sentInvoice();
        $reader = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $reader->assignRole(Role::findOrCreate('billing-reader', 'web')->givePermissionTo(['dashboard.view', 'billing.view']));

        $this->actingAs($reader)
            ->postJson(route('admin.invoices.payments.store', $invoice), $this->payment('2026-10-17'))
            ->assertForbidden();

        $this->assertSame(0, Payment::query()->count());
    }
}
