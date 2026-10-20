<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Modules\Ipam\Events\IpAssignmentChanged;
use Modules\Ipam\Models\IpPrefix;
use Modules\Ipam\Services\IpAssignmentService;
use Modules\Ipam\Services\IpPrefixService;
use Modules\NetworkAsset\Jobs\ApplyNetworkAccountJob;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Services\NetworkAccountProvisioner;
use Modules\Service\Models\SpeedProfile;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithNasCoa;
use Tests\Traits\InteractsWithRadius;

class ProvisioningFlowTest extends TestCase
{
    use BuildsNetworkFixtures;
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
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

        $this->admin = $this->createCompanyUser();
        $this->company = $this->admin->company;
        $this->realm = $this->realmOf($this->company);
        $this->fakeRadius();
        $this->nas = $this->nasClient($this->company);
        $this->nasIp = (string) $this->nas->nasname;
        $this->coaAnswers($this->nasIp, 'ack');
        $this->subscription = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRadiusProfile($this->company, 'home-20m'));
        $this->account = $this->networkAccountFor($this->subscription);
        $this->actingAs($this->admin);
    }

    private function auditCount(string $event): int
    {
        return Activity::query()->where('log_name', 'network_account')->where('description', $event)->count();
    }

    /** The account written to RADIUS and online, as most flows start. */
    private function provisioned(): void
    {
        $this->applyAccount($this->account);
        $this->openSession();
        $this->forgetDisconnects();
    }

    /** A new open session; the NAS ended the earlier ones of that user unless `$keepOthers`. */
    private function openSession(string $username = 'alice', ?string $nasIp = null, bool $keepOthers = false): void
    {
        if (! $keepOthers) {
            $this->radiusConnection()->table('radacct')->where('username', $username.'@'.$this->realm)->whereNull('acctstoptime')->update(['acctstoptime' => now()->format('Y-m-d H:i:s')]);
        }

        $this->seedRadiusSession(['username' => $username.'@'.$this->realm, 'nasipaddress' => $nasIp ?? $this->nasIp]);
    }

    private function forgetDisconnects(): void
    {
        $this->fakeCoa = null;
        $this->coaAnswers($this->nasIp, 'ack');
    }

    private function group(string $username = 'alice'): ?string
    {
        return $this->radiusRows('radusergroup', ['username' => $username.'@'.$this->realm])[0]['groupname'] ?? null;
    }

    /** @return array<string, string> */
    private function replies(string $group): array
    {
        return collect($this->radiusRows('radgroupreply', ['groupname' => $group]))->pluck('value', 'attribute')->all();
    }

    /** The subscription gets `$address` from a /24 customer_static pool of the company (created on first use). */
    private function giveStaticAddress(string $address): void
    {
        $pool = IpPrefix::query()->where('usage', 'customer_static')->first()
            ?? IpPrefixService::create(['cidr' => '10.70.0.0/24', 'usage' => 'customer_static']);

        IpAssignmentService::assignToSubscription($this->subscription, $pool, $address);
    }

    /** @return list<string> the Framed-IP-Address values RADIUS holds for the user */
    private function framedIps(string $username = 'alice'): array
    {
        return array_column($this->radiusRows('radreply', ['username' => $username.'@'.$this->realm, 'attribute' => 'Framed-IP-Address']), 'value');
    }

    public function test_an_active_subscription_gets_its_user_in_the_speed_group_with_the_package_rate(): void
    {
        $this->applyAccount($this->account);

        $check = $this->radiusRows('radcheck', ['username' => "alice@{$this->realm}"]);
        $this->assertCount(1, $check);
        $this->assertSame('Cleartext-Password', $check[0]['attribute']);
        $this->assertSame(':=', $check[0]['op']);
        $this->assertSame('fake-subscriber-secret', $check[0]['value']);
        $this->assertSame("{$this->realm}/home-20m", $this->group());
        $this->assertSame(['Mikrotik-Group' => 'home-20m', 'Mikrotik-Rate-Limit' => '10M/20M', 'Acct-Interim-Interval' => '300'], $this->replies("{$this->realm}/home-20m"));
        $account = $this->account->fresh();
        $this->assertSame('synced', $account->sync_status);
        $this->assertSame("{$this->realm}/home-20m", $account->provisioned_group);
        $this->assertSame("alice@{$this->realm}", $account->provisioned_username);
        $this->assertNotNull($account->provisioned_at);
        $this->assertNull($account->last_error);
        $this->assertNull($account->pending_disconnect_at);
        $this->assertSame(1, $this->auditCount('provisioned'));
        $this->assertSame([], $this->disconnectsSent(), 'a first write has no session to cut');
    }

    public function test_a_pending_subscription_is_enabled_too_so_the_technician_can_test_on_site(): void
    {
        $pending = $this->subscriptionIn($this->company, 'pending', null, $this->packageWithRadiusProfile($this->company, 'home-50m', 50, 25));
        $account = $this->networkAccountFor($pending, ['username' => "bob@{$this->realm}"]);

        $this->applyAccount($account);

        $this->assertSame("{$this->realm}/home-50m", $this->group('bob'));
        $this->assertSame('25M/50M', $this->replies("{$this->realm}/home-50m")['Mikrotik-Rate-Limit']);
    }

    public function test_suspending_moves_the_user_to_the_isolir_group_and_cuts_the_session(): void
    {
        $this->provisioned();

        SubscriptionService::suspend($this->subscription, 'unpaid', 'overdue');

        $this->assertSame("{$this->realm}/isolir", $this->group());
        $this->assertSame(
            ['Mikrotik-Group' => 'isolir', 'Mikrotik-Address-List' => 'isolir', 'Acct-Interim-Interval' => '300'],
            $this->replies("{$this->realm}/isolir"),
        );
        $sent = $this->disconnectsSent();
        $this->assertCount(1, $sent);
        $this->assertSame($this->nasIp, $sent[0]['host']);
        $this->assertSame(1700, $sent[0]['port']);
        $this->assertSame("alice@{$this->realm}", $sent[0]['attributes']['User-Name']);
        $this->assertSame($this->nasIp, $sent[0]['attributes']['NAS-IP-Address']);
        $this->assertSame("{$this->realm}/isolir", $this->account->fresh()->provisioned_group);
        $this->assertSame(1, $this->auditCount('disconnected'));
    }

    public function test_reactivating_restores_the_speed_group_and_cuts_the_isolir_session(): void
    {
        $this->provisioned();
        SubscriptionService::suspend($this->subscription, 'unpaid', 'overdue');
        $this->openSession();
        $this->forgetDisconnects();

        SubscriptionService::reactivate($this->subscription);

        $this->assertSame("{$this->realm}/home-20m", $this->group());
        $this->assertCount(1, $this->disconnectsSent());
    }

    public function test_blocking_an_active_subscription_isolates_it_whatever_the_status_and_unblocking_restores_it(): void
    {
        $this->provisioned();

        SubscriptionService::block($this->subscription, 'abuse');

        $this->assertSame("{$this->realm}/isolir", $this->group());
        $this->assertCount(1, $this->disconnectsSent());
        $this->assertSame('active', $this->subscription->fresh()->status);

        $this->openSession();
        $this->forgetDisconnects();
        SubscriptionService::unblock($this->subscription->fresh());

        $this->assertSame("{$this->realm}/home-20m", $this->group());
        $this->assertCount(1, $this->disconnectsSent());
    }

    public function test_unblocking_a_suspended_subscription_keeps_it_isolated_until_it_is_reactivated(): void
    {
        $this->provisioned();
        SubscriptionService::block($this->subscription, 'abuse');
        SubscriptionService::suspend($this->subscription->fresh(), 'unpaid', 'overdue');

        SubscriptionService::unblock($this->subscription->fresh());

        $this->assertSame("{$this->realm}/isolir", $this->group());

        SubscriptionService::reactivate($this->subscription->fresh());

        $this->assertSame("{$this->realm}/home-20m", $this->group());
    }

    public function test_a_payment_reconnect_leaves_a_blocked_subscription_isolated(): void
    {
        $this->provisioned();
        SubscriptionService::suspend($this->subscription, 'unpaid', 'overdue');
        SubscriptionService::block($this->subscription->fresh(), 'fraud');

        SubscriptionService::reactivate($this->subscription->fresh());

        $this->assertSame('active', $this->subscription->fresh()->status);
        $this->assertSame("{$this->realm}/isolir", $this->group());
    }

    public function test_terminating_removes_the_user_cuts_the_session_and_clears_the_block(): void
    {
        $this->provisioned();
        SubscriptionService::block($this->subscription, 'abuse');
        $this->openSession();
        $this->forgetDisconnects();

        SubscriptionService::terminate($this->subscription->fresh(), 'moved away');

        $this->assertSame([], $this->radiusRows('radcheck'));
        $this->assertSame([], $this->radiusRows('radusergroup'));
        $this->assertCount(1, $this->disconnectsSent());
        $account = $this->account->fresh();
        $this->assertNull($account->provisioned_group);
        $this->assertNull($account->provisioned_username);
        $this->assertSame('synced', $account->sync_status);
        $this->assertNull($this->subscription->fresh()->blocked_at);
    }

    public function test_deleting_a_subscription_removes_its_user(): void
    {
        $this->provisioned();

        $this->subscription->delete();

        $this->assertSame([], $this->radiusRows('radcheck'));
        $this->assertCount(1, $this->disconnectsSent());
    }

    public function test_a_package_change_writes_the_new_group_and_cuts_the_session(): void
    {
        $this->provisioned();
        $bigger = $this->packageWithRadiusProfile($this->company, 'home-50m', 50, 25);

        $this->subscription->update(['service_package_id' => $bigger->id, 'service_package_version_id' => $bigger->versions()->value('id')]);

        $this->assertSame("{$this->realm}/home-50m", $this->group());
        $this->assertSame('25M/50M', $this->replies("{$this->realm}/home-50m")['Mikrotik-Rate-Limit']);
        $this->assertCount(1, $this->disconnectsSent());
    }

    public function test_a_change_that_does_not_concern_the_network_writes_and_sends_nothing(): void
    {
        $this->provisioned();
        $before = $this->radiusRows('radcheck');

        $this->subscription->update(['notes' => 'called the customer']);

        $this->assertSame($before, $this->radiusRows('radcheck'));
        $this->assertSame([], $this->disconnectsSent());
        $this->assertSame(1, $this->auditCount('provisioned'));
    }

    public function test_a_status_change_is_seen_even_when_the_model_is_saved_again_in_the_same_transaction(): void
    {
        $this->provisioned();

        DB::transaction(function () {
            $this->subscription->update(['status' => 'suspended']);
            $this->subscription->update(['notes' => 'second save']);
        });

        $this->assertSame("{$this->realm}/isolir", $this->group());
    }

    public function test_a_rolled_back_status_change_never_reaches_radius(): void
    {
        $this->provisioned();

        try {
            DB::transaction(function () {
                SubscriptionService::suspend($this->subscription, 'unpaid', 'overdue');

                throw new RuntimeException('the surrounding work failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->disconnectsSent());
        $this->assertSame("{$this->realm}/home-20m", $this->group());
    }

    public function test_renaming_the_username_removes_the_old_user_and_cuts_its_session(): void
    {
        $this->provisioned();

        $this->account->update(['username' => "alice2@{$this->realm}"]);
        $this->applyAccount($this->account);

        $this->assertSame([], $this->radiusRows('radcheck', ['username' => "alice@{$this->realm}"]));
        $this->assertSame([], $this->radiusRows('radusergroup', ['username' => "alice@{$this->realm}"]));
        $this->assertCount(1, $this->radiusRows('radcheck', ['username' => "alice2@{$this->realm}"]));
        $this->assertSame("{$this->realm}/home-20m", $this->group('alice2'));
        $sent = $this->disconnectsSent();
        $this->assertCount(1, $sent);
        $this->assertSame("alice@{$this->realm}", $sent[0]['attributes']['User-Name']);
        $this->assertSame("alice2@{$this->realm}", $this->account->fresh()->provisioned_username);
    }

    public function test_changing_the_secret_updates_radius_and_keeps_the_session(): void
    {
        $this->provisioned();

        $this->account->update(['password' => 'fake-new-subscriber-secret']);
        $this->applyAccount($this->account);

        $this->assertSame('fake-new-subscriber-secret', $this->radiusRows('radcheck')[0]['value']);
        $this->assertSame([], $this->disconnectsSent());
    }

    public function test_a_second_run_against_an_unchanged_radius_writes_nothing_and_audits_nothing(): void
    {
        $this->provisioned();
        $rows = [$this->radiusRows('radcheck'), $this->radiusRows('radusergroup'), $this->radiusRows('radgroupreply')];
        $provisionedAt = $this->account->fresh()->provisioned_at;

        $this->travel(1)->minute();
        $this->applyAccount($this->account);

        $this->assertSame($rows, [$this->radiusRows('radcheck'), $this->radiusRows('radusergroup'), $this->radiusRows('radgroupreply')], 'same rows, same ids: nothing was rewritten');
        $this->assertSame([], $this->disconnectsSent());
        $this->assertSame(1, $this->auditCount('provisioned'));
        $this->assertSame(0, $this->auditCount('drift_corrected'));
        $this->assertEquals($provisionedAt, $this->account->fresh()->provisioned_at);
    }

    public function test_a_manual_edit_in_radius_is_corrected_and_audited_as_drift_without_cutting_the_session(): void
    {
        $this->provisioned();
        $this->radiusConnection()->table('radusergroup')->where('username', "alice@{$this->realm}")->update(['groupname' => 'somebody-elses']);

        $this->applyAccount($this->account);

        $this->assertSame("{$this->realm}/home-20m", $this->group());
        $this->assertSame(1, $this->auditCount('drift_corrected'));
        $this->assertSame([], $this->disconnectsSent());
    }

    public function test_a_user_deleted_from_radius_is_created_again(): void
    {
        $this->provisioned();
        $this->radiusConnection()->table('radcheck')->delete();
        $this->radiusConnection()->table('radusergroup')->delete();

        $this->applyAccount($this->account);

        $this->assertCount(1, $this->radiusRows('radcheck'));
        $this->assertSame("{$this->realm}/home-20m", $this->group());
        $this->assertSame(1, $this->auditCount('drift_corrected'));
    }

    public function test_a_static_address_is_written_as_the_framed_ip_reply_of_the_user_beside_the_group_replies(): void
    {
        $this->giveStaticAddress('10.70.0.9');

        $this->applyAccount($this->account);

        $rows = $this->radiusRows('radreply', ['username' => "alice@{$this->realm}"]);
        $this->assertCount(1, $rows);
        $this->assertSame(['Framed-IP-Address', ':=', '10.70.0.9'], [$rows[0]['attribute'], $rows[0]['op'], $rows[0]['value']]);
        $this->assertSame(['Mikrotik-Group' => 'home-20m', 'Mikrotik-Rate-Limit' => '10M/20M', 'Acct-Interim-Interval' => '300'], $this->replies("{$this->realm}/home-20m"));
        $this->assertSame("{$this->realm}/home-20m", $this->group());
        $audit = Activity::query()->where('log_name', 'network_account')->where('description', 'provisioned')->firstOrFail();
        $this->assertSame('10.70.0.9', $audit->properties['framed_ip']);
    }

    public function test_a_subscription_without_a_static_address_gets_no_user_reply(): void
    {
        $this->applyAccount($this->account);

        $this->assertSame([], $this->radiusRows('radreply'));
    }

    public function test_assigning_an_address_to_an_online_subscriber_cuts_the_session_so_it_redials_with_the_address(): void
    {
        $this->provisioned();

        $this->giveStaticAddress('10.70.0.9');
        $this->applyAccount($this->account);

        $this->assertSame(['10.70.0.9'], $this->framedIps());
        $sent = $this->disconnectsSent();
        $this->assertCount(1, $sent);
        $this->assertSame("alice@{$this->realm}", $sent[0]['attributes']['User-Name']);
        $this->assertSame("{$this->realm}/home-20m", $this->group(), 'the group did not change: only the address did');
    }

    public function test_releasing_the_address_drops_the_framed_ip_keeps_the_other_replies_and_cuts_the_session(): void
    {
        $this->giveStaticAddress('10.70.0.9');
        $this->provisioned();

        IpAssignmentService::releaseForSubscription($this->subscription);
        $this->applyAccount($this->account);

        $this->assertSame([], $this->radiusRows('radreply'));
        $this->assertSame("{$this->realm}/home-20m", $this->group());
        $this->assertSame(['Mikrotik-Group' => 'home-20m', 'Mikrotik-Rate-Limit' => '10M/20M', 'Acct-Interim-Interval' => '300'], $this->replies("{$this->realm}/home-20m"));
        $this->assertCount(1, $this->disconnectsSent());
        $audit = Activity::query()->where('log_name', 'network_account')->where('description', 'provisioned')->latest('id')->firstOrFail();
        $this->assertSame(['10.70.0.9', null], [$audit->properties['previous_framed_ip'], $audit->properties['framed_ip']]);
    }

    public function test_changing_the_address_replaces_the_row_and_cuts_the_session(): void
    {
        $this->giveStaticAddress('10.70.0.9');
        $this->provisioned();
        Event::fake([IpAssignmentChanged::class]);

        IpAssignmentService::releaseForSubscription($this->subscription);
        $this->giveStaticAddress('10.70.0.10');
        $this->applyAccount($this->account);

        $this->assertSame(['10.70.0.10'], $this->framedIps());
        $this->assertCount(1, $this->disconnectsSent());
    }

    public function test_the_isolir_state_keeps_the_static_address_and_so_does_the_return_to_the_speed_group(): void
    {
        $this->giveStaticAddress('10.70.0.9');
        $this->provisioned();

        SubscriptionService::suspend($this->subscription, 'unpaid', 'overdue');

        $this->assertSame("{$this->realm}/isolir", $this->group());
        $this->assertSame(['10.70.0.9'], $this->framedIps());

        SubscriptionService::reactivate($this->subscription->fresh());

        $this->assertSame("{$this->realm}/home-20m", $this->group());
        $this->assertSame(['10.70.0.9'], $this->framedIps());
    }

    public function test_an_unchanged_static_address_makes_a_second_run_write_cut_and_audit_nothing(): void
    {
        $this->giveStaticAddress('10.70.0.9');
        $this->provisioned();
        $rows = $this->radiusRows('radreply');

        $this->travel(1)->minute();
        $this->applyAccount($this->account);

        $this->assertSame($rows, $this->radiusRows('radreply'), 'same rows, same ids: nothing was rewritten');
        $this->assertSame([], $this->disconnectsSent());
        $this->assertSame(1, $this->auditCount('provisioned'));
        $this->assertSame(0, $this->auditCount('drift_corrected'));
    }

    public function test_a_framed_ip_row_deleted_from_radius_is_drift_and_is_written_back(): void
    {
        $this->giveStaticAddress('10.70.0.9');
        $this->provisioned();
        $this->radiusConnection()->table('radreply')->delete();

        $this->assertTrue(app(NetworkAccountProvisioner::class)->hasDrifted($this->account->fresh()));

        $this->applyAccount($this->account);

        $this->assertSame(['10.70.0.9'], $this->framedIps());
        $this->assertFalse(app(NetworkAccountProvisioner::class)->hasDrifted($this->account->fresh()));
    }

    public function test_a_framed_ip_row_nobody_assigned_is_drift_and_is_removed(): void
    {
        $this->provisioned();
        $this->radiusConnection()->table('radreply')->insert(['username' => "alice@{$this->realm}", 'attribute' => 'Framed-IP-Address', 'op' => '=', 'value' => '203.0.113.5']);

        $this->assertTrue(app(NetworkAccountProvisioner::class)->hasDrifted($this->account->fresh()));

        $this->applyAccount($this->account);

        $this->assertSame([], $this->framedIps());
        $this->assertFalse(app(NetworkAccountProvisioner::class)->hasDrifted($this->account->fresh()));
    }

    public function test_assigning_an_address_reaches_radius_through_the_queue_without_any_manual_resync(): void
    {
        $this->provisioned();

        $this->giveStaticAddress('10.70.0.9');

        $this->assertSame(['10.70.0.9'], $this->framedIps());
        $this->assertCount(1, $this->disconnectsSent());
    }

    public function test_assigning_an_address_queues_one_apply_job_for_the_account(): void
    {
        Queue::fake();

        $this->giveStaticAddress('10.70.0.9');

        Queue::assertPushed(ApplyNetworkAccountJob::class, 1);
        Queue::assertPushed(ApplyNetworkAccountJob::class, fn (ApplyNetworkAccountJob $job): bool => $job->accountId === $this->account->id && $job->companyId === $this->company->id);
    }

    public function test_releasing_the_address_queues_one_apply_job_and_that_job_drops_the_framed_ip_row(): void
    {
        $this->giveStaticAddress('10.70.0.9');
        $this->provisioned();
        Queue::fake();

        IpAssignmentService::releaseForSubscription($this->subscription);

        Queue::assertPushed(ApplyNetworkAccountJob::class, 1);
        $this->assertSame(['10.70.0.9'], $this->framedIps(), 'RADIUS changes only when the queued job runs');

        Queue::pushed(ApplyNetworkAccountJob::class)->first()->handle(app(NetworkAccountProvisioner::class));

        $this->assertSame([], $this->radiusRows('radreply'));
    }

    public function test_an_address_of_a_subscription_without_a_network_account_queues_nothing(): void
    {
        $plain = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRadiusProfile($this->company, 'home-50m', 50, 25));
        $pool = IpPrefixService::create(['cidr' => '10.70.1.0/24', 'usage' => 'customer_static']);
        Queue::fake();

        IpAssignmentService::assignToSubscription($plain, $pool, '10.70.1.5');

        Queue::assertNotPushed(ApplyNetworkAccountJob::class);
    }

    public function test_a_company_without_an_enabled_radius_writes_nothing_sends_nothing_and_leaves_the_account_pending_with_the_reason(): void
    {
        $this->disableRadius();

        $this->applyAccount($this->account);

        $account = $this->account->fresh();
        $this->assertSame('pending', $account->sync_status);
        $this->assertSame('RADIUS belum dikonfigurasi untuk perusahaan ini (Pengaturan → Integrasi).', $account->last_error);
        $this->assertSame([], $this->radiusRows('radcheck'));
        $this->assertSame([], $this->disconnectsSent());
        $this->assertNull($account->provisioned_group);
    }

    public function test_a_disconnect_nobody_answers_leaves_the_account_pending_and_the_sync_catches_up(): void
    {
        $this->provisioned();
        $this->coaAnswers($this->nasIp, 'timeout');

        SubscriptionService::suspend($this->subscription, 'unpaid', 'overdue');

        $account = $this->account->fresh();
        $this->assertSame('pending', $account->sync_status);
        $this->assertNotNull($account->pending_disconnect_at);
        $this->assertSame('Router belum menjawab permintaan pemutusan sesi; dicoba lagi otomatis.', $account->last_error);
        $this->assertSame("{$this->realm}/isolir", $account->provisioned_group, 'the record holds the group RADIUS already got');
        $this->assertSame("{$this->realm}/isolir", $this->group(), 'RADIUS already holds the isolir group');

        $this->coaAnswers($this->nasIp, 'ack');
        $this->artisan('network:sync', ['--company' => $this->company->id])->assertSuccessful();

        $account = $this->account->fresh();
        $this->assertSame('synced', $account->sync_status);
        $this->assertNull($account->pending_disconnect_at);
        $this->assertNull($account->last_error);
        $this->assertSame("{$this->realm}/isolir", $account->provisioned_group);
    }

    public function test_written_group_is_recorded_while_disconnect_pending(): void
    {
        $this->provisioned();
        $written = $this->auditCount('provisioned');
        $this->coaAnswers($this->nasIp, 'timeout');

        SubscriptionService::block($this->subscription, 'abuse');

        $account = $this->account->fresh();
        $this->assertSame("{$this->realm}/isolir", $this->group(), 'RADIUS holds the isolir group');
        $this->assertSame("{$this->realm}/isolir", $account->provisioned_group, 'and so does the record the page shows');
        $this->assertNotNull($account->pending_disconnect_at, 'the Disconnect is still owed');
        $this->assertSame('pending', $account->sync_status);
        $this->assertSame($written + 1, $this->auditCount('provisioned'));

        $since = $account->pending_disconnect_at;
        $this->travel(10)->minutes();
        $this->artisan('network:sync', ['--company' => $this->company->id])->assertSuccessful();

        $this->assertEquals($since, $this->account->fresh()->pending_disconnect_at, 'a retry the router does not answer keeps the first moment');

        $this->coaAnswers($this->nasIp, 'ack');
        $this->artisan('network:sync', ['--company' => $this->company->id])->assertSuccessful();

        $account = $this->account->fresh();
        $this->assertNull($account->pending_disconnect_at);
        $this->assertSame('synced', $account->sync_status);
        $this->assertNull($account->last_error);
        $this->assertSame("{$this->realm}/isolir", $account->provisioned_group);
        $this->assertSame($written + 1, $this->auditCount('provisioned'), 'the retry audits the change once, not again');
        $this->assertSame("alice@{$this->realm}", $this->disconnectsSent()[array_key_last($this->disconnectsSent())]['attributes']['User-Name']);
    }

    public function test_a_rename_keeps_the_old_record_until_the_old_name_was_disconnected(): void
    {
        $this->provisioned();
        $this->coaAnswers($this->nasIp, 'timeout');

        $this->account->update(['username' => "alice2@{$this->realm}"]);
        $this->applyAccount($this->account);

        $account = $this->account->fresh();
        $this->assertSame("alice@{$this->realm}", $account->provisioned_username, 'only the old record remembers whose session is owed');
        $this->assertNotNull($account->pending_disconnect_at);
        $this->assertSame("{$this->realm}/home-20m", $this->group('alice2'), 'the new user is already written');

        $this->coaAnswers($this->nasIp, 'ack');
        $this->artisan('network:sync', ['--company' => $this->company->id])->assertSuccessful();

        $account = $this->account->fresh();
        $this->assertSame("alice2@{$this->realm}", $account->provisioned_username);
        $this->assertNull($account->pending_disconnect_at);
        $this->assertSame("alice@{$this->realm}", $this->disconnectsSent()[array_key_last($this->disconnectsSent())]['attributes']['User-Name'], 'the old name still got its Disconnect');
    }

    public function test_a_removal_keeps_the_old_record_until_the_session_was_cut(): void
    {
        $this->provisioned();
        $this->coaAnswers($this->nasIp, 'timeout');

        SubscriptionService::terminate($this->subscription->fresh(), 'moved away');

        $account = $this->account->fresh();
        $this->assertSame([], $this->radiusRows('radcheck'), 'the user is gone from RADIUS');
        $this->assertSame("{$this->realm}/home-20m", $account->provisioned_group, 'the old record keeps whose session is owed');
        $this->assertNotNull($account->pending_disconnect_at);

        $this->coaAnswers($this->nasIp, 'ack');
        $this->artisan('network:sync', ['--company' => $this->company->id])->assertSuccessful();

        $account = $this->account->fresh();
        $this->assertNull($account->provisioned_group);
        $this->assertNull($account->pending_disconnect_at);
        $this->assertSame("alice@{$this->realm}", $this->disconnectsSent()[array_key_last($this->disconnectsSent())]['attributes']['User-Name']);
    }

    public function test_a_nak_session_context_not_found_counts_as_done(): void
    {
        $this->provisioned();
        $this->coaAnswers($this->nasIp, 'nak:503');

        SubscriptionService::suspend($this->subscription, 'unpaid', 'overdue');

        $this->assertSame('synced', $this->account->fresh()->sync_status);
        $this->assertNull($this->account->fresh()->pending_disconnect_at);
    }

    public function test_any_other_nak_is_retried(): void
    {
        $this->provisioned();
        $this->coaAnswers($this->nasIp, 'nak:401');

        SubscriptionService::suspend($this->subscription, 'unpaid', 'overdue');

        $this->assertSame('pending', $this->account->fresh()->sync_status);
        $this->assertNotNull($this->account->fresh()->pending_disconnect_at);
    }

    public function test_a_session_on_an_address_no_nas_of_the_company_owns_is_not_sent_and_not_retried(): void
    {
        $this->provisioned();
        $this->openSession('alice', '203.0.113.9', true);
        $this->forgetDisconnects();

        SubscriptionService::suspend($this->subscription, 'unpaid', 'overdue');

        $this->assertCount(1, $this->disconnectsSent(), 'only the session on the company NAS');
        $account = $this->account->fresh();
        $this->assertSame('synced', $account->sync_status);
        $this->assertSame('Sesi terbuka berasal dari router yang belum didaftarkan: 203.0.113.9.', $account->last_error);
        $this->assertNull($account->pending_disconnect_at);
    }

    public function test_a_session_on_the_nas_of_another_company_is_never_sent(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $theirNas = $this->nasClient($other);
        $this->provisioned();
        $this->openSession('alice', (string) $theirNas->nasname, true);
        $this->forgetDisconnects();
        $this->coaAnswers((string) $theirNas->nasname, 'ack');

        SubscriptionService::suspend($this->subscription, 'unpaid', 'overdue');

        $hosts = array_column($this->disconnectsSent(), 'host');
        $this->assertNotContains((string) $theirNas->nasname, $hosts);
        $this->assertStringContainsString((string) $theirNas->nasname, (string) $this->account->fresh()->last_error);
    }

    public function test_a_package_without_a_radius_profile_name_fails_the_account_without_touching_radius(): void
    {
        $nameless = $this->packageWithRadiusProfile($this->company, null);
        $this->subscription->forceFill(['service_package_id' => $nameless->id, 'service_package_version_id' => $nameless->versions()->value('id')])->saveQuietly();

        $this->applyAccount($this->account);

        $account = $this->account->fresh();
        $this->assertSame('failed', $account->sync_status);
        $this->assertSame('Profil kecepatan paket ini belum punya nama profil RADIUS.', $account->last_error);
        $this->assertSame([], $this->radiusRows('radcheck'));
        $this->assertSame([], $this->disconnectsSent());
        $this->assertSame(1, $this->auditCount('provision_failed'));
    }

    public function test_a_company_code_that_is_not_a_valid_realm_fails_the_account(): void
    {
        $this->company->forceFill(['code' => 'ACME_CORP'])->save();

        $this->applyAccount($this->account);

        $this->assertSame('failed', $this->account->fresh()->sync_status);
        $this->assertSame('Kode perusahaan tidak bisa dipakai sebagai realm RADIUS.', $this->account->fresh()->last_error);
        $this->assertSame([], $this->radiusRows('radcheck'));
    }

    public function test_an_unavailable_radius_database_keeps_the_account_pending_without_leaking_driver_text(): void
    {
        Schema::connection('radius')->drop('radcheck');

        $this->applyAccount($this->account);

        $account = $this->account->fresh();
        $this->assertSame('pending', $account->sync_status);
        $this->assertSame('Basis data RADIUS tidak dapat dihubungi; dicoba lagi otomatis.', $account->last_error);
    }

    public function test_an_exhausted_job_stores_no_secret_in_failed_jobs(): void
    {
        $this->account->update(['password' => 'leak-canary-subscriber-pass']);
        $this->radiusConnection()->unprepared('CREATE TRIGGER fail_radcheck BEFORE INSERT ON radcheck BEGIN SELECT RAISE(ABORT, \'boom\'); END');
        config(['queue.default' => 'database']);

        $this->applyAccount($this->account);
        DB::table('jobs')->update(['attempts' => 4]);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();

        $failed = DB::table('failed_jobs')->get();
        $this->assertCount(1, $failed);
        $this->assertStringNotContainsString('leak-canary', $failed[0]->exception);
        $this->assertStringContainsString('Basis data RADIUS tidak dapat dihubungi; dicoba lagi otomatis.', $failed[0]->exception);
        $this->assertSame('failed', $this->account->fresh()->sync_status);
    }

    public function test_business_actions_succeed_while_radius_is_down(): void
    {
        $this->provisioned();
        Schema::connection('radius')->drop('radusergroup');

        SubscriptionService::suspend($this->subscription, 'unpaid', 'overdue');

        $this->assertSame('suspended', $this->subscription->fresh()->status);
        $this->assertSame('pending', $this->account->fresh()->sync_status);
    }

    public function test_a_burst_of_changes_queues_one_run_per_account(): void
    {
        Queue::fake();

        ApplyNetworkAccountJob::dispatch((int) $this->company->id, $this->account->id);
        ApplyNetworkAccountJob::dispatch((int) $this->company->id, $this->account->id);
        ApplyNetworkAccountJob::dispatch((int) $this->company->id, $this->account->id + 1);

        Queue::assertPushed(ApplyNetworkAccountJob::class, 2);
    }

    public function test_a_job_for_company_a_never_touches_company_b(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $theirs = $this->networkAccountFor($this->subscriptionIn($other, 'active', null, $this->packageWithRadiusProfile($other, 'home-20m')), ['username' => 'zed@'.$this->realmOf($other)]);

        ApplyNetworkAccountJob::dispatch((int) $this->company->id, $theirs->id);

        $this->assertSame([], $this->radiusRows('radcheck'));
        $this->assertSame('pending', $theirs->fresh()->sync_status);
        $this->assertNull($theirs->fresh()->last_attempt_at);
    }

    public function test_the_observer_resolves_the_account_in_the_subscriptions_own_company(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $theirSubscription = $this->subscriptionIn($other, 'active', null, $this->packageWithRadiusProfile($other, 'home-20m'));
        $theirs = $this->networkAccountFor($theirSubscription, ['username' => 'zed@'.$this->realmOf($other)]);
        $this->applyAccount($theirs);
        $this->provisioned();

        // The ambient company is A; the subscription being changed belongs to B.
        ServiceSubscription::withoutCompany()->findOrFail($theirSubscription->id)->update(['status' => 'suspended']);

        $this->assertSame($this->realmOf($other).'/isolir', $this->radiusRows('radusergroup', ['username' => 'zed@'.$this->realmOf($other)])[0]['groupname']);
        $this->assertSame("{$this->realm}/home-20m", $this->group());
    }

    public function test_editing_a_speed_profile_rewrites_only_that_companys_speed_group_and_sends_nothing(): void
    {
        $this->provisioned();
        $other = Company::factory()->create(['is_active' => true]);
        $theirs = $this->networkAccountFor($this->subscriptionIn($other, 'active', null, $this->packageWithRadiusProfile($other, 'home-20m')), ['username' => 'zed@'.$this->realmOf($other)]);
        $this->applyAccount($theirs);
        $theirRates = $this->replies($this->realmOf($other).'/home-20m');
        $speed = SpeedProfile::query()->findOrFail($this->subscription->servicePackage->speed_profile_id);

        $speed->update(['download_max_mbps' => 100, 'upload_max_mbps' => 40]);

        $this->assertSame('40M/100M', $this->replies("{$this->realm}/home-20m")['Mikrotik-Rate-Limit']);
        $this->assertSame($theirRates, $this->replies($this->realmOf($other).'/home-20m'));
        $this->assertSame([], $this->disconnectsSent());
    }

    public function test_no_secret_reaches_the_audit_or_the_error_after_a_full_cycle(): void
    {
        $this->provisioned();
        SubscriptionService::block($this->subscription, 'abuse');
        $this->coaAnswers($this->nasIp, 'timeout');
        $this->openSession();
        SubscriptionService::unblock($this->subscription->fresh());
        $this->nas->forceFill(['radius_secret' => 'fake-radius-shared-secret'])->save();

        $dump = json_encode(Activity::query()->get()->map->properties).$this->account->fresh()->last_error;

        $this->assertStringNotContainsString('fake-subscriber-secret', $dump);
        $this->assertStringNotContainsString('fake-radius-shared-secret', $dump);
    }
}
