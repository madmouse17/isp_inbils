<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\Core\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Modules\Billing\Database\Factories\PaymentAttemptFactory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Services\BillingService;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithMidtrans;
use Tests\Traits\InteractsWithNasCoa;
use Tests\Traits\InteractsWithRadius;

/**
 * Matrix rows of the isolir policy that need RADIUS and the payment path together: an overdue
 * subscription is isolated in the fake RADIUS database, a Midtrans settlement brings it back through the webhook,
 * and a payment reversed afterwards is caught by the next automatic run.
 */
class IsolirPolicyGapTest extends TestCase
{
    use BuildsNetworkFixtures;
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use InteractsWithMidtrans;
    use InteractsWithNasCoa;
    use InteractsWithRadius;
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private NasDevice $nas;

    private string $realm;

    private ServiceSubscription $subscription;

    private NetworkAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 15, 8));
        $this->admin = $this->createCompanyUser();
        $this->company = $this->admin->company;
        $this->company->update(['settings' => ['auto_suspend_enabled' => true, 'auto_suspend_overdue_days' => 7]]);
        $this->realm = $this->realmOf($this->company);
        $this->fakeRadius();
        $this->enableMidtrans();
        $this->fakeMidtrans();
        $this->nas = $this->nasClient($this->company);
        $this->coaAnswers((string) $this->nas->nasname, 'ack');
        $this->subscription = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRadiusProfile($this->company, 'home-20m'));
        $this->account = $this->networkAccountFor($this->subscription);
        $this->applyAccount($this->account);
        $this->openSession();
        $this->actingAs($this->admin);
    }

    public function test_a_midtrans_settlement_brings_the_secret_back_to_its_speed_profile_and_a_repeated_notification_changes_nothing(): void
    {
        $invoice = $this->invoiceFor($this->subscription, 10);
        $this->isolate();
        $this->openSession(); // the customer dialled in again, in the isolir group
        $attempt = PaymentAttemptFactory::new()->forInvoice($invoice)->create();
        $notification = $this->midtransPayload($attempt);
        $this->midtransStatusReturns($notification);
        $this->app['auth']->logout(); // Midtrans calls with no session, no user and no ambient company
        CompanyService::useCompanyForTests(null);

        $this->postJson('/webhooks/midtrans', $notification)->assertOk();

        $this->assertSame('paid', Invoice::withoutCompany()->findOrFail($invoice->id)->status);
        $subscription = $this->freshSubscription($this->subscription);
        $this->assertSame('active', $subscription->status);
        $this->assertNull($subscription->suspension_source);
        $this->assertSame("{$this->realm}/home-20m", $this->group());
        $this->assertCount(1, $this->disconnectsSent(), 'the isolated session is cut so the customer dials in on the speed group');
        $account = $this->account->fresh();
        $this->assertSame('synced', $account->sync_status);
        $this->assertSame("{$this->realm}/home-20m", $account->provisioned_group);

        $this->forgetDisconnects();
        $this->postJson('/webhooks/midtrans', $notification)->assertOk();

        $this->assertSame([], $this->disconnectsSent(), 'Midtrans repeats notifications: nothing is paid or reconnected twice');
        $this->assertSame(1, Payment::withoutCompany()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(1, Activity::query()->where('description', 'reactivated')->where('subject_id', $this->subscription->id)->count());
    }

    public function test_a_payment_reversed_after_the_reconnection_isolates_the_subscription_again_at_the_next_automatic_run(): void
    {
        $invoice = $this->invoiceFor($this->subscription, 10);
        $this->isolate();
        $payment = BillingService::recordPayment($invoice, 100000, 'transfer', 'BANK-1');
        $this->assertSame('active', $this->freshSubscription($this->subscription)->status, 'the payment reconnected it');
        $this->assertSame("{$this->realm}/home-20m", $this->group());
        $this->openSession();
        $this->forgetDisconnects();

        $reviewer = User::factory()->create(['company_id' => $this->company->id, 'is_active' => true]);
        $this->actingAs($reviewer);
        BillingService::reversePayment($payment, 'the bank returned the transfer');
        $this->actingAs($this->admin);

        $this->assertSame('sent', $invoice->fresh()->status, 'the invoice owes money again');
        $this->assertSame('active', $this->freshSubscription($this->subscription)->status, 'a reversal alone changes nothing on the network');
        $this->assertSame([], $this->disconnectsSent());

        $this->assertSame(0, Artisan::call('billing:suspend-overdue', ['--company' => $this->company->id]));

        $subscription = $this->freshSubscription($this->subscription);
        $this->assertSame('suspended', $subscription->status);
        $this->assertSame('overdue', $subscription->suspension_source);
        $this->assertSame("{$this->realm}/isolir", $this->group());
        $this->assertCount(1, $this->disconnectsSent());
        $this->assertSame("{$this->realm}/isolir", $this->account->fresh()->provisioned_group);

        BillingService::recordPayment($invoice->fresh(), 100000, 'transfer', 'BANK-2');

        $this->assertSame('active', $this->freshSubscription($this->subscription)->status, 'the second payment reconnects it again');
        $this->assertSame("{$this->realm}/home-20m", $this->group());
        $this->assertSame(2, Activity::query()->where('description', 'suspended')->where('subject_id', $this->subscription->id)->count());
        $this->assertSame(2, Activity::query()->where('description', 'reactivated')->where('subject_id', $this->subscription->id)->count());
    }

    public function test_a_partial_payment_leaves_the_user_isolated_and_the_payment_that_clears_the_invoice_restores_it(): void
    {
        $invoice = $this->invoiceFor($this->subscription, 10);
        $this->isolate();
        $this->forgetDisconnects();

        BillingService::recordPayment($invoice, 40000, 'cash');

        $this->assertSame('suspended', $this->freshSubscription($this->subscription)->status);
        $this->assertSame([], $this->disconnectsSent(), 'a part payment does not touch the network');
        $this->assertSame("{$this->realm}/isolir", $this->group());

        BillingService::recordPayment($invoice->fresh(), 60000, 'cash');

        $this->assertSame('active', $this->freshSubscription($this->subscription)->status);
        $this->assertSame("{$this->realm}/home-20m", $this->group());
    }

    public function test_a_payment_never_lifts_a_block_and_the_overdue_isolir_still_applies_to_a_blocked_subscription(): void
    {
        $invoice = $this->invoiceFor($this->subscription, 10);
        SubscriptionService::block($this->subscription, 'abuse');
        $this->assertSame("{$this->realm}/isolir", $this->group());

        $this->assertSame(0, Artisan::call('billing:suspend-overdue', ['--company' => $this->company->id]));

        $suspended = $this->freshSubscription($this->subscription);
        $this->assertSame('suspended', $suspended->status);
        $this->assertNotNull($suspended->blocked_at);

        BillingService::recordPayment($invoice, 100000, 'transfer', 'BANK-3');

        $subscription = $this->freshSubscription($this->subscription);
        $this->assertSame('active', $subscription->status);
        $this->assertNotNull($subscription->blocked_at);
        $this->assertSame("{$this->realm}/isolir", $this->group(), 'the block keeps the subscriber isolated');
    }

    public function test_running_the_automatic_isolir_again_changes_nothing_in_radius_or_in_the_audit(): void
    {
        $this->invoiceFor($this->subscription, 10);
        $this->isolate();
        $this->forgetDisconnects();
        $rows = $this->radiusRows('radusergroup');

        $this->assertSame(0, Artisan::call('billing:suspend-overdue', ['--company' => $this->company->id]));

        $this->assertStringContainsString('suspended: 0', Artisan::output());
        $this->assertSame([], $this->disconnectsSent());
        $this->assertSame($rows, $this->radiusRows('radusergroup'));
        $this->assertSame(1, Activity::query()->where('description', 'suspended')->where('subject_id', $this->subscription->id)->count());
    }

    /** The 08:00 run: the invoice is ten days overdue and the company allows seven. */
    private function isolate(): void
    {
        $this->assertSame(0, Artisan::call('billing:suspend-overdue', ['--company' => $this->company->id]));

        $subscription = $this->freshSubscription($this->subscription);
        $this->assertSame('suspended', $subscription->status);
        $this->assertSame('overdue', $subscription->suspension_source);
        $this->assertSame("{$this->realm}/isolir", $this->group());
        $this->assertCount(1, $this->disconnectsSent());
    }

    private function group(): ?string
    {
        return $this->radiusRows('radusergroup', ['username' => "alice@{$this->realm}"])[0]['groupname'] ?? null;
    }

    /** A new open session; the NAS ended the earlier ones. */
    private function openSession(): void
    {
        $this->radiusConnection()->table('radacct')->whereNull('acctstoptime')->update(['acctstoptime' => now()->format('Y-m-d H:i:s')]);
        $this->seedRadiusSession(['username' => "alice@{$this->realm}", 'nasipaddress' => $this->nas->nasname]);
        $this->forgetDisconnects();
    }

    private function forgetDisconnects(): void
    {
        $this->fakeCoa = null;
        $this->coaAnswers((string) $this->nas->nasname, 'ack');
    }
}
