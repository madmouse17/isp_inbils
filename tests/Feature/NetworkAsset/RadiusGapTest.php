<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\Core\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Modules\Billing\Database\Factories\PaymentAttemptFactory;
use Modules\Billing\Models\Invoice;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\Service\Models\SpeedProfile;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithMidtrans;
use Tests\Traits\InteractsWithNasCoa;
use Tests\Traits\InteractsWithRadius;

/**
 * Gap matrix of .scratch/freeradius/spec.md "Testing Decisions": rows no other test drives end to end.
 *
 * Row -> test
 *  - blocked + overdue suspension + clearing payment stays isolated (cash)    -> test_a_blocked_subscription_stays_isolated_through_overdue_suspension_and_a_clearing_cash_payment
 *  - same, Midtrans settlement                                               -> test_a_midtrans_settlement_clears_the_overdue_but_the_block_keeps_the_subscriber_isolated
 *  - company A never touches company B usernames / NAS rows                  -> test_terminating_in_company_a_leaves_company_b_rows_nas_and_sessions_alone
 *  - switch off: zero RADIUS queries, zero Disconnects over a full lifecycle -> test_with_the_switch_off_a_full_lifecycle_runs_no_radius_query_and_sends_no_disconnect
 *
 * Already covered elsewhere (not repeated):
 *  - unblock of a suspended subscription keeps isolir -> ProvisioningFlowTest::test_unblocking_a_suspended_subscription_keeps_it_isolated_until_it_is_reactivated
 *  - Disconnect timeout -> pending -> network:sync    -> ProvisioningFlowTest::test_a_disconnect_nobody_answers_leaves_the_account_pending_and_the_sync_catches_up
 *  - NAK 503 is done                                  -> ProvisioningFlowTest::test_a_nak_session_context_not_found_counts_as_done
 *  - unknown NAS address not sent and reported        -> ProvisioningFlowTest::test_a_session_on_an_address_no_nas_of_the_company_owns_is_not_sent_and_not_retried
 *  - job of company A reads nothing of company B      -> ProvisioningFlowTest::test_a_job_for_company_a_never_touches_company_b, NetworkSyncTest::test_the_company_option_limits_the_sync_and_without_it_every_active_company_is_synced
 *  - speed profile edit rewrites only its groups      -> ProvisioningFlowTest::test_editing_a_speed_profile_rewrites_only_that_companys_speed_group_and_sends_nothing
 *  - radius off for the company, single job and sync  -> ProvisioningFlowTest::test_a_company_without_an_enabled_radius_..., NetworkSyncTest::test_with_radius_not_configured_for_any_company_the_sync_does_nothing_at_all
 */
