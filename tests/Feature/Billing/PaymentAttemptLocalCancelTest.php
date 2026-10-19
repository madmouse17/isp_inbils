<?php

namespace Tests\Feature\Billing;

use App\Http\Resources\NotificationResource;
use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\DeviceToken;
use App\Models\Core\EmployeeProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Database\Factories\PaymentAttemptFactory;
use Modules\Billing\Exceptions\MidtransGatewayException;
use Modules\Billing\Jobs\CancelMidtransTransactionJob;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentAttempt;
use Modules\Billing\Notifications\PaymentAttemptExceptionNotification;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Services\MidtransGateway;
use Modules\Billing\Services\PaymentAttemptService;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\FakesFirebaseMessaging;
use Tests\Traits\InteractsWithMidtrans;
use Tests\Traits\RunsConcurrentWorkers;

/**
 * A Snap page left open while the invoice is paid, cancelled or changed is closed by Inbils (`cancelled_locally`), the
 * transaction is cancelled at Midtrans from the queue, and money that still arrives for a closed page is an exception
 * that billing staff are told about.
 */
class PaymentAttemptLocalCancelTest extends TestCase
{
    use CreatesCompanyUser;
    use FakesFirebaseMessaging;
    use InteractsWithMidtrans;
    use RefreshDatabase;
    use RunsConcurrentWorkers;

    private const FINISH_URL = 'https://inbils.test/portal/payments/return';

    private const CLOSED_REASON = 'Tagihan dibayar atau dibatalkan saat halaman pembayaran masih terbuka.';

    private User $admin;

