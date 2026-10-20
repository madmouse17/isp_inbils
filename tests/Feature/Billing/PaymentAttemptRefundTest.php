<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
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

/**
 * K4: a refund happens in the Midtrans dashboard or by bank transfer, so finance closes an exception by marking it
 * "Dikembalikan" with the reference of the refund and a note. The mark is audited and takes the money out of Tunggakan;
 * nothing is sent to Midtrans and the invoice is not touched.
 */
class PaymentAttemptRefundTest extends TestCase
{
    use CreatesCompanyUser;
    use InteractsWithMidtrans;
    use RefreshDatabase;

    private const REFUSED = 'Hanya pembayaran online berstatus Perlu dicek yang belum dibukukan yang bisa ditandai dikembalikan.';

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

    /** @param array<string, mixed> $attributes */
    private function attempt(string $status = PaymentAttempt::STATUS_EXCEPTION, ?Invoice $invoice = null, array $attributes = []): PaymentAttempt
    {
        return PaymentAttemptFactory::new()->forInvoice($invoice ?? $this->invoice())->status($status)->create([
            'status_reason' => 'gross_amount "99000.00" differs from the attempt amount 100000.00.',
            'last_notification' => ['transaction_status' => 'settlement'],
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function refund(PaymentAttempt $attempt, ?User $by = null, array $data = []): TestResponse
    {
        return $this->actingAs($by ?? $this->admin)->postJson(
            route('admin.payment-attempts.refund', $attempt),
            [...['reference' => 'MID-RFD-0001', 'note' => 'Dikembalikan lewat dashboard Midtrans.'], ...$data],
        );
    }

    private function fresh(PaymentAttempt $attempt): PaymentAttempt
    {
        return PaymentAttempt::withoutCompany()->findOrFail($attempt->id);
    }

    private function refundAudits(): int
    {
        return Activity::query()->where('log_name', 'payment_attempt')->where('description', 'refunded')->count();
    }

    private function staffWithRole(string $role): User
    {
        $user = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    public function test_exception_attempt_is_marked_refunded_with_reference_and_audit(): void
    {
        $invoice = $this->invoice();
        $attempt = $this->attempt(invoice: $invoice);

        $this->actingAs($this->admin)
            ->post(route('admin.payment-attempts.refund', $attempt), ['reference' => 'MID-RFD-0001', 'note' => 'Dikembalikan lewat dashboard Midtrans.'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Pembayaran ditandai dikembalikan.');

        $marked = $this->fresh($attempt);
        $this->assertSame('refunded', $marked->status);
        $this->assertSame($this->admin->id, $marked->resolved_by);
        $this->assertNotNull($marked->resolved_at);
        $this->assertSame('MID-RFD-0001', $marked->resolution_reference);
        $this->assertSame('Dikembalikan lewat dashboard Midtrans.', $marked->resolution_note);
        $this->assertSame($attempt->status_reason, $marked->status_reason, 'why it needed a person stays on record');
        $this->assertNull($marked->payment_id);

        // The money was never booked and still is not: the invoice and the ledger are untouched, and Midtrans is not asked.
        $this->assertSame('sent', $invoice->fresh()->status);
        $this->assertSame('0.00', $invoice->fresh()->paid_amount);
        $this->assertSame(0, Payment::withoutCompany()->count());
        Http::assertNothingSent();

        $audit = Activity::query()->where('log_name', 'payment_attempt')->where('description', 'refunded')->sole();
        $this->assertSame($this->admin->id, $audit->causer_id);
        $this->assertSame($attempt->id, $audit->subject_id);
        $this->assertSame('MID-RFD-0001', $audit->getExtraProperty('reference'));
        $this->assertSame('Dikembalikan lewat dashboard Midtrans.', $audit->getExtraProperty('note'));
        $this->assertSame($attempt->order_id, $audit->getExtraProperty('order_id'));
        $this->assertSame('100000.00', $audit->getExtraProperty('amount'));
        $this->assertNotContains('snap_token', array_keys($audit->properties->all()));
    }

    /** @return array<string, array{string}> */
    public static function attemptsThatCannotBeMarked(): array
    {
        return [
            'still pending' => ['pending'],
            'paid' => ['paid'],
            'failed' => ['failed'],
            'expired' => ['expired'],
            'closed by Inbils' => ['cancelled_locally'],
            'already marked refunded' => ['refunded'],
            'booked, then returned (payment linked)' => ['linked'],
            'booked by hand with its order id' => ['by_hand'],
        ];
    }

    #[DataProvider('attemptsThatCannotBeMarked')]
    public function test_booked_or_non_exception_attempt_is_refused(string $case): void
    {
        $invoice = $this->invoice();
        $attempt = $this->attempt(in_array($case, ['linked', 'by_hand'], true) ? 'exception' : $case, $invoice);

        if ($case === 'linked') {
            $attempt->forceFill(['payment_id' => BillingService::recordPayment($invoice, 1000, 'transfer')->id])->save();
        }

        if ($case === 'by_hand') {
            BillingService::recordPayment($invoice, 1000, 'transfer', $attempt->order_id);
        }

        $before = $this->fresh($attempt);

        $this->refund($attempt)->assertUnprocessable()->assertJsonValidationErrors(['refund' => self::REFUSED]);

        $after = $this->fresh($attempt);
        $this->assertSame($before->status, $after->status);
        $this->assertNull($after->resolved_by);
        $this->assertNull($after->resolved_at);
        $this->assertNull($after->resolution_reference);
        $this->assertSame(0, $this->refundAudits());
    }

    public function test_refund_needs_billing_payment_reverse(): void
    {
        $attempt = $this->attempt();
        $staff = $this->staffWithRole('staff');

        $this->refund($attempt, $staff)->assertForbidden();

        $this->assertSame('exception', $this->fresh($attempt)->status);
        $this->assertSame(0, $this->refundAudits());

        // The permission is what counts, not a role: a user holding it directly may mark it.
        $holder = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $holder->givePermissionTo('billing.payment.reverse');

        $this->refund($attempt, $holder)->assertRedirect();

        $this->assertSame($holder->id, $this->fresh($attempt)->resolved_by);
    }

    public function test_reference_and_note_are_required_and_capped(): void
    {
        $attempt = $this->attempt();

        $this->refund($attempt, data: ['reference' => null, 'note' => null])->assertJsonValidationErrors(['reference', 'note']);
        $this->refund($attempt, data: ['reference' => str_repeat('r', 101), 'note' => str_repeat('n', 501)])->assertJsonValidationErrors(['reference', 'note']);

        $this->assertSame('exception', $this->fresh($attempt)->status);
        $this->assertSame(0, $this->refundAudits());

        $this->refund($attempt, data: ['reference' => str_repeat('r', 100), 'note' => str_repeat('n', 500)])->assertRedirect();

        $this->assertSame('refunded', $this->fresh($attempt)->status);
    }

    public function test_other_company_attempt_is_404(): void
    {
        $foreign = $this->attempt(invoice: $this->invoice(Company::factory()->create(['is_active' => true])));

        $this->refund($foreign)->assertNotFound();

        $this->assertSame('exception', $this->fresh($foreign)->status);
        $this->assertSame(0, $this->refundAudits());
    }

    public function test_refunded_leaves_open_exceptions(): void
    {
        $marked = $this->attempt();
        $stillOpen = $this->attempt();

        $this->assertEqualsCanonicalizing([$marked->id, $stillOpen->id], PaymentAttemptService::openExceptions()->pluck('id')->all());

        $this->refund($marked)->assertRedirect();

        $this->assertSame([$stillOpen->id], PaymentAttemptService::openExceptions()->pluck('id')->all());
        $this->get(route('admin.billing.receivables'))
            ->assertInertia(fn (Assert $page) => $page->where('paymentExceptions.count', 1));
    }

    public function test_marking_alerts_nobody(): void
    {
        $attempt = $this->attempt();
        $alerts = fn (): int => DB::table('notifications')->where('type', PaymentAttemptExceptionNotification::class)->count();

        $this->refund($attempt)->assertRedirect();

        $this->assertSame(0, $alerts());
    }

    /** @return array<string, array{string}> */
    public static function whatMidtransSaysLater(): array
    {
        return ['the refund it was told about' => ['refund'], 'a replayed settlement' => ['settlement'], 'an expiry' => ['expire']];
    }

    #[DataProvider('whatMidtransSaysLater')]
    public function test_a_refunded_attempt_stays_refunded_when_midtrans_speaks_again(string $transactionStatus): void
    {
        $attempt = $this->attempt(attributes: ['last_notification' => null]);
        $this->refund($attempt)->assertRedirect();
        $marked = $this->fresh($attempt);

        $returned = app(PaymentAttemptService::class)->applyNotification($marked, $this->midtransPayload($attempt, ['transaction_status' => $transactionStatus]));

        $this->assertSame('refunded', $returned->status);
        $this->assertSame('refunded', $this->fresh($attempt)->status);
        $this->assertSame($this->admin->id, $this->fresh($attempt)->resolved_by);
        $this->assertSame(0, Payment::withoutCompany()->count(), 'a returned payment is never booked');
        $this->assertSame(0, DB::table('notifications')->where('type', PaymentAttemptExceptionNotification::class)->count());
        $this->assertSame(1, $this->refundAudits());
        $this->assertSame(0, Activity::query()->where('log_name', 'payment_attempt')->where('description', 'exception')->count());
    }

    public function test_marking_takes_the_invoice_lock_before_the_attempt_lock(): void
    {
        $attempt = $this->attempt();

        DB::enableQueryLog();
        $this->refund($attempt)->assertRedirect();
        $locks = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'for update'))->values();
        DB::disableQueryLog();

        $invoiceLock = $locks->search(fn (string $sql): bool => str_contains($sql, 'from `invoices`'));
        $attemptLock = $locks->search(fn (string $sql): bool => str_contains($sql, 'from `payment_attempts`'));

        $this->assertNotFalse($invoiceLock, 'the invoice row is locked');
        $this->assertNotFalse($attemptLock, 'the attempt row is locked');
        $this->assertLessThan($attemptLock, $invoiceLock, 'invoice first, attempt second: the order every writer of the two follows');
    }

    public function test_the_invoice_page_shows_who_marked_it_when_with_reference_and_note(): void
    {
        $invoice = $this->invoice();
        $open = $this->attempt(invoice: $invoice);
        $marked = $this->attempt(invoice: $invoice);
        $this->refund($marked)->assertRedirect();

        $this->get(route('admin.invoices.show', $invoice))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('onlinePayments.attempts.data.0.id', $marked->public_id)
                ->where('onlinePayments.attempts.data.0.status', 'refunded')
                ->where('onlinePayments.attempts.data.0.refund.by', $this->admin->name)
                ->where('onlinePayments.attempts.data.0.refund.reference', 'MID-RFD-0001')
                ->where('onlinePayments.attempts.data.0.refund.note', 'Dikembalikan lewat dashboard Midtrans.')
                ->where('onlinePayments.attempts.data.0.refund.at', fn (string $at): bool => Carbon::parse($at)->isToday())
                ->where('onlinePayments.attempts.data.1.id', $open->public_id)
                ->where('onlinePayments.attempts.data.1.status', 'exception')
                ->where('onlinePayments.attempts.data.1.refund', null));
    }
}
