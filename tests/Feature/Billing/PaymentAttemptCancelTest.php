<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Database\Factories\PaymentAttemptFactory;
use Modules\Billing\Jobs\CancelMidtransTransactionJob;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentAttempt;
use Modules\Billing\Services\MidtransGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithMidtrans;

/**
 * F5-U11: staff close an open Midtrans page from the invoice ("Batalkan di Midtrans"). The page is closed here at once,
 * Midtrans is asked from the queue to cancel its transaction, and the click is audited.
 */
class PaymentAttemptCancelTest extends TestCase
{
    use CreatesCompanyUser;
    use InteractsWithMidtrans;
    use RefreshDatabase;

    private const REFUSED = 'Hanya pembayaran yang masih menunggu yang bisa dibatalkan.';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = $this->createCompanyUser();
        $this->actingAs($this->admin);
        $this->enableMidtrans();
        $this->fakeMidtrans();
    }

    private function invoice(?Company $company = null): Invoice
    {
        $companyId = $company->id ?? $this->admin->company_id;

        return InvoiceFactory::new()->create([
            'company_id' => $companyId,
            'customer_id' => Customer::factory()->create(['company_id' => $companyId])->id,
            'status' => 'sent',
            'total' => 100000,
        ]);
    }

    private function attempt(string $status = PaymentAttempt::STATUS_PENDING, ?Invoice $invoice = null): PaymentAttempt
    {
        return PaymentAttemptFactory::new()->forInvoice($invoice ?? $this->invoice())->status($status)->create();
    }

    private function cancel(PaymentAttempt $attempt, ?User $by = null): TestResponse
    {
        return $this->actingAs($by ?? $this->admin)->postJson(route('admin.payment-attempts.cancel', $attempt));
    }

    private function fresh(PaymentAttempt $attempt): PaymentAttempt
    {
        return PaymentAttempt::withoutCompany()->findOrFail($attempt->id);
    }

    private function audits(string $event): int
    {
        return Activity::query()->where('log_name', 'payment_attempt')->where('description', $event)->count();
    }

    /** @param  list<string>  $permissions */
    private function permitted(array $permissions): User
    {
        $user = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);

        return $this->grantPermissions($user, ['app.web', ...$permissions]);
    }

    public function test_staff_close_an_open_page_and_midtrans_is_asked_from_the_queue(): void
    {
        Bus::fake([CancelMidtransTransactionJob::class]);
        $invoice = $this->invoice();
        $attempt = $this->attempt(invoice: $invoice);

        $this->actingAs($this->admin)
            ->post(route('admin.payment-attempts.cancel', $attempt))
            ->assertRedirect()
            ->assertSessionHas('success', 'Pembatalan dikirim ke Midtrans.');

        $closed = $this->fresh($attempt);
        $this->assertSame('cancelled_locally', $closed->status);
        $this->assertSame('Dibatalkan oleh staf dari halaman tagihan.', $closed->status_reason);
        $this->assertSame($attempt->order_id, $closed->order_id);
        $this->assertSame('100000.00', $closed->amount, 'what the page stood for never changes');

        // The request itself never calls Midtrans; the queue does, after the commit.
        Http::assertNothingSent();
        Bus::assertDispatchedTimes(CancelMidtransTransactionJob::class, 1);
        Bus::assertDispatched(CancelMidtransTransactionJob::class, fn (CancelMidtransTransactionJob $job): bool => $job->companyId === $closed->company_id && $job->attemptId === $closed->id);

        // The invoice is not touched: closing a page is not booking, cancelling or refunding anything.
        $this->assertSame(['sent', '0.00'], [$invoice->fresh()->status, $invoice->fresh()->paid_amount]);

        // One audit row, who asked, with the order and the amount and nothing secret; not a second one for the state change.
        $audit = Activity::query()->where('log_name', 'payment_attempt')->where('description', 'cancel_requested')->sole();
        $this->assertSame($this->admin->id, $audit->causer_id);
        $this->assertSame($attempt->id, $audit->subject_id);
        $this->assertSame($attempt->order_id, $audit->getExtraProperty('order_id'));
        $this->assertSame('100000.00', $audit->getExtraProperty('amount'));
        $this->assertSame(0, $this->audits('cancelled_locally'));
        foreach (['snap_token', 'redirect_url', 'signature_key', self::FAKE_SERVER_KEY, $attempt->snap_token] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $audit->toJson());
        }
    }

    public function test_the_queued_job_really_cancels_the_transaction_at_midtrans(): void
    {
        Bus::fake([CancelMidtransTransactionJob::class]);
        $attempt = $this->attempt();

        $this->cancel($attempt)->assertRedirect();
        Bus::dispatched(CancelMidtransTransactionJob::class)->sole()->handle(app(MidtransGateway::class));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.sandbox.midtrans.com/v2/'.$attempt->order_id.'/cancel');
    }

    /** @return array<string, array{string}> */
    public static function pagesThatAreNotOpen(): array
    {
        return [
            'paid' => ['paid'],
            'failed' => ['failed'],
            'expired' => ['expired'],
            'needs a person' => ['exception'],
            'already closed by Inbils' => ['cancelled_locally'],
            'marked refunded' => ['refunded'],
        ];
    }

    #[DataProvider('pagesThatAreNotOpen')]
    public function test_a_page_that_is_not_pending_cannot_be_cancelled(string $status): void
    {
        Bus::fake([CancelMidtransTransactionJob::class]);
        $attempt = $this->attempt($status);

        $this->cancel($attempt)->assertUnprocessable()->assertJsonValidationErrors(['cancel' => self::REFUSED]);

        $this->assertSame($status, $this->fresh($attempt)->status);
        $this->assertNull($this->fresh($attempt)->status_reason);
        Bus::assertNothingDispatched();
        $this->assertSame(0, $this->audits('cancel_requested'));
    }

    public function test_a_second_click_is_refused_and_asks_midtrans_once(): void
    {
        Bus::fake([CancelMidtransTransactionJob::class]);
        $attempt = $this->attempt();

        $this->cancel($attempt)->assertRedirect();
        $this->cancel($attempt)->assertUnprocessable()->assertJsonValidationErrors(['cancel' => self::REFUSED]);

        Bus::assertDispatchedTimes(CancelMidtransTransactionJob::class, 1);
        $this->assertSame(1, $this->audits('cancel_requested'));
    }

    public function test_cancelling_needs_billing_payment_record(): void
    {
        Bus::fake([CancelMidtransTransactionJob::class]);
        $attempt = $this->attempt();
        $noc = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantRole($noc, 'noc');

        // Reading invoices is not recording payments.
        $this->cancel($attempt, $noc)->assertForbidden();
        $this->cancel($attempt, $this->permitted(['billing.view']))->assertForbidden();
        $this->assertSame('pending', $this->fresh($attempt)->status);
        Bus::assertNothingDispatched();

        // The permission is what counts, not a role: whoever may record a payment may close the page.
        $this->cancel($attempt, $this->permitted(['billing.payment.record']))->assertRedirect();
        $this->assertSame('cancelled_locally', $this->fresh($attempt)->status);
    }

    public function test_another_companys_page_is_a_404_and_stays_open(): void
    {
        Bus::fake([CancelMidtransTransactionJob::class]);
        $foreign = $this->attempt(invoice: $this->invoice(Company::factory()->create(['is_active' => true])));

        $this->cancel($foreign)->assertNotFound();

        $this->assertSame('pending', $this->fresh($foreign)->status);
        Bus::assertNothingDispatched();
        $this->assertSame(0, $this->audits('cancel_requested'));
    }

    public function test_with_the_gateway_off_nothing_is_closed_because_midtrans_cannot_be_asked(): void
    {
        Bus::fake([CancelMidtransTransactionJob::class]);
        $attempt = $this->attempt();
        $this->disableMidtrans();

        $this->cancel($attempt)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cancel' => 'Pembayaran online sedang tidak aktif, jadi pembatalan tidak bisa dikirim ke Midtrans.']);

        $this->assertSame('pending', $this->fresh($attempt)->status);
        Bus::assertNothingDispatched();
        $this->assertSame(0, $this->audits('cancel_requested'));
    }

    public function test_the_invoice_is_locked_before_the_page(): void
    {
        Bus::fake([CancelMidtransTransactionJob::class]);
        $attempt = $this->attempt();

        DB::enableQueryLog();
        $this->cancel($attempt)->assertRedirect();
        $locks = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'for update'))->values();
        DB::disableQueryLog();

        $invoiceLock = $locks->search(fn (string $sql): bool => str_contains($sql, 'from `invoices`'));
        $attemptLock = $locks->search(fn (string $sql): bool => str_contains($sql, 'from `payment_attempts`'));

        $this->assertNotFalse($invoiceLock, 'the invoice row is locked');
        $this->assertNotFalse($attemptLock, 'the attempt row is locked');
        $this->assertLessThan($attemptLock, $invoiceLock, 'invoice first, attempt second: the order every writer of the two follows');
    }

    public function test_the_cancel_that_midtrans_reports_back_leaves_the_page_closed_and_alerts_nobody(): void
    {
        Bus::fake([CancelMidtransTransactionJob::class]);
        $attempt = $this->attempt();
        $this->cancel($attempt)->assertRedirect();
        $this->midtransStatusReturns($this->midtransPayload($attempt, ['transaction_status' => 'cancel', 'status_code' => '200', 'fraud_status' => 'accept']));
        CompanyService::useCompanyForTests(null);

        $this->postJson('/webhooks/midtrans', $this->midtransPayload($attempt, ['transaction_status' => 'cancel', 'status_code' => '200']))->assertOk();

        $closed = $this->fresh($attempt);
        $this->assertSame('cancelled_locally', $closed->status, 'Midtrans confirming the cancel does not turn the closed page into a failed one');
        $this->assertSame('Dibatalkan oleh staf dari halaman tagihan.', $closed->status_reason);
        $this->assertSame(0, $this->audits('exception'));
        $this->assertSame(1, $this->audits('callback_received'));
    }

    public function test_a_payment_that_still_arrives_for_a_page_staff_closed_is_booked_while_the_invoice_owes_it(): void
    {
        Bus::fake([CancelMidtransTransactionJob::class]);
        $invoice = $this->invoice();
        $attempt = $this->attempt(invoice: $invoice);
        $this->cancel($attempt)->assertRedirect();
        $settled = $this->midtransPayload($attempt);
        $this->midtransStatusReturns($settled);
        CompanyService::useCompanyForTests(null);

        $this->postJson('/webhooks/midtrans', $settled)->assertOk();

        // Closing the page does not refuse the money: only billing can, and here it still owes that amount.
        $paid = $this->fresh($attempt);
        $booked = Invoice::withoutCompany()->findOrFail($invoice->id);
        $this->assertSame('paid', $paid->status);
        $this->assertNotNull($paid->payment_id);
        $this->assertSame(['paid', '100000.00'], [$booked->status, $booked->paid_amount]);
        $this->assertSame(0, $this->audits('exception'));
    }
}