    private PaymentAttemptService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
        $this->actingAs($this->admin);
        $this->enableMidtrans();
        $this->fakeMidtrans();
        $this->service = app(PaymentAttemptService::class);
    }

    /** @param array<string, mixed> $attributes */
    private function invoice(array $attributes = [], ?Company $company = null): Invoice
    {
        $companyId = $company->id ?? $this->admin->company_id;

        return InvoiceFactory::new()->create(array_merge([
            'company_id' => $companyId,
            'customer_id' => Customer::factory()->create(['company_id' => $companyId])->id,
            'status' => 'sent',
            'total' => 100000,
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function page(Invoice $invoice, array $attributes = []): PaymentAttempt
    {
        return PaymentAttemptFactory::new()->forInvoice($invoice)->create($attributes);
    }

    /** @param array<string, mixed> $attributes */
    private function closedPage(Invoice $invoice, array $attributes = []): PaymentAttempt
    {
        return PaymentAttemptFactory::new()->forInvoice($invoice)->status(PaymentAttempt::STATUS_CANCELLED_LOCALLY)
            ->create(['status_reason' => self::CLOSED_REASON, ...$attributes]);
    }

    /** @param array<string, mixed> $attributes */
    private function staff(string $role, ?Company $company = null, array $attributes = []): User
    {
        $user = User::factory()->create([
            'company_id' => $company->id ?? $this->admin->company_id,
            'is_active' => true,
            'email_verified_at' => now(),
            ...$attributes,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function auditCount(string $event): int
    {
        return Activity::query()->where('log_name', 'payment_attempt')->where('description', $event)->count();
    }

    private function cancelCalls(): int
    {
        return Http::recorded(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/cancel'))->count();
    }

    private function queuedCancels(): int
    {
        return DB::table('jobs')->get()
            ->filter(fn (object $row): bool => json_decode($row->payload, true)['displayName'] === CancelMidtransTransactionJob::class)
            ->count();
    }

    /**
     * Exceptions in every state openExceptions() has to tell apart. The open ones come in the order they were made, each
     * a minute newer than the one before.
     *
     * @return array{open: list<PaymentAttempt>, hidden: list<PaymentAttempt>}
     */
    private function exceptionCases(): array
    {
        $minutes = 100;
        $exception = function (Invoice $invoice, array $attributes = []) use (&$minutes): PaymentAttempt {
            return PaymentAttemptFactory::new()->forInvoice($invoice)->status(PaymentAttempt::STATUS_EXCEPTION)
                ->create(['created_at' => now()->subMinutes($minutes--), ...$attributes]);
        };
        $notice = fn (string $status): array => ['last_notification' => ['transaction_status' => $status]];
        $reviewer = $this->staff('staff');
        $reverse = function (Payment $payment) use ($reviewer): void {
            $this->actingAs($reviewer);
            BillingService::reversePayment($payment, 'salah nominal');
            $this->actingAs($this->admin);
        };

        // Open: money captured and never booked.
        $captured = $exception($this->invoice(), [...$notice('settlement'), 'status_reason' => 'Tagihan tidak menerima pembayaran pada statusnya saat ini.']);
        $noNotice = $exception($this->invoice());
        $partlyRefunded = $exception($this->invoice(), $notice('partial_refund'));
        $handBookingReversed = $exception($invoiceC = $this->invoice(), $notice('settlement'));
        $reverse(BillingService::recordPayment($invoiceC, 1000, 'transfer', $handBookingReversed->order_id));

        // Open: booked money that was returned.
        $returned = $exception($invoiceD = $this->invoice(), [...$notice('refund'), 'status_reason' => 'Midtrans reported refund after settlement. Reverse the payment by hand if the money was returned.']);
        $returned->forceFill(['payment_id' => BillingService::recordPayment($invoiceD, 1000, 'transfer')->id])->save();

        // Resolved: staff booked the money with the order id, or Midtrans took it back, or the returned payment was reversed.
        $bookedByHand = $exception($invoiceB = $this->invoice(), $notice('settlement'));
        BillingService::recordPayment($invoiceB, 1000, 'transfer', $bookedByHand->order_id);
        $refunded = $exception($this->invoice(), $notice('refund'));
        $chargedBack = $exception($this->invoice(), $notice('chargeback'));
        $returnedAndReversed = $exception($invoiceE = $this->invoice(), $notice('refund'));
        $paymentE = BillingService::recordPayment($invoiceE, 1000, 'transfer');
        $returnedAndReversed->forceFill(['payment_id' => $paymentE->id])->save();
        $reverse($paymentE);

        // Not exceptions, and another company's exception.
        $this->page($this->invoice());
        $this->page($this->invoice(), ['status' => PaymentAttempt::STATUS_FAILED]);
        $this->closedPage($this->invoice());
        $foreign = $exception($this->invoice(company: Company::factory()->create(['is_active' => true])), $notice('settlement'));

        return [
            'open' => [$captured, $noNotice, $partlyRefunded, $handBookingReversed, $returned],
            'hidden' => [$bookedByHand, $refunded, $chargedBack, $returnedAndReversed, $foreign],
        ];
    }

    public function test_recording_cash_closes_the_open_snap_page_and_queues_a_midtrans_cancel(): void
    {
        Bus::fake([CancelMidtransTransactionJob::class]);
        $invoice = $this->invoice(['total' => 154600]);
        $page = $this->service->openFor($invoice, self::FINISH_URL);

        $this->post(route('admin.invoices.payments.store', $invoice), ['amount' => 154600, 'method' => 'cash'])->assertRedirect();

        $closed = $page->fresh();
        $this->assertSame(PaymentAttempt::STATUS_CANCELLED_LOCALLY, $closed->status);
        $this->assertSame(self::CLOSED_REASON, $closed->status_reason);
        $this->assertSame('paid', $invoice->fresh()->status, 'the cash payment is booked');
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, $this->auditCount('cancelled_locally'));
        Bus::assertDispatchedTimes(CancelMidtransTransactionJob::class, 1);
        Bus::assertDispatched(CancelMidtransTransactionJob::class, fn (CancelMidtransTransactionJob $job): bool => $job->companyId === $closed->company_id && $job->attemptId === $closed->id);
    }

    public function test_cancelling_the_invoice_closes_its_open_pages(): void
    {
        Bus::fake([CancelMidtransTransactionJob::class]);
        $invoice = $this->invoice();
        $page = $this->service->openFor($invoice, self::FINISH_URL);

        BillingService::cancel($invoice, 'Salah tagih');

        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame(PaymentAttempt::STATUS_CANCELLED_LOCALLY, $page->fresh()->status);
        Bus::assertDispatchedTimes(CancelMidtransTransactionJob::class, 1);
        Bus::assertDispatched(CancelMidtransTransactionJob::class, fn (CancelMidtransTransactionJob $job): bool => $job->attemptId === $page->id);
    }

    public function test_a_partial_cash_payment_closes_the_page_whose_amount_no_longer_matches(): void
    {
        Bus::fake([CancelMidtransTransactionJob::class]);
        $invoice = $this->invoice(['total' => 154600]);
        $page = $this->service->openFor($invoice, self::FINISH_URL);

        BillingService::recordPayment($invoice, 54600, 'cash');

        $closed = $page->fresh();
        $this->assertSame(PaymentAttempt::STATUS_CANCELLED_LOCALLY, $closed->status);
        $this->assertSame('154600.00', $closed->amount, 'what the page stood for never changes');
        Bus::assertDispatchedTimes(CancelMidtransTransactionJob::class, 1);

        $next = $this->service->openFor($invoice->fresh(), self::FINISH_URL);

        $this->assertNotSame($page->id, $next->id, 'a closed page is never reopened');
        $this->assertSame('100000.00', $next->amount);
        $this->assertSame(PaymentAttempt::STATUS_PENDING, $next->status);
    }

    public function test_settling_one_page_closes_the_others_but_not_itself(): void
    {
        Bus::fake([CancelMidtransTransactionJob::class]);
        $invoice = $this->invoice();
        $settling = $this->page($invoice);
        $sibling = $this->page($invoice);
        $elsewhere = $this->page($this->invoice(['total' => 50000]));

        $this->service->applyNotification($settling, $this->midtransPayload($settling));

        $this->assertSame(PaymentAttempt::STATUS_PAID, $settling->fresh()->status);
        $this->assertSame(PaymentAttempt::STATUS_CANCELLED_LOCALLY, $sibling->fresh()->status);
        $this->assertSame(PaymentAttempt::STATUS_PENDING, $elsewhere->fresh()->status, 'a page of another invoice is not touched');
        $this->assertSame(1, Payment::query()->count());
        Bus::assertDispatchedTimes(CancelMidtransTransactionJob::class, 1);
        Bus::assertDispatched(CancelMidtransTransactionJob::class, fn (CancelMidtransTransactionJob $job): bool => $job->attemptId === $sibling->id);
    }

    public function test_the_cash_payment_is_booked_even_when_midtrans_cannot_be_reached(): void
    {
        config(['queue.default' => 'database']);
        $invoice = $this->invoice();
        $page = $this->service->openFor($invoice, self::FINISH_URL);
        $tries = 0;
        $this->midtransCancelReply = function () use (&$tries): never {
            $tries++;

            throw new ConnectionException('cURL error 28: timeout');
        };

        $this->post(route('admin.invoices.payments.store', $invoice), ['amount' => 100000, 'method' => 'cash'])->assertRedirect();

        $this->assertSame(0, $tries, 'the request itself never talks to Midtrans');
        $this->assertSame(1, $this->queuedCancels());

        $this->artisan('queue:work', ['--queue' => 'default', '--stop-when-empty' => true, '--sleep' => 0, '--memory' => 4096])->assertSuccessful();

        $this->assertSame(1, $tries, 'the worker tried');
        $this->assertSame(1, $this->queuedCancels(), 'and the failed cancel waits for its next try');
        $this->assertSame(1, DB::table('jobs')->where('attempts', 1)->count());
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(PaymentAttempt::STATUS_CANCELLED_LOCALLY, $page->fresh()->status);
    }

    public function test_the_cancel_is_queued_only_after_the_invoice_change_commits(): void
    {
        config(['queue.default' => 'database']);
        $invoice = $this->invoice();
        $page = $this->service->openFor($invoice, self::FINISH_URL);

        try {
            DB::transaction(function () use ($invoice): void {
                $this->service->closeOpenFor(Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail());
                $this->assertSame(0, $this->queuedCancels(), 'nothing is queued while the change can still roll back');

                throw new RuntimeException('rolled back');
            });
            $this->fail('the transaction should have rolled back');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, $this->queuedCancels());
        $this->assertSame(PaymentAttempt::STATUS_PENDING, $page->fresh()->status);

        DB::transaction(function () use ($invoice): void {
            $this->service->closeOpenFor(Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail());
            $this->assertSame(0, $this->queuedCancels());
        });

        $this->assertSame(1, $this->queuedCancels());
        $this->assertSame(PaymentAttempt::STATUS_CANCELLED_LOCALLY, $page->fresh()->status);
    }

    public function test_closing_pages_touches_only_pending_ones_of_that_invoice_and_skips_the_page_being_settled(): void
    {
        Bus::fake([CancelMidtransTransactionJob::class]);
        $invoice = $this->invoice();
        $first = $this->page($invoice);
        $second = $this->page($invoice);
        $settling = $this->page($invoice);
        $done = [
            PaymentAttempt::STATUS_PAID => $this->page($invoice, ['status' => PaymentAttempt::STATUS_PAID]),
            PaymentAttempt::STATUS_FAILED => $this->page($invoice, ['status' => PaymentAttempt::STATUS_FAILED]),
            PaymentAttempt::STATUS_EXCEPTION => $this->page($invoice, ['status' => PaymentAttempt::STATUS_EXCEPTION]),
        ];
        $elsewhere = $this->page($this->invoice(['total' => 50000]));

        DB::transaction(fn () => $this->service->closeOpenFor(Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail(), $settling->order_id));

        $this->assertSame(PaymentAttempt::STATUS_CANCELLED_LOCALLY, $first->fresh()->status);
        $this->assertSame(PaymentAttempt::STATUS_CANCELLED_LOCALLY, $second->fresh()->status);
        $this->assertSame(PaymentAttempt::STATUS_PENDING, $settling->fresh()->status);
        $this->assertSame(PaymentAttempt::STATUS_PENDING, $elsewhere->fresh()->status);
        foreach ($done as $status => $attempt) {
            $this->assertSame($status, $attempt->fresh()->status);
        }
        $this->assertSame(2, $this->auditCount('cancelled_locally'));
        Bus::assertDispatchedTimes(CancelMidtransTransactionJob::class, 2);
        Bus::assertNotDispatched(CancelMidtransTransactionJob::class, fn (CancelMidtransTransactionJob $job): bool => $job->attemptId === $settling->id);
    }

    public function test_a_late_settlement_on_a_closed_page_becomes_an_exception_and_alerts_billing_staff_of_that_company_only(): void
    {
        $cashier = $this->staff('staff');
        $technician = $this->staff('technician');
        $inactive = $this->staff('staff', attributes: ['is_active' => false]);
        $stranger = $this->staff('admin', Company::factory()->create(['is_active' => true]));
        $invoice = $this->invoice();
        $page = $this->closedPage($invoice);
        BillingService::recordPayment($invoice, 100000, 'cash');
        Notification::fake();

        $result = $this->service->applyNotification($page, $this->midtransPayload($page));

        $this->assertSame(PaymentAttempt::STATUS_EXCEPTION, $result->status);
        $this->assertSame('Tagihan tidak menerima pembayaran pada statusnya saat ini.', $result->status_reason);
        $this->assertNull($result->payment_id);
        $this->assertSame(1, Payment::query()->count(), 'only the cash payment is booked');
        $this->assertSame('100000.00', $invoice->fresh()->paid_amount);
        $this->assertSame(1, $this->auditCount('exception'));
        Notification::assertSentTo([$this->admin, $cashier], PaymentAttemptExceptionNotification::class, function (PaymentAttemptExceptionNotification $notification) use ($page, $invoice): bool {
            $data = $notification->toArray($this->admin);

            return $notification->companyId === $page->company_id
                && $notification->attemptId === $page->id
                && $data['type'] === 'payment_attempt_exception'
                && $data['order_id'] === $page->order_id
                && $data['amount'] === '100000.00'
                && $data['invoice_id'] === $invoice->public_id
                && $data['invoice_number'] === $invoice->number
                && $data['company_id'] === $page->company_id;
        });
        Notification::assertNotSentTo([$technician, $inactive, $stranger], PaymentAttemptExceptionNotification::class);
        Notification::assertSentTimes(PaymentAttemptExceptionNotification::class, 2);
    }

    /** @return array<string, array{string}> */
    public static function causesOfAnException(): array
    {
        return [
            'an amount that differs from the page' => ['amount'],
            'a refund of money that was never booked' => ['refund'],
            'an invoice that cannot take the money' => ['unpayable'],
            'a refund after the settlement' => ['after settlement'],
        ];
    }

    #[DataProvider('causesOfAnException')]
    public function test_every_move_to_exception_alerts_billing_staff_once_and_a_replay_does_not_alert_again(string $cause): void
    {
        $invoice = $this->invoice();
        $page = $this->page($invoice);
        $payload = match ($cause) {
            'amount' => $this->midtransPayload($page, ['gross_amount' => '5000.00']),
            'unpayable' => $this->midtransPayload($page),
            default => $this->midtransPayload($page, ['transaction_status' => 'refund']),
        };
        match ($cause) {
            'unpayable' => BillingService::cancel($invoice, 'Salah tagih'),
            'after settlement' => $this->service->applyNotification($page, $this->midtransPayload($page)),
            default => null,
        };
        Notification::fake();

        $this->service->applyNotification($page, $payload);
        $this->service->applyNotification($page, $payload);

        $this->assertSame(PaymentAttempt::STATUS_EXCEPTION, $page->fresh()->status);
        Notification::assertSentToTimes($this->admin, PaymentAttemptExceptionNotification::class, 1);
    }

    public function test_a_pending_notice_keeps_a_closed_page_closed_and_cancels_it_again(): void
    {
        Bus::fake([CancelMidtransTransactionJob::class]);
        $page = $this->closedPage($this->invoice());

        $result = $this->service->applyNotification($page, $this->midtransPayload($page, ['transaction_status' => 'pending', 'status_code' => '201', 'payment_type' => 'bank_transfer']));

        $this->assertSame(PaymentAttempt::STATUS_CANCELLED_LOCALLY, $result->status);
        $this->assertSame(self::CLOSED_REASON, $result->status_reason);
        $this->assertSame('pending', $result->last_notification['transaction_status']);
        $this->assertSame('bank_transfer', $result->payment_type);
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(1, $this->auditCount('notified'));
        Bus::assertDispatchedTimes(CancelMidtransTransactionJob::class, 1);
        Bus::assertDispatched(CancelMidtransTransactionJob::class, fn (CancelMidtransTransactionJob $job): bool => $job->attemptId === $page->id);
    }

    public function test_reconcile_revisits_a_closed_page_until_a_day_after_it_expires(): void
    {
        $paidInvoice = $this->invoice();
        $lost = $this->closedPage($paidInvoice, ['created_at' => now()->subMinutes(30)]);
        BillingService::recordPayment($paidInvoice, 100000, 'cash');
        $unknown = $this->closedPage($this->invoice(), ['created_at' => now()->subHours(40), 'expires_at' => now()->subHours(16)]);
        $outside = $this->closedPage($this->invoice(), ['created_at' => now()->subHours(50), 'expires_at' => now()->subHours(26)]);
        $this->midtransStatusReply = fn (Request $request) => str_contains($request->url(), $lost->order_id)
            ? Http::response($this->midtransPayload($lost))
            : Http::response(['status_code' => '404', 'status_message' => 'The requested resource is not found'], 404);
        Notification::fake();

        $this->artisan('midtrans:reconcile')->expectsOutputToContain('Closed')->assertExitCode(0);

        $this->assertSame(PaymentAttempt::STATUS_EXCEPTION, $lost->fresh()->status, 'a webhook that never arrived still lands as an exception');
        $this->assertSame(PaymentAttempt::STATUS_CANCELLED_LOCALLY, $unknown->fresh()->status, 'a page Midtrans does not know stays as it is, it is not expired');
        $this->assertSame(PaymentAttempt::STATUS_CANCELLED_LOCALLY, $outside->fresh()->status);
        $this->assertSame(2, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/status'))->count());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), $outside->order_id));
        Notification::assertSentToTimes($this->admin, PaymentAttemptExceptionNotification::class, 1);
    }

    public function test_open_exceptions_lists_unbooked_money_and_returned_payments_and_hides_resolved_ones(): void
    {
        ['open' => $open, 'hidden' => $hidden] = $this->exceptionCases();

        $listed = PaymentAttemptService::openExceptions()->pluck('id')->all();
        $unlisted = array_diff(PaymentAttempt::withoutCompany()->where('status', PaymentAttempt::STATUS_EXCEPTION)->pluck('id')->all(), $listed);

        $this->assertEqualsCanonicalizing(collect($open)->pluck('id')->all(), $listed);
        $this->assertEqualsCanonicalizing(collect($hidden)->pluck('id')->all(), $unlisted);
    }

    public function test_receivables_lists_open_exceptions_and_hides_booked_or_refunded_ones(): void
    {
        $newestFirst = collect($this->exceptionCases()['open'])->reverse()->values();
        $newest = $newestFirst->first();

        $this->get(route('admin.billing.receivables'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Billing/Receivables')
                ->where('paymentExceptions.count', 5)
                ->has('paymentExceptions.items', 5)
                ->where('paymentExceptions.items', fn ($items): bool => $items->pluck('order_id')->all() === $newestFirst->pluck('order_id')->all())
                ->has('paymentExceptions.items.0', fn (Assert $item) => $item
                    ->where('invoice_id', $newest->invoice->public_id)
                    ->where('invoice_number', $newest->invoice->number)
                    ->where('order_id', $newest->order_id)
                    ->where('amount', '100000.00')
                    ->where('reason', $newest->status_reason)
                    ->where('updated_at', fn ($value): bool => Carbon::parse($value)->equalTo($newest->fresh()->updated_at))));
    }

    public function test_receivables_shows_only_the_newest_ten_open_exceptions_but_counts_every_one(): void
    {
        $attempts = collect(range(1, 12))->map(fn (int $age): PaymentAttempt => PaymentAttemptFactory::new()->forInvoice($this->invoice())
            ->status(PaymentAttempt::STATUS_EXCEPTION)->create(['created_at' => now()->subMinutes(30 - $age)]));

        $this->get(route('admin.billing.receivables'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('paymentExceptions.count', 12)
                ->has('paymentExceptions.items', 10)
                ->where('paymentExceptions.items', fn ($items): bool => $items->pluck('order_id')->all() === $attempts->reverse()->take(10)->pluck('order_id')->values()->all()));
    }

    public function test_receivables_has_no_payment_exceptions_to_show_while_none_is_open(): void
    {
        PaymentAttemptFactory::new()->forInvoice($this->invoice())->status(PaymentAttempt::STATUS_EXCEPTION)
            ->create(['last_notification' => ['transaction_status' => 'refund']]);
        $this->page($this->invoice());

        $this->get(route('admin.billing.receivables'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('paymentExceptions.count', 0)
                ->where('paymentExceptions.items', []));
    }

    public function test_cash_and_online_settlement_on_one_invoice_book_once(): void
    {
        $invoice = $this->invoice();
        $page = $this->page($invoice);
        $payload = $this->midtransPayload($page);
        $this->commitFixture();

        try {
            $results = $this->race([
                ['company_id' => $invoice->company_id, 'role' => 'cash', 'invoice_id' => $invoice->id],
                ['company_id' => $invoice->company_id, 'role' => 'online', 'attempt_id' => $page->id, 'payload' => $payload],
            ], <<<'PHP'
                config(['logging.default' => 'null']);
                // The company's Midtrans row is committed with the fixture and the cancel job runs inline here: nothing may reach the real Midtrans.
                Illuminate\Support\Facades\Http::fake();

                try {
                    if ($args['role'] === 'cash') {
                        Modules\Billing\Services\BillingService::recordPayment(Modules\Billing\Models\Invoice::query()->findOrFail($args['invoice_id']), '100000.00', 'cash');

                        return ['booked' => 'cash'];
                    }

                    $attempt = Modules\Billing\Models\PaymentAttempt::query()->findOrFail($args['attempt_id']);

                    return ['attempt' => app(Modules\Billing\Services\PaymentAttemptService::class)->applyNotification($attempt, $args['payload'])->status];
                } catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
                    return ['refused' => $e->getStatusCode()];
                }
            PHP);

            $payments = Payment::withoutCompany()->where('invoice_id', $invoice->id)->get();
            $alerts = DB::table('notifications')->where('notifiable_id', $this->admin->id)->where('data', 'like', '%payment_attempt_exception%')->count();
            $attempt = PaymentAttempt::withoutCompany()->findOrFail($page->id);

            $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
            $this->assertCount(1, $payments, 'exactly one payment: '.json_encode($results));
            $this->assertSame('paid', Invoice::withoutCompany()->findOrFail($invoice->id)->status);

            if ($payments->first()->method === 'cash') {
                $this->assertSame(['attempt' => 'exception'], $results[1]['result']);
                $this->assertSame(PaymentAttempt::STATUS_EXCEPTION, $attempt->status);
                $this->assertSame(1, $alerts, 'the money that could not be booked alerts billing staff once');
            } else {
                $this->assertSame(['refused' => 422], $results[0]['result']);
                $this->assertSame(PaymentAttempt::STATUS_PAID, $attempt->status);
                $this->assertSame(0, $alerts);
            }
        } finally {
            DB::table('notifications')->whereIn('notifiable_id', DB::table('users')->where('company_id', $invoice->company_id)->pluck('id'))->delete();
            $this->purgeCompany($invoice->company_id);
        }
    }

    /** @return array<string, array{string}> */
    public static function attemptTransactions(): array
    {
        return ['settling' => ['settle'], 'expiring' => ['expire']];
    }

    #[DataProvider('attemptTransactions')]
    public function test_a_deadlock_inside_an_attempt_transaction_is_retried_and_applied_once(string $operation): void
    {
        $invoice = $this->invoice();
        $page = $this->page($invoice);
        $this->commitFixture();
        $deadlocks = 1;
        PaymentAttempt::updating(function () use (&$deadlocks): void {
            if ($deadlocks-- > 0) {
                throw new QueryException('mysql', 'update `payment_attempts` set `status` = ?', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
            }
        });

        try {
            $result = $operation === 'settle'
                ? $this->service->applyNotification($page, $this->midtransPayload($page))
                : $this->service->markExpired($page);

            $this->assertSame($operation === 'settle' ? PaymentAttempt::STATUS_PAID : PaymentAttempt::STATUS_EXPIRED, $result->status);
            $this->assertSame(-1, $deadlocks, 'the first try deadlocked and the second one ran');
            $this->assertSame($operation === 'settle' ? 1 : 0, Payment::withoutCompany()->where('invoice_id', $invoice->id)->count(), 'the rolled-back try booked nothing');
            $this->assertSame(1, $this->auditCount($operation === 'settle' ? 'settled' : 'expired'), 'and wrote no audit row of its own');
        } finally {
            $this->purgeCompany($invoice->company_id);
        }
    }

    public function test_the_status_migration_rolls_back_to_the_old_enum_and_turns_closed_pages_into_expired_ones(): void
    {
        $closed = $this->closedPage($this->invoice());
        $this->commitFixture();
        $migration = require base_path('Modules/Billing/database/migrations/2026_10_23_120000_add_cancelled_locally_to_payment_attempts_status.php');
        $column = fn (): object => DB::selectOne("SELECT COLUMN_TYPE AS type, COLUMN_DEFAULT AS fallback FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_attempts' AND COLUMN_NAME = 'status'");

        try {
            $this->assertSame("enum('pending','paid','failed','expired','exception','cancelled_locally')", $column()->type);

            $migration->down();

            $this->assertSame("enum('pending','paid','failed','expired','exception')", $column()->type);
            $this->assertSame('pending', $column()->fallback);
            $this->assertSame('expired', DB::table('payment_attempts')->where('id', $closed->id)->value('status'));
        } finally {
            $migration->up();
            $this->purgeCompany($this->admin->company_id);
        }

        $this->assertSame("enum('pending','paid','failed','expired','exception','cancelled_locally')", $column()->type);
        $this->assertSame('pending', $column()->fallback);
    }

    public function test_the_alert_reads_in_the_bell_and_goes_to_the_phone_of_a_staff_member_with_the_app(): void
    {
        $this->fakeFirebase();
        $staff = $this->staff('staff');
        EmployeeProfile::factory()->create(['company_id' => $staff->company_id, 'user_id' => $staff->id, 'status' => 'active']);
        DeviceToken::factory()->create(['user_id' => $staff->id, 'app' => 'technician', 'token' => 'staff-phone-0123456789']);
        $invoice = $this->invoice();
        $page = $this->page($invoice);
        BillingService::recordPayment($invoice, 100000, 'cash');

        $this->service->applyNotification($page, $this->midtransPayload($page));

        $row = $staff->notifications()->sole();
        $this->assertEquals([
            'type' => 'payment_attempt_exception',
            'invoice_id' => $invoice->public_id,
            'invoice_number' => $invoice->number,
            'order_id' => $page->order_id,
            'amount' => '100000.00',
            'reason' => 'Tagihan tidak menerima pembayaran pada statusnya saat ini.',
            'company_id' => $page->company_id,
        ], $row->data);

        $title = 'Pembayaran online perlu dicek';
        $message = "Pembayaran {$page->order_id} sebesar Rp 100.000 untuk tagihan {$invoice->number} tidak bisa dibukukan otomatis. Periksa di menu Tunggakan.";
        $bell = (new NotificationResource($row))->resolve(HttpRequest::create('/api/v1/notifications'));
        $this->assertSame('payment_attempt_exception', $bell['type']);
        $this->assertSame($title, $bell['title']);
        $this->assertSame($message, $bell['message']);
        $this->assertSame(['type' => 'invoice', 'id' => $invoice->public_id], $bell['subject']);
        $this->assertContains('payment_attempt_exception', NotificationResource::TYPES);

        $push = $this->onlyPush();
        $this->assertSame(['title' => $title, 'body' => $message], $push['notification']);
        $this->assertSame([
            'type' => 'payment_attempt_exception',
            'subject_type' => 'invoice',
            'subject_id' => $invoice->public_id,
            'notification_id' => $row->id,
            'title' => $title,
            'body' => $message,
        ], $push['data']);
        $this->assertSame(['staff-phone-0123456789'], $this->pushedTokens());
    }

    public function test_the_cancel_call_goes_to_the_core_api_for_that_order_with_the_server_key(): void
    {
        $page = $this->closedPage($this->invoice());

        app(MidtransGateway::class)->cancelTransaction($page);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.sandbox.midtrans.com/v2/'.$page->order_id.'/cancel'
            && $request->header('Authorization') === ['Basic '.base64_encode(self::FAKE_SERVER_KEY.':')]);
    }

    /** @return array<string, array{int, array<string, string>}> */
    public static function nothingLeftToCancel(): array
    {
        $unknown = ['status_code' => '404', 'status_message' => 'Transaction doesn\'t exist.'];
        $final = ['status_code' => '412', 'status_message' => 'Transaction status cannot be updated.'];

        return [
            'no transaction, HTTP 404' => [404, $unknown],
            'no transaction, a 404 inside an HTTP 200' => [200, $unknown],
            'already settled, expired or cancelled, HTTP 412' => [412, $final],
            'already settled, expired or cancelled, a 412 inside an HTTP 200' => [200, $final],
        ];
    }

    /** @param array<string, string> $body */
    #[DataProvider('nothingLeftToCancel')]
    public function test_midtrans_saying_there_is_nothing_to_cancel_is_not_an_error(int $httpStatus, array $body): void
    {
        $this->midtransCancelReturns($body, $httpStatus);

        app(MidtransGateway::class)->cancelTransaction($this->closedPage($this->invoice()));

        $this->assertSame(1, $this->cancelCalls());
    }

    /** @return array<string, array{int, array<string, string>}> */
    public static function refusals(): array
    {
        return [
            'a server error' => [500, ['status_message' => 'oops']],
            'unauthorized, HTTP 401' => [401, ['status_code' => '401', 'status_message' => 'Access denied due to unauthorized transaction']],
            'unauthorized, a 401 inside an HTTP 200' => [200, ['status_code' => '401', 'status_message' => 'Access denied due to unauthorized transaction']],
        ];
    }

    /** @param array<string, string> $body */
    #[DataProvider('refusals')]
    public function test_a_cancel_midtrans_refuses_raises_without_leaking_the_key(int $httpStatus, array $body): void
    {
        $this->midtransCancelReturns($body, $httpStatus);

        try {
            app(MidtransGateway::class)->cancelTransaction($this->closedPage($this->invoice()));
            $this->fail('A refused cancel must raise.');
        } catch (MidtransGatewayException $e) {
            $this->assertStringNotContainsString(self::FAKE_SERVER_KEY, $e->getMessage());
        }
    }

    public function test_a_network_failure_while_cancelling_surfaces_as_a_gateway_exception(): void
    {
        $this->midtransCancelReply = fn () => throw new ConnectionException('cURL error 28: timeout');

        $this->expectException(MidtransGatewayException::class);

        app(MidtransGateway::class)->cancelTransaction($this->closedPage($this->invoice()));
    }

    public function test_the_cancel_job_runs_in_the_pages_company_and_cancels_only_a_page_that_is_still_closed(): void
    {
        $closed = $this->closedPage($this->invoice());
        $settled = $this->page($this->invoice(), ['status' => PaymentAttempt::STATUS_PAID]);
        $pending = $this->page($this->invoice());
        $otherCompany = Company::factory()->create(['is_active' => true]);

        foreach ([$settled, $pending] as $attempt) {
            (new CancelMidtransTransactionJob($attempt->company_id, $attempt->id))->handle(app(MidtransGateway::class));
        }
        (new CancelMidtransTransactionJob($otherCompany->id, $closed->id))->handle(app(MidtransGateway::class));

        $this->assertSame(0, $this->cancelCalls(), 'a settled page, a pending one and a page of another company are left alone');

        (new CancelMidtransTransactionJob($closed->company_id, $closed->id))->handle(app(MidtransGateway::class));

        $this->assertSame(1, $this->cancelCalls());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), $closed->order_id));
    }

    public function test_the_cancel_job_does_nothing_while_the_gateway_is_off(): void
    {
        $closed = $this->closedPage($this->invoice());
        $this->disableMidtrans();

        (new CancelMidtransTransactionJob($closed->company_id, $closed->id))->handle(app(MidtransGateway::class));

        Http::assertNothingSent();
    }

    public function test_a_cancel_that_never_got_through_is_logged_with_the_order_id_only(): void
    {
        $closed = $this->closedPage($this->invoice());
        $job = new CancelMidtransTransactionJob($closed->company_id, $closed->id);
        Log::spy();

        $job->failed(new MidtransGatewayException('Midtrans cancel failed (HTTP 500).'));

        Log::shouldHaveReceived('warning')->once()->with('midtrans.cancel_failed', ['order_id' => $closed->order_id]);
        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 60, 300], $job->backoff);
        $this->assertSame(30, $job->timeout);
    }
}
