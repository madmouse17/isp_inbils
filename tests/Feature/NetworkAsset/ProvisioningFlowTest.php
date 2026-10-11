<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Modules\NetworkAsset\Jobs\ApplyNetworkAccountJob;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Notifications\NasDeviceUnreachableNotification;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\Support\FakeRouterOs;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithRouterOs;

class ProvisioningFlowTest extends TestCase
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
        $this->actingAs($this->admin);
    }

    private function auditCount(string $event): int
    {
        return Activity::query()->where('log_name', 'network_account')->where('description', $event)->count();
    }

    /** The account provisioned and online, as most flows start. */
    private function provisioned(): void
    {
        $this->applyAccount($this->account);
        $this->router->seedSession('pppoe', 'alice', '10.10.0.5');
        $this->router->forgetRequests();
    }

    public function test_an_active_subscription_gets_its_secret_with_the_speed_profile_and_the_comment(): void
    {
        $this->applyAccount($this->account);

        $secret = $this->router->account('pppoe', 'alice');
        $this->assertSame('home-20m', $secret['profile']);
        $this->assertSame('false', $secret['disabled']);
        $this->assertSame('pppoe', $secret['service']);
        $this->assertSame('fake-subscriber-secret', $secret['password']);
        $this->assertSame('inbils:'.$this->company->code.':'.$this->subscription->code, $secret['comment']);
        $account = $this->account->fresh();
        $this->assertSame('synced', $account->sync_status);
        $this->assertSame('home-20m', $account->provisioned_profile);
        $this->assertSame('alice', $account->provisioned_username);
        $this->assertSame('pppoe', $account->provisioned_service_type);
        $this->assertSame($this->nas->id, $account->provisioned_nas_device_id);
        $this->assertNotNull($account->provisioned_at);
        $this->assertNull($account->last_error);
        $this->assertSame(1, $this->auditCount('provisioned'));
    }

    public function test_a_pending_subscription_is_enabled_too_so_the_technician_can_test_on_site(): void
    {
        $pending = $this->subscriptionIn($this->company, 'pending', null, $this->packageWithRouterProfile($this->company, 'home-50m'));
        $account = $this->networkAccountFor($pending, $this->nas, ['username' => 'bob']);

        $this->applyAccount($account);

        $this->assertSame('home-50m', $this->router->account('pppoe', 'bob')['profile']);
        $this->assertSame('false', $this->router->account('pppoe', 'bob')['disabled']);
    }

    public function test_suspending_moves_the_secret_to_the_isolir_profile_and_drops_the_session(): void
    {
        $this->provisioned();

        SubscriptionService::suspend($this->subscription, 'unpaid', 'overdue');

        $this->assertSame('isolir', $this->router->account('pppoe', 'alice')['profile']);
        $this->assertSame([], $this->router->sessions('pppoe', 'alice'));
        $this->assertSame('isolir', $this->account->fresh()->provisioned_profile);
    }

    public function test_reactivating_restores_the_speed_profile_and_drops_the_session(): void
    {
        $this->provisioned();
        SubscriptionService::suspend($this->subscription, 'unpaid', 'overdue');
        $this->router->seedSession('pppoe', 'alice', '10.10.0.9');

        SubscriptionService::reactivate($this->subscription);

        $this->assertSame('home-20m', $this->router->account('pppoe', 'alice')['profile']);
        $this->assertSame([], $this->router->sessions('pppoe', 'alice'));
    }

    public function test_terminating_removes_the_secret_and_drops_the_session(): void
    {
        $this->provisioned();

        SubscriptionService::terminate($this->subscription, 'moved away');

        $this->assertNull($this->router->account('pppoe', 'alice'));
        $this->assertSame([], $this->router->sessions('pppoe', 'alice'));
        $account = $this->account->fresh();
        $this->assertNull($account->provisioned_profile);
        $this->assertNull($account->provisioned_nas_device_id);
        $this->assertSame('synced', $account->sync_status);
    }

    public function test_deleting_a_subscription_removes_its_secret(): void
    {
        $this->provisioned();

        $this->subscription->delete();

        $this->assertNull($this->router->account('pppoe', 'alice'));
    }

    public function test_a_package_change_through_the_subscription_update_route_applies_the_new_profile(): void
    {
        $this->provisioned();
        $bigger = $this->packageWithRouterProfile($this->company, 'home-50m');

        $this->put(route('admin.subscriptions.update', $this->subscription), ['service_package_id' => $bigger->id])->assertRedirect();

        $this->assertSame('home-50m', $this->router->account('pppoe', 'alice')['profile']);
        $this->assertSame([], $this->router->sessions('pppoe', 'alice'));
    }

    public function test_a_change_that_does_not_concern_the_network_makes_no_router_call(): void
    {
        $this->provisioned();

        $this->subscription->update(['notes' => 'called the customer']);

        $this->assertSame([], $this->router->requests());
    }

    public function test_a_status_change_is_seen_even_when_the_model_is_saved_again_in_the_same_transaction(): void
    {
        $this->provisioned();

        DB::transaction(function () {
            $this->subscription->update(['status' => 'suspended']);
            $this->subscription->update(['notes' => 'second save']);
        });

        $this->assertSame('isolir', $this->router->account('pppoe', 'alice')['profile']);
    }

    public function test_a_rolled_back_status_change_never_reaches_the_router(): void
    {
        $this->provisioned();

        try {
            DB::transaction(function () {
                SubscriptionService::suspend($this->subscription, 'unpaid', 'overdue');

                throw new RuntimeException('the surrounding work failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->router->requests());
        $this->assertSame('home-20m', $this->router->account('pppoe', 'alice')['profile']);
    }

    public function test_moving_the_account_to_another_router_removes_it_from_the_old_one(): void
    {
        $this->provisioned();
        [$other, $otherRouter] = $this->nasRouter($this->company);

        $this->account->update(['nas_device_id' => $other->id]);
        $this->applyAccount($this->account);

        $this->assertNull($this->router->account('pppoe', 'alice'));
        $this->assertSame([], $this->router->sessions('pppoe', 'alice'));
        $this->assertSame('home-20m', $otherRouter->account('pppoe', 'alice')['profile']);
        $this->assertSame($other->id, $this->account->fresh()->provisioned_nas_device_id);
    }

    public function test_renaming_the_username_removes_the_old_name_and_creates_the_new_one(): void
    {
        $this->provisioned();

        $this->account->update(['username' => 'alice.new']);
        $this->applyAccount($this->account);

        $this->assertNull($this->router->account('pppoe', 'alice'));
        $this->assertSame('home-20m', $this->router->account('pppoe', 'alice.new')['profile']);
        $this->assertSame('alice.new', $this->account->fresh()->provisioned_username);
    }

    public function test_switching_the_service_type_cleans_the_old_menu(): void
    {
        $this->provisioned();

        $this->account->update(['service_type' => 'hotspot']);
        $this->applyAccount($this->account);

        $this->assertNull($this->router->account('pppoe', 'alice'));
        $this->assertSame('home-20m', $this->router->account('hotspot', 'alice')['profile']);
        $this->assertSame('hotspot', $this->account->fresh()->provisioned_service_type);
    }

    public function test_changing_the_password_updates_it_on_the_router_and_keeps_the_session(): void
    {
        $this->provisioned();

        $this->account->update(['password' => 'fake-new-secret']);
        $this->applyAccount($this->account);

        $this->assertSame('fake-new-secret', $this->router->account('pppoe', 'alice')['password']);
        $this->assertCount(1, $this->router->sessions('pppoe', 'alice'));
        $this->assertSame(0, $this->auditCount('drift_corrected'));
    }

    public function test_an_existing_secret_with_the_same_name_is_adopted_not_duplicated(): void
    {
        $this->router->seedAccount('pppoe', 'alice', 'default', false, 'fake-manual-pass');
        $this->router->seedSession('pppoe', 'alice');

        $this->applyAccount($this->account);

        $this->assertCount(1, $this->router->accounts('pppoe'));
        $secret = $this->router->account('pppoe', 'alice');
        $this->assertSame('home-20m', $secret['profile']);
        $this->assertSame('fake-subscriber-secret', $secret['password']);
        $this->assertSame('inbils:'.$this->company->code.':'.$this->subscription->code, $secret['comment']);
        $this->assertSame([], $this->router->sessions('pppoe', 'alice'));
        $this->assertSame(1, $this->auditCount('provisioned'));
    }

    public function test_a_stray_secret_without_our_comment_is_not_deleted_for_an_absent_account(): void
    {
        $this->subscription->forceFill(['status' => 'terminated'])->saveQuietly();
        $this->router->seedAccount('pppoe', 'alice', 'default', false, 'fake-manual-pass');

        $this->applyAccount($this->account);

        $this->assertNotNull($this->router->account('pppoe', 'alice'));
        $this->assertSame('synced', $this->account->fresh()->sync_status);
    }

    public function test_a_second_run_against_an_unchanged_router_writes_nothing_and_audits_nothing(): void
    {
        $this->applyAccount($this->account);
        $this->router->forgetRequests();

        $this->applyAccount($this->account);

        $this->assertSame([], $this->router->writes());
        $this->assertSame(1, $this->auditCount('provisioned'));
        $this->assertSame(0, $this->auditCount('drift_corrected'));
    }

    public function test_a_manual_edit_on_the_router_is_corrected_and_audited_as_drift(): void
    {
        $this->applyAccount($this->account);
        $id = $this->router->account('pppoe', 'alice')['.id'];
        $this->router->changeAccount('pppoe', 'alice', ['profile' => 'home-50m', 'disabled' => 'true']);
        $this->router->forgetRequests();

        $this->applyAccount($this->account);

        $secret = $this->router->account('pppoe', 'alice');
        $this->assertSame('home-20m', $secret['profile']);
        $this->assertSame('false', $secret['disabled']);
        $this->assertSame($id, $secret['.id']);
        $this->assertSame(1, $this->auditCount('drift_corrected'));
    }

    public function test_a_secret_deleted_on_the_router_is_created_again_and_audited_as_drift(): void
    {
        $this->applyAccount($this->account);
        $this->router->removeAccount('pppoe', 'alice');

        $this->applyAccount($this->account);

        $this->assertSame('home-20m', $this->router->account('pppoe', 'alice')['profile']);
        $this->assertSame(1, $this->auditCount('drift_corrected'));
        $this->assertSame(1, $this->auditCount('provisioned'));
    }

    public function test_hotspot_accounts_follow_the_same_lifecycle_in_the_hotspot_menu(): void
    {
        $account = $this->networkAccountFor($this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company, 'home-50m')), $this->nas, ['username' => 'hot1', 'service_type' => 'hotspot']);
        $this->applyAccount($account);
        $this->router->seedSession('hotspot', 'hot1');

        SubscriptionService::suspend($account->subscription, 'unpaid', 'overdue');

        $this->assertSame('isolir', $this->router->account('hotspot', 'hot1')['profile']);
        $this->assertArrayNotHasKey('service', $this->router->account('hotspot', 'hot1'));
        $this->assertNull($this->router->account('pppoe', 'hot1'));
        $this->assertSame([], $this->router->sessions('hotspot', 'hot1'));
    }

    public function test_the_installation_switch_off_sends_nothing_and_leaves_the_account_pending_with_the_reason(): void
    {
        $this->disableRouterOs();

        $this->applyAccount($this->account);

        Http::assertNothingSent();
        $account = $this->account->fresh();
        $this->assertSame('pending', $account->sync_status);
        $this->assertStringContainsString('switched off for this installation', $account->last_error);
    }

    public function test_the_router_write_flag_off_sends_nothing_and_leaves_the_account_pending_with_the_reason(): void
    {
        $this->nas->forceFill(['provisioning_enabled' => false])->save();

        $this->applyAccount($this->account);

        Http::assertNothingSent();
        $account = $this->account->fresh();
        $this->assertSame('pending', $account->sync_status);
        $this->assertStringContainsString('Writes are switched off for this router', $account->last_error);
    }

    public function test_a_router_that_says_no_fails_the_account_at_once_with_its_message_and_never_retries(): void
    {
        $this->router->down('rejected', 'input does not match any value of profile');

        $this->applyAccount($this->account);

        $account = $this->account->fresh();
        $this->assertSame('failed', $account->sync_status);
        $this->assertSame('Bad Request: input does not match any value of profile', $account->last_error);
        $this->assertCount(1, $this->router->requests());
        $audit = Activity::query()->where('description', 'provision_failed')->firstOrFail();
        $this->assertSame('rejected', $audit->properties['error_kind']);
        $this->assertStringNotContainsString('fake-subscriber-secret', json_encode($audit->properties));
        $this->assertStringNotContainsString('fake-router-api-password', json_encode($audit->properties));
    }

    public function test_wrong_router_credentials_fail_the_account_at_once(): void
    {
        $this->router->down('auth');

        $this->applyAccount($this->account);

        $this->assertSame('failed', $this->account->fresh()->sync_status);
        $this->assertCount(1, $this->router->requests());
    }

    public function test_a_package_without_a_router_profile_name_fails_the_account_without_calling_the_router(): void
    {
        $this->subscription->forceFill(['service_package_id' => $this->packageWithRouterProfile($this->company, null)->id])->saveQuietly();

        $this->applyAccount($this->account);

        $account = $this->account->fresh();
        $this->assertSame('failed', $account->sync_status);
        $this->assertStringContainsString('no router profile name', $account->last_error);
        $this->assertSame([], $this->router->requests());
    }

    public function test_an_unreachable_router_keeps_the_account_pending_marks_the_outage_and_is_skipped_for_two_minutes(): void
    {
        Notification::fake();
        $manager = $this->managerWhoCanManageRouters();
        $other = $this->networkAccountFor($this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company, 'home-20m')), $this->nas, ['username' => 'carol']);
        $this->router->down('refused');

        $this->applyAccount($this->account);

        $account = $this->account->fresh();
        $this->assertSame('pending', $account->sync_status);
        $this->assertStringContainsString('did not answer', $account->last_error);
        $nas = $this->nas->fresh();
        $this->assertNotNull($nas->unreachable_since);
        $this->assertSame(1, Activity::query()->where('log_name', 'nas_device')->where('description', 'unreachable')->count());
        Notification::assertSentToTimes($manager, NasDeviceUnreachableNotification::class, 1);

        $seen = count($this->router->requests());
        $this->applyAccount($other);
        $this->assertCount($seen, $this->router->requests());
        $this->assertSame('pending', $other->fresh()->sync_status);
        Notification::assertSentToTimes($manager, NasDeviceUnreachableNotification::class, 1);

        $this->travel(3)->minutes();
        $this->applyAccount($other);
        $this->assertGreaterThan($seen, count($this->router->requests()));
        Notification::assertSentToTimes($manager, NasDeviceUnreachableNotification::class, 1);
    }

    public function test_business_actions_succeed_while_the_router_is_down(): void
    {
        $this->provisioned();
        $this->router->down('refused');

        $suspended = SubscriptionService::suspend($this->subscription, 'unpaid', 'overdue');

        $this->assertSame('suspended', $suspended->status);
        $this->assertSame('pending', $this->account->fresh()->sync_status);
        $this->assertSame('home-20m', $this->router->account('pppoe', 'alice')['profile']);
    }

    public function test_the_router_answering_again_clears_the_outage_and_audits_the_recovery(): void
    {
        $this->router->down('refused');
        $this->applyAccount($this->account);
        $this->router->up();
        $this->travel(3)->minutes();

        $this->applyAccount($this->account);

        $this->assertNull($this->nas->fresh()->unreachable_since);
        $this->assertSame('synced', $this->account->fresh()->sync_status);
        $this->assertSame(1, Activity::query()->where('log_name', 'nas_device')->where('description', 'recovered')->count());
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
        [$otherNas, $otherRouter] = $this->nasRouter($other);
        $theirs = $this->networkAccountFor($this->subscriptionIn($other, 'active', null, $this->packageWithRouterProfile($other, 'home-20m')), $otherNas, ['username' => 'zed']);

        ApplyNetworkAccountJob::dispatch((int) $this->company->id, $theirs->id);

        $this->assertSame([], $otherRouter->requests());
        $this->assertSame('pending', $theirs->fresh()->sync_status);
        $this->assertNull($theirs->fresh()->last_attempt_at);
    }

    public function test_the_observer_resolves_the_account_in_the_subscriptions_own_company(): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        [$otherNas, $otherRouter] = $this->nasRouter($other);
        $theirSubscription = $this->subscriptionIn($other, 'active', null, $this->packageWithRouterProfile($other, 'home-20m'));
        $theirs = $this->networkAccountFor($theirSubscription, $otherNas, ['username' => 'zed']);
        $this->applyAccount($theirs);
        $this->provisioned();

        // The ambient company is A; the subscription being changed belongs to B.
        ServiceSubscription::withoutCompany()->findOrFail($theirSubscription->id)->update(['status' => 'suspended']);

        $this->assertSame('isolir', $otherRouter->account('pppoe', 'zed')['profile']);
        $this->assertSame([], $this->router->requests());
    }

    private function managerWhoCanManageRouters(): User
    {
        Permission::findOrCreate('network_asset.nas.manage', 'web');
        $manager = User::factory()->create(['company_id' => $this->company->id, 'is_active' => true, 'email_verified_at' => now()]);
        $manager->givePermissionTo('network_asset.nas.manage');
        $outsider = User::factory()->create(['company_id' => Company::factory()->create(['is_active' => true])->id, 'is_active' => true]);
        $outsider->givePermissionTo('network_asset.nas.manage');

        return $manager;
    }
}
