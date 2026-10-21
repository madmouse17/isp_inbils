<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Database\Factories\PaymentAttemptFactory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentAttempt;
use Modules\Billing\Notifications\PaymentAttemptExceptionNotification;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Services\PaymentAttemptService;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithMidtrans;

class MidtransWebhookTest extends TestCase
{
    use CreatesCompanyUser;
    use InteractsWithMidtrans;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
        $this->enableMidtrans();
        $this->fakeMidtrans();
    }

    /** @param array<string, mixed> $invoice */
    private function attemptFor(array $invoice = [], ?Company $company = null, array $attempt = []): PaymentAttempt
    {
        $companyId = $company->id ?? $this->admin->company_id;
        $customer = Customer::factory()->create(['company_id' => $companyId]);
        $invoice = InvoiceFactory::new()->create(array_merge([
            'company_id' => $companyId,
            'customer_id' => $customer->id,
            'status' => 'sent',
            'total' => 100000,
        ], $invoice));

        return PaymentAttemptFactory::new()->forInvoice($invoice)->create($attempt);
    }

    /**
     * Deliver a notification the way Midtrans does: no session, no user, no ambient company. The Status
     * API (the authority) answers with $remote, by default the same record as the notification.
     *
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>|null  $remote
     */
    private function notify(PaymentAttempt $attempt, array $overrides = [], ?array $remote = null): TestResponse
    {
        $notification = $this->midtransPayload($attempt, $overrides);
        $this->midtransStatusReturns($remote ?? $notification);
        CompanyService::useCompanyForTests(null);

        return $this->postJson('/webhooks/midtrans', $notification);
    }

    /** @return array{status: string, paid_amount: string, updated_at: string} */
    private function invoiceState(PaymentAttempt $attempt): array
    {
        $invoice = $this->freshInvoice($attempt);

        return ['status' => $invoice->status, 'paid_amount' => $invoice->paid_amount, 'updated_at' => $invoice->updated_at->toIso8601String()];
    }

    private function payments(): Collection
    {
        return Payment::withoutCompany()->get();
    }

    private function freshInvoice(PaymentAttempt $attempt): Invoice
    {
        return Invoice::withoutCompany()->findOrFail($attempt->invoice_id);
    }

    private function freshAttempt(PaymentAttempt $attempt): PaymentAttempt
    {
        return PaymentAttempt::withoutCompany()->findOrFail($attempt->id);
    }

    private function auditCount(string $event): int
    {
        return Activity::query()->where('log_name', 'payment_attempt')->where('description', $event)->count();
    }

    /** The alerts billing staff were sent for an attempt that needs a person. */
    private function alerts(): int
    {
        return DatabaseNotification::query()->where('type', PaymentAttemptExceptionNotification::class)->count();
    }

    public function test_a_valid_settlement_books_exactly_one_payment_and_closes_the_invoice(): void
    {
        $attempt = $this->attemptFor();

        $this->notify($attempt)->assertOk()->assertSee('OK');

        $payments = $this->payments();
        $this->assertCount(1, $payments);
        $payment = $payments->first();
        $this->assertSame('midtrans', $payment->method);
        $this->assertSame($attempt->order_id, $payment->reference);
        $this->assertNull($payment->received_by);
        $this->assertSame('100000.00', $payment->amount);
        $this->assertSame($attempt->company_id, $payment->company_id);

        $invoice = $this->freshInvoice($attempt);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('100000.00', $invoice->paid_amount);

        $settled = $this->freshAttempt($attempt);
        $this->assertSame('paid', $settled->status);
        $this->assertSame($payment->id, $settled->payment_id);
        $this->assertNotNull($settled->settled_at);
        $this->assertSame('513f1f01-c9da-474c-9fc9-d5c64364b709', $settled->transaction_id);
        $this->assertSame('bank_transfer', $settled->payment_type);
        $this->assertNull($settled->status_reason);

        $this->assertSame(1, $this->auditCount('settled'));
        $this->assertSame(1, Activity::query()->where('log_name', 'invoice')->where('description', 'payment_recorded')->count());
        $this->assertSame(0, $this->alerts(), 'a settlement that booked needs nobody');
    }

    public function test_an_attempt_that_predates_the_inb_order_id_format_still_settles_through_its_old_id(): void
    {
        $attempt = $this->attemptFor(attempt: ['order_id' => '12-345-a1b2c3d4e5f6']);

        $this->notify($attempt)->assertOk()->assertSee('OK');

        $this->assertSame('paid', $this->freshAttempt($attempt)->status);
        $this->assertSame('paid', $this->freshInvoice($attempt)->status);
        $this->assertSame('12-345-a1b2c3d4e5f6', $this->payments()->first()->reference);
    }

    public function test_the_notification_is_only_a_trigger_the_state_applied_comes_from_the_status_api(): void
    {
        $attempt = $this->attemptFor();

        $this->notify($attempt)->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.sandbox.midtrans.com/v2/'.$attempt->order_id.'/status'
            && $request->header('Authorization') === ['Basic '.base64_encode(self::FAKE_SERVER_KEY.':')]);
    }

    public function test_a_notification_the_status_api_does_not_confirm_books_nothing(): void
    {
        $attempt = $this->attemptFor();
        $remote = $this->midtransPayload($attempt, ['transaction_status' => 'pending', 'status_code' => '201', 'fraud_status' => 'accept']);

        $this->notify($attempt, remote: $remote)->assertOk();

        $this->assertCount(0, $this->payments());
        $this->assertSame('pending', $this->freshAttempt($attempt)->status);
        $this->assertSame('sent', $this->freshInvoice($attempt)->status);
    }

    public function test_a_balance_left_after_settlement_leaves_the_invoice_partial(): void
    {
        $attempt = $this->attemptFor(attempt: ['amount' => '40000.00']);

        $this->notify($attempt)->assertOk();

        $invoice = $this->freshInvoice($attempt);
        $this->assertSame('partial', $invoice->status);
        $this->assertSame('40000.00', $invoice->paid_amount);
        $this->assertSame('paid', $this->freshAttempt($attempt)->status);
    }

    public function test_a_card_capture_accepted_by_fraud_detection_settles(): void
    {
        $attempt = $this->attemptFor();

        $this->notify($attempt, ['transaction_status' => 'capture', 'payment_type' => 'credit_card', 'fraud_status' => 'accept'])->assertOk();

        $this->assertCount(1, $this->payments());
        $this->assertSame('credit_card', $this->freshAttempt($attempt)->payment_type);
    }

    public function test_the_same_notification_replayed_three_times_books_one_payment(): void
    {
        $attempt = $this->attemptFor();

        $this->notify($attempt)->assertOk();
        $afterFirst = $this->invoiceState($attempt);
        $settled = $this->freshAttempt($attempt)->only(['status', 'status_reason', 'payment_id', 'settled_at']);

        $this->travel(5)->minutes();
        $this->notify($attempt)->assertOk();
        $this->notify($attempt)->assertOk();

        $this->assertCount(1, $this->payments());
        $this->assertSame($afterFirst, $this->invoiceState($attempt));
        $this->assertEquals($settled, $this->freshAttempt($attempt)->only(['status', 'status_reason', 'payment_id', 'settled_at']));
        $this->assertSame('paid', $this->freshAttempt($attempt)->status);
        $this->assertSame(1, $this->auditCount('settled'));
        $this->assertSame(0, $this->auditCount('exception'));
        $this->assertSame(1, Activity::query()->where('log_name', 'invoice')->where('description', 'payment_recorded')->count());
    }

    public function test_a_second_worker_holding_a_stale_attempt_cannot_book_the_payment_again(): void
    {
        $attempt = $this->attemptFor();
        $stale = $this->freshAttempt($attempt);
        $record = $this->midtransPayload($attempt);
        $service = app(PaymentAttemptService::class);

        $service->applyNotification($attempt, $record);
        $service->applyNotification($stale, $record);

        $this->assertCount(1, $this->payments());
        $this->assertSame('100000.00', $this->freshInvoice($attempt)->paid_amount);
        $this->assertSame('paid', $this->freshAttempt($attempt)->status);
        $this->assertSame(0, $this->auditCount('exception'));
    }

    public function test_a_bad_signature_is_rejected_with_an_empty_body_and_changes_nothing(): void
    {
        $attempt = $this->attemptFor();
        $notification = [...$this->midtransPayload($attempt), 'signature_key' => str_repeat('a', 128)];
        CompanyService::useCompanyForTests(null);

        $this->postJson('/webhooks/midtrans', $notification)->assertForbidden()->assertContent('');

        Http::assertNothingSent();
        $this->assertCount(0, $this->payments());
        $this->assertSame('pending', $this->freshAttempt($attempt)->status);
        $this->assertSame(0, Activity::query()->where('log_name', 'payment_attempt')->count());
    }

    public function test_a_notification_signed_for_other_figures_is_rejected(): void
    {
        $attempt = $this->attemptFor();
        $signedForSmallerAmount = $this->midtransPayload($attempt, ['gross_amount' => '1000.00']);
        CompanyService::useCompanyForTests(null);

        $this->postJson('/webhooks/midtrans', [...$signedForSmallerAmount, 'gross_amount' => '100000.00'])->assertForbidden();

        Http::assertNothingSent();
        $this->assertCount(0, $this->payments());
    }

    public function test_a_notification_without_a_signature_is_forbidden(): void
    {
        $attempt = $this->attemptFor();
        $notification = $this->midtransPayload($attempt);
        unset($notification['signature_key']);

        $this->postJson('/webhooks/midtrans', $notification)->assertForbidden()->assertContent('');

        $this->assertCount(0, $this->payments());
    }

    /** @return array<string, array{string}> */
    public static function requiredFields(): array
    {
        return ['order_id' => ['order_id'], 'status_code' => ['status_code'], 'gross_amount' => ['gross_amount'], 'transaction_status' => ['transaction_status']];
    }

    #[DataProvider('requiredFields')]
    public function test_a_notification_missing_a_required_field_is_unprocessable(string $field): void
    {
        $attempt = $this->attemptFor();
        $notification = $this->midtransPayload($attempt);
        unset($notification[$field]);

        $this->postJson('/webhooks/midtrans', $notification)->assertUnprocessable()->assertContent('');

        Http::assertNothingSent();
        $this->assertCount(0, $this->payments());
    }

    public function test_a_number_where_midtrans_sends_a_string_is_unprocessable(): void
    {
        $attempt = $this->attemptFor();

        $this->postJson('/webhooks/midtrans', [...$this->midtransPayload($attempt), 'gross_amount' => 100000])->assertUnprocessable();

        $this->assertCount(0, $this->payments());
    }

    public function test_legacy_unknown_order_answers_200_and_logs_foreign_order(): void
    {
        $attempt = $this->attemptFor();
        $notification = $this->midtransPayload($attempt, ['order_id' => '999-999-doesnotexist']);
        Log::spy();

        // No order means no company, and without a company there is no key to check a signature against: the legacy URL
        // cannot tell a foreign order of a shared merchant from junk, so it answers 200 to both (no retry storm), writes
        // nothing and logs the order id as unverified.
        $this->postJson('/webhooks/midtrans', $notification)->assertOk()->assertSee('OK');
        $this->postJson('/webhooks/midtrans', [...$notification, 'signature_key' => str_repeat('a', 128)])->assertOk();

        Log::shouldHaveReceived('info')->with('midtrans.foreign_order', ['order_id' => '999-999-doesnotexist', 'verified' => false])->twice();
        Http::assertNothingSent();
        $this->assertCount(0, $this->payments());
        $this->assertSame(0, Activity::query()->where('log_name', 'payment_attempt')->count());
        $this->assertSame('pending', $this->freshAttempt($attempt)->status);
    }

    public function test_the_foreign_order_log_keeps_at_most_64_characters_of_the_order_id(): void
    {
        $attempt = $this->attemptFor();
        Log::spy();

        $this->postJson('/webhooks/midtrans', $this->midtransPayload($attempt, ['order_id' => str_repeat('x', 100)]))->assertOk();

        Log::shouldHaveReceived('info')->with('midtrans.foreign_order', ['order_id' => str_repeat('x', 64), 'verified' => false])->once();
    }

    public function test_only_a_json_body_is_read(): void
    {
        $attempt = $this->attemptFor();

        $this->post('/webhooks/midtrans', $this->midtransPayload($attempt))->assertForbidden();

        Http::assertNothingSent();
        $this->assertCount(0, $this->payments());
    }

    /** @return array<string, array{string, string}> */
    public static function webhookRoutes(): array
    {
        return [
            'the legacy route' => ['webhooks.midtrans', 'webhooks/midtrans'],
            'the company route' => ['webhooks.midtrans.company', 'webhooks/midtrans/{company}'],
        ];
    }

    #[DataProvider('webhookRoutes')]
    public function test_the_routes_take_posts_only_and_need_no_session_or_csrf_token(string $name, string $uri): void
    {
        $route = app('router')->getRoutes()->getByName($name);

        $this->get(str_replace('{company}', (string) Str::ulid(), '/'.$uri))->assertStatus(405);
        $this->assertSame($uri, $route->uri());
        $this->assertSame(['POST'], $route->methods());
        $this->assertNotContains('web', $route->gatherMiddleware());
        $this->assertNotContains('auth', $route->gatherMiddleware());
        $this->assertContains('throttle:midtrans-webhook', $route->gatherMiddleware());
    }

    public function test_unsigned_junk_is_throttled_but_a_signed_notification_still_gets_through(): void
    {
        $attempt = $this->attemptFor();
        $junk = [...$this->midtransPayload($attempt), 'signature_key' => 'bad'];

        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/webhooks/midtrans', $junk)->assertForbidden();
        }
        $this->postJson('/webhooks/midtrans', $junk)->assertStatus(429);

        // Behind a proxy Midtrans shares that address with the junk, yet a signed notification still gets through.
        $this->notify($attempt)->assertOk();
        $this->assertCount(1, $this->payments());
    }

    public function test_the_webhook_does_not_exist_while_the_gateway_is_off(): void
    {
        $attempt = $this->attemptFor();
        $notification = $this->midtransPayload($attempt);
        $this->disableMidtrans();

        $this->postJson('/webhooks/midtrans', $notification)->assertNotFound();

        Http::assertNothingSent();
        $this->assertCount(0, $this->payments());
    }

    public function test_an_amount_that_differs_from_the_attempt_is_quarantined(): void
    {
        $attempt = $this->attemptFor();

        $this->notify($attempt, ['gross_amount' => '1000.00'])->assertOk();

        $this->assertCount(0, $this->payments());
        $quarantined = $this->freshAttempt($attempt);
        $this->assertSame('exception', $quarantined->status);
        $this->assertStringContainsString('gross_amount', $quarantined->status_reason);
        $this->assertNull($quarantined->payment_id);
        $this->assertSame('sent', $this->freshInvoice($attempt)->status);
        $this->assertSame(1, $this->auditCount('exception'));
        $this->assertSame(1, $this->alerts());
    }

    /** @return array<string, array{string, string, string}> */
    public static function unpayableInvoices(): array
    {
        return [
            'cancelled' => ['cancelled', '0', 'Tagihan tidak menerima pembayaran pada statusnya saat ini.'],
            'draft' => ['draft', '0', 'Tagihan tidak menerima pembayaran pada statusnya saat ini.'],
            'already paid' => ['paid', '100000', 'Tagihan tidak menerima pembayaran pada statusnya saat ini.'],
        ];
    }

    #[DataProvider('unpayableInvoices')]
    public function test_money_for_an_invoice_that_can_no_longer_take_payments_becomes_an_exception(string $status, string $paid, string $reason): void
    {
        $attempt = $this->attemptFor();
        Invoice::withoutCompany()->whereKey($attempt->invoice_id)->update(['status' => $status, 'paid_amount' => $paid]);

        $this->notify($attempt)->assertOk();

        $this->assertCount(0, $this->payments());
        $quarantined = $this->freshAttempt($attempt);
        $this->assertSame('exception', $quarantined->status);
        $this->assertSame($reason, $quarantined->status_reason);
        $this->assertSame($status, $this->freshInvoice($attempt)->status);
        $this->assertSame(1, $this->auditCount('exception'));
        $this->assertSame(1, $this->alerts());
    }

    public function test_a_balance_that_shrank_after_the_attempt_was_created_becomes_an_exception(): void
    {
        $attempt = $this->attemptFor();
        $this->actingAs($this->admin);
        BillingService::recordPayment($this->freshInvoice($attempt), 30000, 'cash');
        $this->app['auth']->forgetGuards();

        $this->notify($attempt)->assertOk();

        $this->assertCount(1, $this->payments());
        $quarantined = $this->freshAttempt($attempt);
        $this->assertSame('exception', $quarantined->status);
        $this->assertSame('Jumlah pembayaran melebihi sisa tagihan.', $quarantined->status_reason);
        $this->assertSame('30000.00', $this->freshInvoice($attempt)->paid_amount);
        $this->assertSame(1, $this->alerts());
    }

    public function test_a_payment_that_already_uses_the_order_id_as_reference_is_quarantined(): void
    {
        $attempt = $this->attemptFor();
        Payment::create([
            'company_id' => $attempt->company_id,
            'invoice_id' => $attempt->invoice_id,
            'amount' => '1000.00',
            'method' => 'other',
            'reference' => $attempt->order_id,
            'paid_at' => now(),
            'received_by' => $this->admin->id,
        ]);

        $this->notify($attempt)->assertOk();

        $this->assertCount(1, $this->payments());
        $this->assertSame('Referensi pembayaran sudah ada.', $this->freshAttempt($attempt)->status_reason);
        $this->assertSame('exception', $this->freshAttempt($attempt)->status);
        $this->assertSame(1, $this->alerts());
    }

    public function test_a_card_challenged_by_fraud_detection_books_nothing_and_stays_pending(): void
    {
        $attempt = $this->attemptFor();

        $this->notify($attempt, ['transaction_status' => 'capture', 'payment_type' => 'credit_card', 'fraud_status' => 'challenge'])->assertOk();

        $this->assertCount(0, $this->payments());
        $this->assertSame('pending', $this->freshAttempt($attempt)->status);
        $this->assertSame('sent', $this->freshInvoice($attempt)->status);
        $this->assertSame(1, Activity::query()->where('log_name', 'payment_attempt')->where('description', 'notified')
            ->where('properties->fraud_status', 'challenge')->count());
    }

    public function test_a_capture_without_a_fraud_verdict_is_not_booked(): void
    {
        $attempt = $this->attemptFor();
        $capture = $this->midtransPayload($attempt, ['transaction_status' => 'capture', 'payment_type' => 'credit_card']);
        unset($capture['fraud_status']);
        $capture = $this->signMidtransPayload($capture);

        $this->notify($attempt, remote: $capture)->assertOk();

        $this->assertCount(0, $this->payments());
        $this->assertSame('pending', $this->freshAttempt($attempt)->status);
    }

    public function test_pending_keeps_the_attempt_pending_and_a_replay_adds_no_audit_noise(): void
    {
        $attempt = $this->attemptFor();
        $overrides = ['transaction_status' => 'pending', 'status_code' => '201', 'payment_type' => 'bank_transfer'];

        $this->notify($attempt, $overrides)->assertOk();
        $this->notify($attempt, $overrides)->assertOk();

        $pending = $this->freshAttempt($attempt);
        $this->assertSame('pending', $pending->status);
        $this->assertSame('bank_transfer', $pending->payment_type);
        $this->assertSame('513f1f01-c9da-474c-9fc9-d5c64364b709', $pending->transaction_id);
        $this->assertCount(0, $this->payments());
        $this->assertSame(1, $this->auditCount('notified'));
    }

    /** @return array<string, array{string, string, string}> */
    public static function closingStatuses(): array
    {
        return [
            'deny' => ['deny', '202', 'failed'],
            'cancel' => ['cancel', '200', 'failed'],
            'failure' => ['failure', '202', 'failed'],
            'expire' => ['expire', '407', 'expired'],
        ];
    }

    #[DataProvider('closingStatuses')]
    public function test_deny_cancel_failure_and_expire_close_the_attempt(string $transactionStatus, string $code, string $expected): void
    {
        $attempt = $this->attemptFor();

        $this->notify($attempt, ['transaction_status' => $transactionStatus, 'status_code' => $code])->assertOk();

        $closed = $this->freshAttempt($attempt);
        $this->assertSame($expected, $closed->status);
        $this->assertCount(0, $this->payments());
        $this->assertSame('sent', $this->freshInvoice($attempt)->status);
        $this->assertSame(1, $this->auditCount($expected));
    }

    public function test_a_fraud_denied_capture_fails_the_attempt(): void
    {
        $attempt = $this->attemptFor();

        $this->notify($attempt, ['transaction_status' => 'capture', 'fraud_status' => 'deny', 'payment_type' => 'credit_card'])->assertOk();

        $this->assertSame('failed', $this->freshAttempt($attempt)->status);
        $this->assertSame('fraud_status deny', $this->freshAttempt($attempt)->status_reason);
    }

    /** @return array<string, array{string}> */
    public static function reversals(): array
    {
        return ['refund' => ['refund'], 'partial refund' => ['partial_refund'], 'chargeback' => ['chargeback']];
    }

    #[DataProvider('reversals')]
    public function test_a_refund_or_chargeback_never_reverses_the_ledger_but_flags_the_attempt(string $transactionStatus): void
    {
        $attempt = $this->attemptFor();
        $this->notify($attempt)->assertOk();

        $this->notify($attempt, ['transaction_status' => $transactionStatus])->assertOk();

        $this->assertCount(1, $this->payments());
        $this->assertNull($this->payments()->first()->cancelled_at);
        $this->assertSame('paid', $this->freshInvoice($attempt)->status);
        $flagged = $this->freshAttempt($attempt);
        $this->assertSame('exception', $flagged->status);
        $this->assertNotNull($flagged->payment_id);
        $this->assertStringContainsString($transactionStatus, $flagged->status_reason);
        $this->assertSame(1, $this->auditCount('exception'));
        $this->assertSame(1, $this->alerts());
    }

    public function test_a_refund_for_an_attempt_that_was_never_settled_here_is_an_exception(): void
    {
        $attempt = $this->attemptFor();

        $this->notify($attempt, ['transaction_status' => 'refund'])->assertOk();

        $this->assertCount(0, $this->payments());
        $this->assertSame('exception', $this->freshAttempt($attempt)->status);
    }

    public function test_a_late_pending_or_expire_notice_cannot_undo_a_settled_attempt(): void
    {
        $attempt = $this->attemptFor();
        $this->notify($attempt)->assertOk();

        $this->notify($attempt, ['transaction_status' => 'pending', 'status_code' => '201'])->assertOk();
        $this->notify($attempt, ['transaction_status' => 'expire', 'status_code' => '407'])->assertOk();

        $this->assertSame('paid', $this->freshAttempt($attempt)->status);
        $this->assertCount(1, $this->payments());
    }

    /** @return array<string, array{string, string}> */
    public static function noticesThatOnlyEndTheTransaction(): array
    {
        return [
            'Midtrans confirms the cancel' => ['cancel', '200'],
            'the transaction expired' => ['expire', '407'],
            'denied' => ['deny', '202'],
            'failed' => ['failure', '202'],
        ];
    }

    #[DataProvider('noticesThatOnlyEndTheTransaction')]
    public function test_a_notice_that_only_ends_the_transaction_leaves_a_page_inbils_closed_as_it_was(string $transactionStatus, string $code): void
    {
        $reason = 'Tagihan dibayar atau dibatalkan saat halaman pembayaran masih terbuka.';
        $attempt = $this->attemptFor(attempt: ['status' => 'cancelled_locally', 'status_reason' => $reason]);

        $this->notify($attempt, ['transaction_status' => $transactionStatus, 'status_code' => $code])->assertOk();

        $closed = $this->freshAttempt($attempt);
        $this->assertSame('cancelled_locally', $closed->status);
        $this->assertSame($reason, $closed->status_reason);
        $this->assertSame($transactionStatus, $closed->last_notification['transaction_status'], 'what Midtrans said is kept');
        $this->assertCount(0, $this->payments());
        $this->assertSame(0, $this->alerts());
        $this->assertSame(1, $this->auditCount('notified'));
    }

    public function test_a_settlement_for_a_page_inbils_closed_is_booked_when_the_invoice_can_still_take_it(): void
    {
        $attempt = $this->attemptFor(attempt: ['status' => 'cancelled_locally', 'status_reason' => 'Tagihan dibayar atau dibatalkan saat halaman pembayaran masih terbuka.']);

        $this->notify($attempt)->assertOk();

        $this->assertCount(1, $this->payments());
        $settled = $this->freshAttempt($attempt);
        $this->assertSame('paid', $settled->status);
        $this->assertNull($settled->status_reason);
        $this->assertSame('paid', $this->freshInvoice($attempt)->status);
        $this->assertSame(0, $this->alerts());
    }

    public function test_money_for_a_page_inbils_closed_that_the_invoice_can_no_longer_take_is_an_exception_and_alerts_billing_staff(): void
    {
        $attempt = $this->attemptFor(attempt: ['status' => 'cancelled_locally', 'status_reason' => 'Tagihan dibayar atau dibatalkan saat halaman pembayaran masih terbuka.']);
        $this->actingAs($this->admin);
        BillingService::recordPayment($this->freshInvoice($attempt), 100000, 'cash');
        $this->app['auth']->forgetGuards();

        $this->notify($attempt)->assertOk();

        $this->assertCount(1, $this->payments());
        $exception = $this->freshAttempt($attempt);
        $this->assertSame('exception', $exception->status);
        $this->assertSame('Tagihan tidak menerima pembayaran pada statusnya saat ini.', $exception->status_reason);
        $this->assertSame(1, $this->alerts());
        $this->assertSame(1, $this->admin->notifications()->where('type', PaymentAttemptExceptionNotification::class)->count());
    }

    public function test_a_notification_only_ever_credits_the_company_that_owns_the_attempt(): void
    {
        $companyB = Company::factory()->create(['is_active' => true]);
        // The same invoice number in both companies: only the order id tells the attempts apart.
        $attemptA = $this->attemptFor(['number' => 'INV-2026-00001']);
        $attemptB = $this->attemptFor(['number' => 'INV-2026-00001'], $companyB);
        $this->assertNotSame($attemptA->order_id, $attemptB->order_id);

        $this->notify($attemptB)->assertOk();

        $payments = $this->payments();
        $this->assertCount(1, $payments);
        $this->assertSame($companyB->id, $payments->first()->company_id);
        $this->assertSame($attemptB->invoice_id, $payments->first()->invoice_id);
        $this->assertSame('paid', $this->freshInvoice($attemptB)->status);
        $this->assertSame('sent', $this->freshInvoice($attemptA)->status);
        $this->assertSame('pending', $this->freshAttempt($attemptA)->status);
        $this->assertSame($companyB->id, Activity::query()->where('log_name', 'payment_attempt')->where('description', 'settled')->value('company_id'));
    }

    public function test_a_payload_naming_a_foreign_order_cannot_settle_another_attempt(): void
    {
        $attempt = $this->attemptFor();
        $other = $this->attemptFor();

        $this->expectException(InvalidArgumentException::class);

        app(PaymentAttemptService::class)->applyNotification($attempt, $this->midtransPayload($other));
    }

    public function test_an_unreachable_status_api_asks_midtrans_to_retry_and_changes_nothing(): void
    {
        $attempt = $this->attemptFor();
        $this->midtransStatusReply = fn () => throw new ConnectionException('cURL error 28: timeout');
        CompanyService::useCompanyForTests(null);

        $this->postJson('/webhooks/midtrans', $this->midtransPayload($attempt))->assertStatus(503);

        $this->assertCount(0, $this->payments());
        $this->assertSame('pending', $this->freshAttempt($attempt)->status);
    }

    public function test_a_status_api_without_the_transaction_or_with_a_forged_answer_asks_midtrans_to_retry(): void
    {
        $attempt = $this->attemptFor();
        $this->midtransStatusReturns(['status_code' => '404', 'status_message' => 'The requested resource is not found'], 404);
        CompanyService::useCompanyForTests(null);

        $this->postJson('/webhooks/midtrans', $this->midtransPayload($attempt))->assertStatus(503);

        $this->midtransStatusReturns([...$this->midtransPayload($attempt), 'signature_key' => str_repeat('0', 128)]);
        $this->postJson('/webhooks/midtrans', $this->midtransPayload($attempt))->assertStatus(503);

        $this->assertCount(0, $this->payments());
        $this->assertSame('pending', $this->freshAttempt($attempt)->status);
    }

    public function test_no_key_material_reaches_the_audit_log_or_the_stored_attempt(): void
    {
        $attempt = $this->attemptFor();
        $this->notify($attempt)->assertOk();
        $this->notify($this->attemptFor(), ['gross_amount' => '5.00'])->assertOk();

        $stored = $this->freshAttempt($attempt);

        $this->assertStringNotContainsString(self::FAKE_SERVER_KEY, Activity::all()->toJson());
        $this->assertStringNotContainsString(self::FAKE_SERVER_KEY, json_encode($stored->getAttributes()));
        $this->assertArrayNotHasKey('signature_key', $stored->last_notification);
        $this->assertSame('settlement', $stored->last_notification['transaction_status']);
        $this->assertArrayNotHasKey('snap_token', $stored->toArray());
        $this->assertArrayNotHasKey('redirect_url', $stored->toArray());
        $this->assertArrayNotHasKey('last_notification', $stored->toArray());
    }
}
