<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Modules\NetworkAsset\Jobs\ApplyNetworkAccountJob;
use Modules\NetworkAsset\Jobs\SyncNasDeviceJob;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Notifications\NasDeviceUnreachableNotification;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\Support\FakeRouterOs;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithRouterOs;

class NetworkSyncTest extends TestCase
{
    use BuildsNetworkFixtures;
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
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

        $this->admin = $this->createCompanyUser();
        $this->company = $this->admin->company;
        $this->fakeRouterOs();
        [$this->nas, $this->router] = $this->nasRouter($this->company);
        $this->subscription = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company, 'home-20m'));
        $this->account = $this->networkAccountFor($this->subscription, $this->nas);
    }

    private function sync(?NasDevice $nas = null): void
    {
        $nas ??= $this->nas;

        $this->artisan('network:sync', ['--company' => $nas->company_id, '--nas' => $nas->id])->assertSuccessful();
    }

    private function auditCount(string $log, string $event): int
    {
        return Activity::query()->where('log_name', $log)->where('description', $event)->count();
    }

    private function managerWhoCanManageRouters(): User
    {
        Permission::findOrCreate('network_asset.nas.manage', 'web');
        $manager = User::factory()->create(['company_id' => $this->company->id, 'is_active' => true, 'email_verified_at' => now()]);
        $manager->givePermissionTo('network_asset.nas.manage');

        return $manager;
    }

    public function test_the_probe_stores_the_version_and_the_check_times(): void
    {
        $this->travelTo('2026-10-15 09:00:00');

        $this->sync();

        $nas = $this->nas->fresh();
        $this->assertSame('7.16.2 (stable)', $nas->routeros_version);
        $this->assertSame('2026-10-15 09:00:00', $nas->last_checked_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-15 09:00:00', $nas->last_seen_at->format('Y-m-d H:i:s'));
        $this->assertNull($nas->last_error);
        $this->assertNull($nas->unreachable_since);
    }

    public function test_online_and_offline_accounts_are_updated(): void
    {
        $bob = $this->networkAccountFor($this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company, 'home-20m')), $this->nas, ['username' => 'bob']);
        $this->applyAccount($this->account);
        $this->applyAccount($bob);
        $bob->forceFill(['current_address' => '10.10.0.99'])->save();
        $this->router->seedSession('pppoe', 'alice', '10.10.0.5');
        $this->travelTo(now()->addMinutes(5));

        $this->sync();

        $alice = $this->account->fresh();
        $this->assertSame('10.10.0.5', $alice->current_address);
        $this->assertTrue($alice->last_online_at->gte($this->nas->fresh()->last_seen_at->subMinute()));
        $this->assertNull($bob->fresh()->current_address);
        $this->assertNull($bob->fresh()->last_online_at);
    }

    public function test_hotspot_sessions_are_read_from_the_hotspot_menu_by_user(): void
    {
        $hot = $this->networkAccountFor($this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company, 'home-20m')), $this->nas, ['username' => 'hot1', 'service_type' => 'hotspot']);
        $this->applyAccount($hot);
        $this->router->seedSession('hotspot', 'hot1', '10.20.0.8');

        $this->sync();

        $this->assertSame('10.20.0.8', $hot->fresh()->current_address);
    }

    public function test_a_pending_account_is_applied_by_the_sync(): void
    {
        $this->sync();

        $this->assertSame('home-20m', $this->router->account('pppoe', 'alice')['profile']);
        $this->assertSame('synced', $this->account->fresh()->sync_status);
    }

    public function test_drift_is_queued_corrected_and_audited(): void
    {
        $this->applyAccount($this->account);
        $this->router->changeAccount('pppoe', 'alice', ['profile' => 'home-50m']);
        $this->router->seedSession('pppoe', 'alice');

        $this->sync();

        $this->assertSame('home-20m', $this->router->account('pppoe', 'alice')['profile']);
        $this->assertSame(1, $this->auditCount('network_account', 'drift_corrected'));
    }

    public function test_a_secret_missing_on_the_router_is_created_again_by_the_sync(): void
    {
        $this->applyAccount($this->account);
        $this->router->removeAccount('pppoe', 'alice');

        $this->sync();

        $this->assertNotNull($this->router->account('pppoe', 'alice'));
        $this->assertSame(1, $this->auditCount('network_account', 'drift_corrected'));
    }

    public function test_an_account_that_matches_the_router_is_not_queued_again(): void
    {
        $this->applyAccount($this->account);
        $this->router->forgetRequests();

        $this->sync();

        $this->assertSame([], $this->router->writes());
        $this->assertSame(0, $this->auditCount('network_account', 'drift_corrected'));
    }

    public function test_the_unmanaged_list_is_capped_at_one_hundred_and_skips_inbils_secrets(): void
    {
        $this->applyAccount($this->account);
        foreach (range(1, 130) as $number) {
            $this->router->seedAccount('pppoe', sprintf('stray-%03d', $number));
        }
        $this->router->seedAccount('pppoe', 'managed-elsewhere', 'default', false, 'fake-pw', 'inbils:OTHER:SUB-9');

        $this->sync();

        $nas = $this->nas->fresh();
        $this->assertSame(130, $nas->unmanaged_count);
        $this->assertCount(100, $nas->unmanaged_usernames);
        $this->assertSame('stray-001', $nas->unmanaged_usernames[0]);
        $this->assertSame('stray-100', $nas->unmanaged_usernames[99]);
        $this->assertNotContains('alice', $nas->unmanaged_usernames);
        $this->assertNotContains('managed-elsewhere', $nas->unmanaged_usernames);
    }

    public function test_a_router_with_no_account_yet_still_lists_its_strays(): void
    {
        $empty = NasDevice::factory()->create(['company_id' => $this->company->id]);
        $this->routerFor($empty)->seedAccount('pppoe', 'old-customer');

        $this->sync($empty);

        $this->assertSame(['old-customer'], $empty->fresh()->unmanaged_usernames);
        $this->assertSame(1, $empty->fresh()->unmanaged_count);
    }

    public function test_one_notification_per_outage_then_recovery_requeues_the_pending_accounts(): void
    {
        Notification::fake();
        $manager = $this->managerWhoCanManageRouters();
        $outsider = User::factory()->create(['company_id' => Company::factory()->create(['is_active' => true])->id, 'is_active' => true]);
        $outsider->givePermissionTo('network_asset.nas.manage');
        $plain = User::factory()->create(['company_id' => $this->company->id, 'is_active' => true]);
        $this->router->down('refused');

        $this->sync();
        $this->sync();

        $nas = $this->nas->fresh();
        $this->assertNotNull($nas->unreachable_since);
        $this->assertStringContainsString('did not answer', $nas->last_error);
        Notification::assertSentToTimes($manager, NasDeviceUnreachableNotification::class, 1);
        Notification::assertNotSentTo($outsider, NasDeviceUnreachableNotification::class);
        Notification::assertNotSentTo($plain, NasDeviceUnreachableNotification::class);
        $this->assertSame(1, $this->auditCount('nas_device', 'unreachable'));
        $this->assertSame('pending', $this->account->fresh()->sync_status);

        $this->router->up();
        $this->sync();

        $this->assertNull($this->nas->fresh()->unreachable_since);
        $this->assertSame(1, $this->auditCount('nas_device', 'recovered'));
        $this->assertSame('home-20m', $this->router->account('pppoe', 'alice')['profile']);
        $this->assertSame('synced', $this->account->fresh()->sync_status);
        Notification::assertSentToTimes($manager, NasDeviceUnreachableNotification::class, 1);
    }

    public function test_a_second_outage_notifies_again(): void
    {
        Notification::fake();
        $manager = $this->managerWhoCanManageRouters();

        $this->router->down('refused');
        $this->sync();
        $this->router->up();
        $this->sync();
        $this->router->down('timeout');
        $this->sync();

        Notification::assertSentToTimes($manager, NasDeviceUnreachableNotification::class, 2);
    }

    public function test_the_notification_names_the_router_and_the_error_kind_but_no_credentials(): void
    {
        $notification = new NasDeviceUnreachableNotification($this->nas->id, 'AST-2026-00007', 'unreachable');

        $mail = $notification->toMail($this->admin)->render()->toHtml();
        $data = json_encode($notification->toArray($this->admin));

        $this->assertStringContainsString('AST-2026-00007', $mail);
        $this->assertStringContainsString('unreachable', $data);
        foreach ([$mail, $data] as $text) {
            $this->assertStringNotContainsString('fake-router-api-password', $text);
            $this->assertStringNotContainsString('inbils-api', $text);
        }
        $this->assertSame(['mail', 'database'], $notification->via($this->admin));
    }

    public function test_a_failure_other_than_a_connection_failure_does_not_start_an_outage(): void
    {
        Notification::fake();
        $manager = $this->managerWhoCanManageRouters();
        $this->router->down('tls');

        $this->sync();

        $nas = $this->nas->fresh();
        $this->assertNull($nas->unreachable_since);
        $this->assertStringContainsString('certificate', $nas->last_error);
        Notification::assertNotSentTo($manager, NasDeviceUnreachableNotification::class);
    }

    public function test_a_failed_account_is_requeued_at_most_once_per_hour(): void
    {
        [$nas, $router] = $this->nasRouter($this->company, ['isolir']); // no home-20m on this router: every apply is refused
        $account = $this->networkAccountFor($this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company, 'home-20m')), $nas, ['username' => 'dave']);
        $account->forceFill(['sync_status' => 'failed', 'last_error' => 'earlier failure', 'last_attempt_at' => now()->subMinutes(30)])->save();

        $this->sync($nas);
        $this->assertCount(0, $router->writes());

        $account->forceFill(['last_attempt_at' => now()->subMinutes(61)])->save();
        $this->sync($nas);
        $this->assertCount(1, $router->writes());
        $this->assertSame('failed', $account->fresh()->sync_status);

        $this->sync($nas);
        $this->assertCount(1, $router->writes());

        $this->travel(61)->minutes();
        $this->sync($nas);
        $this->assertCount(2, $router->writes());
    }

    public function test_with_the_write_flag_off_the_sync_only_reads(): void
    {
        $this->nas->forceFill(['provisioning_enabled' => false])->save();
        $this->router->seedAccount('pppoe', 'stray');
        $this->router->seedSession('pppoe', 'alice', '10.10.0.5');
        $this->router->seedAccount('pppoe', 'alice', 'home-50m', false, 'fake-pw', 'inbils:X:Y');

        $this->sync();

        $this->assertSame([], $this->router->writes());
        $nas = $this->nas->fresh();
        $this->assertSame('7.16.2 (stable)', $nas->routeros_version);
        $this->assertSame(['stray'], $nas->unmanaged_usernames);
        $this->assertSame('10.10.0.5', $this->account->fresh()->current_address);
        $this->assertSame('pending', $this->account->fresh()->sync_status);
    }

    public function test_with_the_installation_switch_off_the_sync_does_nothing_at_all(): void
    {
        $this->disableRouterOs();
        Queue::fake();

        $this->artisan('network:sync')->expectsOutputToContain('switched off')->assertSuccessful();

        Queue::assertNothingPushed();

        $this->runSyncJob($this->nas);
        Http::assertNothingSent();
        $this->assertNull($this->nas->fresh()->last_checked_at);
    }

    public function test_the_command_queues_one_job_per_router_and_respects_its_options(): void
    {
        $second = NasDevice::factory()->create(['company_id' => $this->company->id]);
        $other = Company::factory()->create(['is_active' => true]);
        NasDevice::factory()->create(['company_id' => $other->id]);
        $inactive = Company::factory()->create(['is_active' => false]);
        NasDevice::factory()->create(['company_id' => $inactive->id]);
        Queue::fake();

        $this->artisan('network:sync')->assertSuccessful();
        Queue::assertPushed(SyncNasDeviceJob::class, 3);

        Queue::fake();
        $this->artisan('network:sync', ['--company' => $this->company->id])->assertSuccessful();
        Queue::assertPushed(SyncNasDeviceJob::class, 2);

        Queue::fake();
        $this->artisan('network:sync', ['--company' => $this->company->id, '--nas' => $second->id])->assertSuccessful();
        Queue::assertPushed(SyncNasDeviceJob::class, 1);
        Queue::assertPushed(SyncNasDeviceJob::class, fn (SyncNasDeviceJob $job): bool => $job->nasDeviceId === $second->id && $job->companyId === $this->company->id);

        Queue::fake();
        $this->artisan('network:sync', ['--company' => $this->company->id, '--nas' => NasDevice::withoutCompany()->where('company_id', $other->id)->value('id')])->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_a_sync_job_for_company_a_never_reads_company_bs_router(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        [$otherNas, $otherRouter] = $this->nasRouter($other);

        SyncNasDeviceJob::dispatchSync((int) $this->company->id, $otherNas->id);

        $this->assertSame([], $otherRouter->requests());
        $this->assertNull($otherNas->fresh()->last_checked_at);
    }

    public function test_the_sync_is_scheduled_every_fifteen_minutes_without_overlapping(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'network:sync'));

        $this->assertNotNull($event, 'network:sync is not scheduled.');
        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_apply_jobs_are_queued_for_drift_only_while_writes_are_on(): void
    {
        $this->applyAccount($this->account);
        $this->router->changeAccount('pppoe', 'alice', ['profile' => 'home-50m']);
        Queue::fake();

        $this->runSyncJob($this->nas);
        Queue::assertPushed(ApplyNetworkAccountJob::class, 1);

        $this->nas->forceFill(['provisioning_enabled' => false])->save();
        Queue::fake();
        $this->runSyncJob($this->nas);
        Queue::assertNothingPushed();
    }

    /** A faked queue would swallow dispatchSync() of a ShouldQueue job, so the handler is called directly. */
    private function runSyncJob(NasDevice $nas): void
    {
        $this->app->call([new SyncNasDeviceJob((int) $nas->company_id, $nas->id), 'handle']);
    }
}
