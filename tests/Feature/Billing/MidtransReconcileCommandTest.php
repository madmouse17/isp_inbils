<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Database\Factories\PaymentAttemptFactory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentAttempt;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithMidtrans;

class MidtransReconcileCommandTest extends TestCase
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

    /** @param array<string, mixed> $attempt */
    private function attemptFor(?Company $company = null, array $attempt = []): PaymentAttempt
    {
        $companyId = $company->id ?? $this->admin->company_id;
        $invoice = InvoiceFactory::new()->create([
            'company_id' => $companyId,
            'customer_id' => Customer::factory()->create(['company_id' => $companyId])->id,
            'status' => 'sent',
            'total' => 100000,
        ]);

        return PaymentAttemptFactory::new()->forInvoice($invoice)->create(array_merge(['created_at' => now()->subMinutes(30)], $attempt));
    }

    private function statusCalls(): int
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/status'))->count();
    }

    public function test_a_missed_webhook_is_settled_exactly_once_and_a_second_run_changes_nothing(): void
    {
        $attempt = $this->attemptFor();
        $this->midtransStatusReturns($this->midtransPayload($attempt));

        $this->artisan('midtrans:reconcile')->assertExitCode(0);

        $payment = Payment::withoutCompany()->sole();
        $this->assertSame('midtrans', $payment->method);
        $this->assertSame($attempt->order_id, $payment->reference);
        $this->assertNull($payment->received_by);
        $this->assertSame('paid', PaymentAttempt::withoutCompany()->findOrFail($attempt->id)->status);
        $this->assertSame('paid', Invoice::withoutCompany()->findOrFail($attempt->invoice_id)->status);
        $this->assertSame(1, $this->statusCalls());

        $this->artisan('midtrans:reconcile')->assertExitCode(0);

        $this->assertCount(1, Payment::withoutCompany()->get());
        $this->assertSame(1, $this->statusCalls(), 'A settled attempt is not asked about again.');
        $this->assertSame(1, Activity::query()->where('log_name', 'payment_attempt')->where('description', 'settled')->count());
    }

    public function test_the_same_path_as_the_webhook_quarantines_a_wrong_amount(): void
    {
        $attempt = $this->attemptFor();
        $this->midtransStatusReturns($this->midtransPayload($attempt, ['gross_amount' => '5000.00']));

        $this->artisan('midtrans:reconcile')->expectsOutputToContain('Exceptions')->assertExitCode(0);

        $this->assertCount(0, Payment::withoutCompany()->get());
        $this->assertSame('exception', PaymentAttempt::withoutCompany()->findOrFail($attempt->id)->status);
    }

    public function test_an_attempt_inside_the_grace_period_is_left_alone(): void
    {
        $attempt = $this->attemptFor(attempt: ['created_at' => now()->subMinutes(5)]);
        $this->midtransStatusReturns($this->midtransPayload($attempt));

        $this->artisan('midtrans:reconcile')->assertExitCode(0);

        $this->assertSame(0, $this->statusCalls());
        $this->assertSame('pending', PaymentAttempt::withoutCompany()->findOrFail($attempt->id)->status);
        $this->assertCount(0, Payment::withoutCompany()->get());
    }

    public function test_an_expired_attempt_without_a_midtrans_transaction_is_closed(): void
    {
        $attempt = $this->attemptFor(attempt: ['created_at' => now()->subHours(25), 'expires_at' => now()->subHour()]);

        $this->artisan('midtrans:reconcile')->assertExitCode(0);

        $closed = PaymentAttempt::withoutCompany()->findOrFail($attempt->id);
        $this->assertSame('expired', $closed->status);
        $this->assertSame(1, Activity::query()->where('log_name', 'payment_attempt')->where('description', 'expired')->count());
    }

    public function test_an_expired_attempt_that_midtrans_reports_as_expired_is_closed(): void
    {
        $attempt = $this->attemptFor(attempt: ['created_at' => now()->subHours(25), 'expires_at' => now()->subHour()]);
        $this->midtransStatusReturns($this->midtransPayload($attempt, ['transaction_status' => 'expire', 'status_code' => '407']));

        $this->artisan('midtrans:reconcile')->assertExitCode(0);

        $this->assertSame('expired', PaymentAttempt::withoutCompany()->findOrFail($attempt->id)->status);
    }

    public function test_an_unexpired_attempt_midtrans_has_never_heard_of_stays_pending(): void
    {
        $attempt = $this->attemptFor();

        $this->artisan('midtrans:reconcile')->assertExitCode(0);

        $this->assertSame(1, $this->statusCalls());
        $this->assertSame('pending', PaymentAttempt::withoutCompany()->findOrFail($attempt->id)->status);
    }

    public function test_a_pending_midtrans_transaction_past_our_expiry_is_not_closed_early(): void
    {
        $attempt = $this->attemptFor(attempt: ['created_at' => now()->subHours(25), 'expires_at' => now()->subHour()]);
        $this->midtransStatusReturns($this->midtransPayload($attempt, ['transaction_status' => 'pending', 'status_code' => '201']));

        $this->artisan('midtrans:reconcile')->assertExitCode(0);

        $this->assertSame('pending', PaymentAttempt::withoutCompany()->findOrFail($attempt->id)->status);
    }

    public function test_a_closed_page_midtrans_has_cancelled_stays_closed_and_is_counted_as_closed(): void
    {
        $closed = $this->attemptFor(attempt: ['status' => 'cancelled_locally']);
        $this->midtransStatusReturns($this->midtransPayload($closed, ['transaction_status' => 'cancel', 'status_code' => '200']));

        $this->artisan('midtrans:reconcile')
            ->expectsTable(['Checked', 'Settled', 'Expired', 'Exceptions', 'Failed', 'Still pending', 'Closed', 'Errors'], [[1, 0, 0, 0, 0, 0, 1, 0]])
            ->assertExitCode(0);

        $this->assertSame('cancelled_locally', PaymentAttempt::withoutCompany()->findOrFail($closed->id)->status);
        $this->assertSame(1, $this->statusCalls());
        $this->assertCount(0, Payment::withoutCompany()->get());
    }

    public function test_a_closed_page_midtrans_does_not_know_is_never_expired_by_the_reconciliation(): void
    {
        $closed = $this->attemptFor(attempt: ['status' => 'cancelled_locally', 'created_at' => now()->subHours(25), 'expires_at' => now()->subHour()]);

        $this->artisan('midtrans:reconcile')->assertExitCode(0);

        $this->assertSame('cancelled_locally', PaymentAttempt::withoutCompany()->findOrFail($closed->id)->status);
        $this->assertSame(1, $this->statusCalls());
        $this->assertSame(0, Activity::query()->where('log_name', 'payment_attempt')->where('description', 'expired')->count());
    }

    public function test_a_dry_run_leaves_a_closed_page_alone(): void
    {
        $closed = $this->attemptFor(attempt: ['status' => 'cancelled_locally']);
        $this->midtransStatusReturns($this->midtransPayload($closed));
        $activityBefore = Activity::query()->count();

        $this->artisan('midtrans:reconcile', ['--dry-run' => true])->expectsOutputToContain('[DRY RUN]')->assertExitCode(0);

        $this->assertSame('cancelled_locally', PaymentAttempt::withoutCompany()->findOrFail($closed->id)->status);
        $this->assertCount(0, Payment::withoutCompany()->get());
        $this->assertSame($activityBefore, Activity::query()->count());
    }

    public function test_a_dry_run_reports_without_changing_anything(): void
    {
        $settles = $this->attemptFor();
        $expires = $this->attemptFor(attempt: ['created_at' => now()->subHours(25), 'expires_at' => now()->subHour()]);
        $this->midtransStatusReply = fn (Request $request) => str_contains($request->url(), $settles->order_id)
            ? Http::response($this->midtransPayload($settles))
            : Http::response(['status_code' => '404', 'status_message' => 'The requested resource is not found'], 404);
        $activityBefore = Activity::query()->count();

        $this->artisan('midtrans:reconcile', ['--dry-run' => true])
            ->expectsOutputToContain('would apply paid to '.$settles->order_id)
            ->expectsOutputToContain('would expire '.$expires->order_id)
            ->expectsOutputToContain('[DRY RUN]')
            ->assertExitCode(0);

        $this->assertSame('pending', PaymentAttempt::withoutCompany()->findOrFail($settles->id)->status);
        $this->assertSame('pending', PaymentAttempt::withoutCompany()->findOrFail($expires->id)->status);
        $this->assertCount(0, Payment::withoutCompany()->get());
        $this->assertSame($activityBefore, Activity::query()->count());
    }

    public function test_the_company_option_never_touches_another_companys_attempts(): void
    {
        $companyB = Company::factory()->create(['is_active' => true]);
        $mine = $this->attemptFor();
        $theirs = $this->attemptFor($companyB);
        $this->midtransStatusReply = fn (Request $request) => Http::response($this->midtransPayload(str_contains($request->url(), $mine->order_id) ? $mine : $theirs));

        $this->artisan('midtrans:reconcile', ['--company' => $this->admin->company_id])->assertExitCode(0);

        $this->assertSame('paid', PaymentAttempt::withoutCompany()->findOrFail($mine->id)->status);
        $this->assertSame('pending', PaymentAttempt::withoutCompany()->findOrFail($theirs->id)->status);
        $this->assertSame(1, $this->statusCalls());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), $theirs->order_id));
    }

    public function test_without_the_option_every_active_company_is_reconciled_in_its_own_scope(): void
    {
        $companyB = Company::factory()->create(['is_active' => true]);
        $inactive = Company::factory()->create(['is_active' => false]);
        $a = $this->attemptFor();
        $b = $this->attemptFor($companyB);
        $c = $this->attemptFor($inactive);
        $this->midtransStatusReply = function (Request $request) use ($a, $b, $c) {
            foreach ([$a, $b, $c] as $attempt) {
                if (str_contains($request->url(), $attempt->order_id)) {
                    return Http::response($this->midtransPayload($attempt));
                }
            }

            return Http::response([], 404);
        };

        $this->artisan('midtrans:reconcile')->assertExitCode(0);

        $this->assertSame('paid', PaymentAttempt::withoutCompany()->findOrFail($a->id)->status);
        $this->assertSame('paid', PaymentAttempt::withoutCompany()->findOrFail($b->id)->status);
        $this->assertSame('pending', PaymentAttempt::withoutCompany()->findOrFail($c->id)->status);
        $this->assertSame($companyB->id, Payment::withoutCompany()->where('reference', $b->order_id)->value('company_id'));
    }

    public function test_a_failing_call_for_one_attempt_does_not_stop_the_others_and_leaves_it_pending(): void
    {
        $broken = $this->attemptFor();
        $healthy = $this->attemptFor();
        $this->midtransStatusReply = function (Request $request) use ($broken, $healthy) {
            if (str_contains($request->url(), $broken->order_id)) {
                throw new ConnectionException('cURL error 28: timeout');
            }

            return Http::response($this->midtransPayload($healthy));
        };

        $this->artisan('midtrans:reconcile')->expectsOutputToContain('Errors')->assertExitCode(0);

        $this->assertSame('pending', PaymentAttempt::withoutCompany()->findOrFail($broken->id)->status);
        $this->assertSame('paid', PaymentAttempt::withoutCompany()->findOrFail($healthy->id)->status);
        $this->assertCount(1, Payment::withoutCompany()->get());
    }

    public function test_a_forged_status_answer_is_an_error_and_changes_nothing(): void
    {
        $attempt = $this->attemptFor();
        $this->midtransStatusReturns([...$this->midtransPayload($attempt), 'signature_key' => str_repeat('0', 128)]);

        $this->artisan('midtrans:reconcile')->assertExitCode(0);

        $this->assertSame('pending', PaymentAttempt::withoutCompany()->findOrFail($attempt->id)->status);
        $this->assertCount(0, Payment::withoutCompany()->get());
    }

    public function test_with_no_company_taking_midtrans_it_exits_cleanly_and_does_nothing(): void
    {
        $this->attemptFor();
        $this->disableMidtrans();

        $this->artisan('midtrans:reconcile')->expectsOutputToContain('Midtrans is not configured; nothing to reconcile.')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_the_reconciliation_is_scheduled_every_fifteen_minutes_without_overlapping(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'midtrans:reconcile'));

        $this->assertNotNull($event, 'midtrans:reconcile is not scheduled.');
        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