class RadiusGapTest extends TestCase
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

    private string $nasIp;

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
        $this->nas = $this->nasClient($this->company);
        $this->nasIp = (string) $this->nas->nasname;
        $this->coaAnswers($this->nasIp, 'ack');
        $this->subscription = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRadiusProfile($this->company, 'home-20m'));
        $this->account = $this->networkAccountFor($this->subscription);
        $this->actingAs($this->admin);
    }

    private function group(string $username = 'alice', ?Company $company = null): ?string
    {
        $realm = $this->realmOf($company ?? $this->company);

        return $this->radiusRows('radusergroup', ['username' => $username.'@'.$realm])[0]['groupname'] ?? null;
    }

    /** The account is written and the subscriber is online on the company NAS. */
    private function online(): void
    {
        $this->applyAccount($this->account);
        $this->seedRadiusSession(['username' => "alice@{$this->realm}", 'nasipaddress' => $this->nasIp]);
    }

    private function runOverdueSuspension(): void
    {
        Auth::logout();
        $this->artisan('billing:suspend-overdue')->assertSuccessful();
    }

    public function test_a_blocked_subscription_stays_isolated_through_overdue_suspension_and_a_clearing_cash_payment(): void
    {
        $invoice = $this->invoiceFor($this->subscription, 10);
        $this->online();
        SubscriptionService::block($this->subscription, 'abuse');

        $this->runOverdueSuspension();

        $this->assertSame('suspended', $this->freshSubscription($this->subscription)->status);
        $this->assertSame("{$this->realm}/isolir", $this->group());

        $this->actingAs($this->admin)
            ->post(route('admin.invoices.payments.store', $invoice), ['amount' => 100000, 'method' => 'cash'])
            ->assertRedirect();

        $fresh = $this->freshSubscription($this->subscription);
        $this->assertSame('active', $fresh->status, 'the payment reconnects the billing state');
        $this->assertNotNull($fresh->blocked_at, 'a payment never lifts the block');
        $this->assertSame("{$this->realm}/isolir", $this->group());

        SubscriptionService::unblock($fresh);

        $this->assertSame("{$this->realm}/home-20m", $this->group());
    }

    public function test_a_midtrans_settlement_clears_the_overdue_but_the_block_keeps_the_subscriber_isolated(): void
    {
        $this->enableMidtrans();
        $this->fakeMidtrans();
        $invoice = $this->invoiceFor($this->subscription, 10);
        $this->online();
        SubscriptionService::block($this->subscription, 'fraud');
        $this->runOverdueSuspension();
        $notification = $this->midtransPayload(PaymentAttemptFactory::new()->forInvoice($invoice)->create());
        $this->midtransStatusReturns($notification);
        CompanyService::useCompanyForTests(null);

        $this->postJson('/webhooks/midtrans', $notification)->assertOk();

        $this->assertSame('paid', Invoice::withoutCompany()->findOrFail($invoice->id)->status);
        $fresh = $this->freshSubscription($this->subscription);
        $this->assertSame('active', $fresh->status);
        $this->assertNotNull($fresh->blocked_at);
        $this->assertSame("{$this->realm}/isolir", $this->group());
    }

    public function test_terminating_in_company_a_leaves_company_b_rows_nas_and_sessions_alone(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $theirNas = $this->nasClient($other);
        $this->coaAnswers((string) $theirNas->nasname, 'ack');
        $theirRealm = $this->realmOf($other);
        $theirs = $this->networkAccountFor($this->subscriptionIn($other, 'active', null, $this->packageWithRadiusProfile($other, 'home-20m')), ['username' => "zed@{$theirRealm}"]);
        $this->applyAccount($theirs);
        $this->seedRadiusSession(['username' => "zed@{$theirRealm}", 'nasipaddress' => (string) $theirNas->nasname]);
        $this->online();
        $this->artisan('network:sync')->assertSuccessful();
        $tables = ['radcheck', 'radreply', 'radusergroup', 'radgroupreply'];
        $before = fn (): array => array_map(
            fn (string $table): array => array_values(array_filter(
                $this->radiusRows($table),
                fn (array $row): bool => str_ends_with((string) ($row['username'] ?? $row['groupname']), "@{$theirRealm}") || str_starts_with((string) ($row['groupname'] ?? ''), "{$theirRealm}/"),
            )),
            $tables,
        );
        $theirRows = $before();
        $theirNasRow = $this->radiusRows('nas', ['nasname' => $theirNas->nasname]);
        $this->assertNotEmpty($theirNasRow, 'company B NAS was written by the sync');
        $this->assertNotEmpty($theirRows[0]);
        $this->fakeCoa = null;
        $this->coaAnswers($this->nasIp, 'ack');
        $this->coaAnswers((string) $theirNas->nasname, 'ack');

        SubscriptionService::terminate($this->subscription->fresh(), 'moved away');
        SpeedProfile::query()->findOrFail($this->subscription->servicePackage->speed_profile_id)->update(['download_max_mbps' => 100]);

        $this->assertSame([], $this->radiusRows('radcheck', ['username' => "alice@{$this->realm}"]));
        $this->assertSame($theirRows, $before());
        $this->assertSame($theirNasRow, $this->radiusRows('nas', ['nasname' => $theirNas->nasname]));
        $this->assertSame("{$theirRealm}/home-20m", $this->group('zed', $other));
        $this->assertNotContains((string) $theirNas->nasname, array_column($this->disconnectsSent(), 'host'));
    }

    public function test_with_the_switch_off_a_full_lifecycle_runs_no_radius_query_and_sends_no_disconnect(): void
    {
        $this->seedRadiusSession(['username' => "alice@{$this->realm}", 'nasipaddress' => $this->nasIp]);
        $radius = $this->radiusConnection();
        $radius->flushQueryLog();
        $radius->enableQueryLog();
        $this->disableRadius();
        $this->fakeCoa = null;
        $this->coaAnswers($this->nasIp, 'ack');
        $bigger = $this->packageWithRadiusProfile($this->company, 'home-50m', 50, 25);
        $invoice = $this->invoiceFor($this->subscription, 10);

        $this->applyAccount($this->account);
        SubscriptionService::block($this->subscription, 'abuse');
        $this->runOverdueSuspension();
        $this->actingAs($this->admin)
            ->post(route('admin.invoices.payments.store', $invoice), ['amount' => 100000, 'method' => 'cash'])
            ->assertRedirect();
        SubscriptionService::unblock($this->freshSubscription($this->subscription));
        $this->freshSubscription($this->subscription)->update(['service_package_id' => $bigger->id, 'service_package_version_id' => $bigger->versions()->value('id')]);
        SpeedProfile::query()->findOrFail($bigger->speed_profile_id)->update(['download_max_mbps' => 60]);
        $this->artisan('network:sync')->assertSuccessful();
        SubscriptionService::terminate($this->freshSubscription($this->subscription), 'moved away');

        $this->assertSame([], $radius->getQueryLog(), 'no RADIUS query of any kind');
        $this->assertSame([], $this->disconnectsSent());
        $this->assertSame('terminated', $this->freshSubscription($this->subscription)->status, 'the office actions still succeeded');
    }
}
