<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
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
use Tests\Support\FakeRouterOs;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithMidtrans;
use Tests\Traits\InteractsWithRouterOs;

/**
 * Matrix rows of the isolir policy that need the router and the payment path together: an overdue
 * subscription is isolated on the fake router, a Midtrans settlement brings it back through the webhook,
 * and a payment reversed afterwards is caught by the next automatic run.
 */
class IsolirPolicyGapTest extends TestCase
{
    use BuildsNetworkFixtures;
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use InteractsWithMidtrans;
    use InteractsWithRouterOs;
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private NasDevice $nas;

    private FakeRouterOs $router;

    private ServiceSubscription $subscription;

    private NetworkAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 15, 8));
        $this->admin = $this->createCompanyUser();
        $this->company = $this->admin->company;
        $this->company->update(['settings' => ['auto_suspend_enabled' => true, 'auto_suspend_overdue_days' => 7]]);
        $this->fakeRouterOs();
        $this->enableMidtrans();
        $this->fakeMidtrans();
        [$this->nas, $this->router] = $this->nasRouter($this->company);
        $this->subscription = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company, 'home-20m'));
        $this->account = $this->networkAccountFor($this->subscription, $this->nas);
        $this->applyAccount($this->account);
        $this->router->seedSession('pppoe', 'alice', '10.10.0.5');
        $this->router->forgetRequests();
        $this->actingAs($this->admin);
    }

    public function test_a_midtrans_settlement_brings_the_secret_back_to_its_speed_profile_and_a_repeated_notification_changes_nothing(): void
    {
        $invoice = $this->invoiceFor($this->subscription, 10);
        $this->isolate();
        $this->router->seedSession('pppoe', 'alice', '10.99.0.2'); // the customer dialled in again, on the isolir profile
        $this->router->forgetRequests();
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
        $secret = $this->router->account('pppoe', 'alice');
        $this->assertSame('home-20m', $secret['profile']);
        $this->assertSame('false', $secret['disabled']);
        $this->assertSame([], $this->router->sessions('pppoe', 'alice'), 'the isolated session is dropped so the customer dials in on the speed profile');
        $this->assertCount(2, $this->router->writes(), 'one profile change and one dropped session');
        $account = $this->account->fresh();
        $this->assertSame('synced', $account->sync_status);
        $this->assertSame('home-20m', $account->provisioned_profile);

        $this->router->forgetRequests();
        $this->postJson('/webhooks/midtrans', $notification)->assertOk();

        $this->assertSame([], $this->router->requests(), 'Midtrans repeats notifications: nothing is paid or reconnected twice');
        $this->assertSame(1, Payment::withoutCompany()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(1, Activity::query()->where('description', 'reactivated')->where('subject_id', $this->subscription->id)->count());
    }

    public function test_a_payment_reversed_after_the_reconnection_isolates_the_subscription_again_at_the_next_automatic_run(): void
    {
        $invoice = $this->invoiceFor($this->subscription, 10);
        $this->isolate();
        $payment = BillingService::recordPayment($invoice, 100000, 'transfer', 'BANK-1');
        $this->assertSame('active', $this->freshSubscription($this->subscription)->status, 'the payment reconnected it');
        $this->assertSame('home-20m', $this->router->account('pppoe', 'alice')['profile']);
        $this->router->seedSession('pppoe', 'alice', '10.10.0.7');
        $this->router->forgetRequests();

        $reviewer = User::factory()->create(['company_id' => $this->company->id, 'is_active' => true]);
        $this->actingAs($reviewer);
        BillingService::reversePayment($payment, 'the bank returned the transfer');
        $this->actingAs($this->admin);

        $this->assertSame('sent', $invoice->fresh()->status, 'the invoice owes money again');
        $this->assertSame('active', $this->freshSubscription($this->subscription)->status, 'a reversal alone changes nothing on the network');
        $this->assertSame([], $this->router->requests());

        $this->assertSame(0, Artisan::call('billing:suspend-overdue', ['--company' => $this->company->id]));

        $subscription = $this->freshSubscription($this->subscription);
        $this->assertSame('suspended', $subscription->status);
        $this->assertSame('overdue', $subscription->suspension_source);
        $this->assertSame('isolir', $this->router->account('pppoe', 'alice')['profile']);
        $this->assertSame([], $this->router->sessions('pppoe', 'alice'));
        $this->assertSame('isolir', $this->account->fresh()->provisioned_profile);

        BillingService::recordPayment($invoice->fresh(), 100000, 'transfer', 'BANK-2');

        $this->assertSame('active', $this->freshSubscription($this->subscription)->status, 'the second payment reconnects it again');
        $this->assertSame('home-20m', $this->router->account('pppoe', 'alice')['profile']);
        $this->assertSame(2, Activity::query()->where('description', 'suspended')->where('subject_id', $this->subscription->id)->count());
        $this->assertSame(2, Activity::query()->where('description', 'reactivated')->where('subject_id', $this->subscription->id)->count());
    }

    public function test_a_partial_payment_leaves_the_secret_isolated_and_the_payment_that_clears_the_invoice_restores_it(): void
    {
        $invoice = $this->invoiceFor($this->subscription, 10);
        $this->isolate();
        $this->router->forgetRequests();

        BillingService::recordPayment($invoice, 40000, 'cash');

        $this->assertSame('suspended', $this->freshSubscription($this->subscription)->status);
        $this->assertSame([], $this->router->requests(), 'a part payment does not touch the router');
        $this->assertSame('isolir', $this->router->account('pppoe', 'alice')['profile']);

        BillingService::recordPayment($invoice->fresh(), 60000, 'cash');

        $this->assertSame('active', $this->freshSubscription($this->subscription)->status);
        $this->assertSame('home-20m', $this->router->account('pppoe', 'alice')['profile']);
    }

    public function test_running_the_automatic_isolir_again_changes_nothing_on_the_router_or_in_the_audit(): void
    {
        $this->invoiceFor($this->subscription, 10);
        $this->isolate();
        $this->router->forgetRequests();

        $this->assertSame(0, Artisan::call('billing:suspend-overdue', ['--company' => $this->company->id]));

        $this->assertStringContainsString('suspended: 0', Artisan::output());
        $this->assertSame([], $this->router->requests());
        $this->assertSame(1, Activity::query()->where('description', 'suspended')->where('subject_id', $this->subscription->id)->count());
    }

    /** The 08:00 run: the invoice is ten days overdue and the company allows seven. */
    private function isolate(): void
    {
        $this->assertSame(0, Artisan::call('billing:suspend-overdue', ['--company' => $this->company->id]));

        $subscription = $this->freshSubscription($this->subscription);
        $this->assertSame('suspended', $subscription->status);
        $this->assertSame('overdue', $subscription->suspension_source);
        $this->assertSame('isolir', $this->router->account('pppoe', 'alice')['profile']);
        $this->assertSame([], $this->router->sessions('pppoe', 'alice'));
    }
}
