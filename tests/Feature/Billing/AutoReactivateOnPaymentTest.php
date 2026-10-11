<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Billing\Database\Factories\PaymentAttemptFactory;
use Modules\Billing\Events\PaymentRecorded;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Services\BillingService;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithMidtrans;

class AutoReactivateOnPaymentTest extends TestCase
{
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use InteractsWithMidtrans;
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 15, 8));
        $this->admin = $this->createCompanyUser();
        $this->company = $this->admin->company;
        $this->company->update(['settings' => ['auto_suspend_overdue_days' => 7]]);
    }

    /** @return array{0: ServiceSubscription, 1: Invoice} an isolated subscription and the invoice behind it */
    private function isolated(string $source = 'overdue', int $daysPastDue = 10): array
    {
        $subscription = $this->subscriptionIn($this->company, 'suspended', $source);

        return [$subscription, $this->invoiceFor($subscription, $daysPastDue)];
    }

    private function pay(Invoice $invoice, int $amount = 100000): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.invoices.payments.store', $invoice), ['amount' => $amount, 'method' => 'cash'])
            ->assertRedirect();
    }

    public function test_paying_the_last_eligible_invoice_reconnects_an_overdue_isolation(): void
    {
        [$subscription, $invoice] = $this->isolated();

        $this->pay($invoice);

        $reconnected = $this->freshSubscription($subscription);
        $this->assertSame('active', $reconnected->status);
        $this->assertNull($reconnected->suspension_source);
        $this->assertSame(1, Activity::query()->where('description', 'reactivated')->where('subject_id', $subscription->id)->count());
    }

    public function test_a_manual_suspension_is_never_lifted_by_a_payment(): void
    {
        [$subscription, $invoice] = $this->isolated('manual');

        $this->pay($invoice);

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('suspended', $this->freshSubscription($subscription)->status);
        $this->assertSame('manual', $this->freshSubscription($subscription)->suspension_source);
    }

    public function test_a_suspension_without_a_recorded_source_is_never_lifted_by_a_payment(): void
    {
        $subscription = $this->subscriptionIn($this->company, 'suspended');
        $invoice = $this->invoiceFor($subscription, 10);

        $this->pay($invoice);

        $this->assertSame('suspended', $this->freshSubscription($subscription)->status);
    }

    public function test_another_eligible_invoice_blocks_the_reconnection_until_it_is_paid_too(): void
    {
        [$subscription, $first] = $this->isolated();
        $second = $this->invoiceFor($subscription, 40);

        $this->pay($first);
        $this->assertSame('suspended', $this->freshSubscription($subscription)->status);

        $this->pay($second);
        $this->assertSame('active', $this->freshSubscription($subscription)->status);
    }

    public function test_an_invoice_still_inside_the_grace_does_not_block_the_reconnection(): void
    {
        [$subscription, $overdue] = $this->isolated();
        $this->invoiceFor($subscription, 3);

        $this->pay($overdue);

        $this->assertSame('active', $this->freshSubscription($subscription)->status);
    }

    public function test_a_partial_payment_of_the_only_eligible_invoice_does_not_reconnect(): void
    {
        [$subscription, $invoice] = $this->isolated();

        $this->pay($invoice, 40000);

        $this->assertSame('partial', $invoice->fresh()->status);
        $this->assertSame('suspended', $this->freshSubscription($subscription)->status);

        $this->pay($invoice, 60000);
        $this->assertSame('active', $this->freshSubscription($subscription)->status);
    }

    public function test_the_company_setting_can_switch_the_reconnection_off(): void
    {
        $this->company->update(['settings' => ['auto_suspend_overdue_days' => 7, 'auto_reactivate_on_payment' => false]]);
        [$subscription, $invoice] = $this->isolated();

        $this->pay($invoice);

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('suspended', $this->freshSubscription($subscription)->status);
    }

    public function test_a_payment_after_a_manual_reactivation_or_for_an_invoice_without_subscription_is_harmless(): void
    {
        [$subscription, $invoice] = $this->isolated();
        $this->actingAs($this->admin)->post(route('admin.subscriptions.reactivate', $subscription))->assertRedirect();
        $withoutSubscription = $this->invoiceFor($subscription, 10, ['subscription_id' => null]);

        $this->pay($invoice);
        $this->pay($withoutSubscription);

        $this->assertSame('active', $this->freshSubscription($subscription)->status);
        $this->assertSame(2, Payment::query()->count());
    }

    public function test_a_payment_whose_transaction_rolls_back_reconnects_nothing(): void
    {
        [$subscription, $invoice] = $this->isolated();
        $this->actingAs($this->admin);

        try {
            DB::transaction(function () use ($invoice) {
                BillingService::recordPayment($invoice, 100000, 'cash');

                throw new RuntimeException('the surrounding work failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame('sent', $invoice->fresh()->status);
        $this->assertSame('suspended', $this->freshSubscription($subscription)->status);
    }

    public function test_a_midtrans_settlement_reconnects_inside_the_invoice_company_without_any_ambient_company(): void
    {
        $this->enableMidtrans();
        $this->fakeMidtrans();
        [$subscription, $invoice] = $this->isolated();
        $attempt = PaymentAttemptFactory::new()->forInvoice($invoice)->create();
        $notification = $this->midtransPayload($attempt);
        $this->midtransStatusReturns($notification);
        Auth::logout();
        CompanyService::useCompanyForTests(null);

        $this->postJson('/webhooks/midtrans', $notification)->assertOk();

        $this->assertSame('paid', Invoice::withoutCompany()->findOrFail($invoice->id)->status);
        $this->assertSame('active', $this->freshSubscription($subscription)->status);
        $this->assertNull($this->freshSubscription($subscription)->suspension_source);
    }

    public function test_a_payment_in_one_company_never_reconnects_another_companys_subscription(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $theirs = $this->subscriptionIn($other, 'suspended', 'overdue');
        $this->invoiceFor($theirs, 10);
        [, $invoice] = $this->isolated();

        $this->pay($invoice);

        $this->assertSame('suspended', $this->freshSubscription($theirs)->status);
    }

    public function test_recording_a_payment_announces_it_after_commit_with_the_three_ids(): void
    {
        Event::fake([PaymentRecorded::class]);
        [, $invoice] = $this->isolated();
        $this->actingAs($this->admin);

        $payment = BillingService::recordPayment($invoice, 100000, 'cash');

        Event::assertDispatchedTimes(PaymentRecorded::class, 1);
        Event::assertDispatched(PaymentRecorded::class, fn (PaymentRecorded $event): bool => $event->companyId === $this->company->id
            && $event->invoiceId === $invoice->id
            && $event->paymentId === $payment->id);
    }
}
