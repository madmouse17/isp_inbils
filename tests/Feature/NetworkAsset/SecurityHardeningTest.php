<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\NetworkAsset\Jobs\ApplyNetworkAccountJob;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Services\NetworkAccountService;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeRouterOs;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithRouterOs;

/** Fixes of the security review of the MikroTik lane (.scratch/mikrotik-radius ticket 07). */
class SecurityHardeningTest extends TestCase
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

        $this->withoutVite();
        $this->admin = $this->createCompanyUser();
        $this->company = $this->admin->company;
        $this->grantNetworkPermissionsToAdminRole();
        $this->fakeRouterOs();
        [$this->nas, $this->router] = $this->nasRouter($this->company);
        $this->subscription = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company, 'home-20m'));
        $this->account = $this->networkAccountFor($this->subscription, $this->nas);
        $this->actingAs($this->admin);
    }

    /** @return array<string, mixed> */
    private function assetPayload(array $overrides = []): array
    {
        $asset = $this->nas->networkAsset;

        return $overrides + ['name' => $asset->name, 'asset_type' => 'router', 'management_ip' => $asset->getAttribute('management_ip')];
    }

    /** An active, provisioned account of the given type whose subscriber is online. @return array{0: ServiceSubscription, 1: NetworkAccount} */
    private function onlineAccount(string $type, string $username = 'carol'): array
    {
        $subscription = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company, 'home-20m'));
        $account = $this->networkAccountFor($subscription, $this->nas, ['username' => $username, 'service_type' => $type]);
        $this->applyAccount($account);
        $this->router->seedSession($type, $username, '10.10.0.7');
        $this->router->forgetRequests();

        return [$subscription, $account];
    }

    /**
     * An isolated account whose secret drifted back to the speed profile with the subscriber online again, and a
     * router that refuses the session lookup: the drift run writes the profile and then fails before the drop.
     *
     * @return array{0: ServiceSubscription, 1: NetworkAccount}
     */
    private function failedDriftCorrection(string $type, string $activeMenu): array
    {
        [$subscription, $account] = $this->onlineAccount($type);
        SubscriptionService::suspend($subscription, 'unpaid', 'overdue');
        $this->router->changeAccount($type, 'carol', ['profile' => 'home-20m']);
        $this->router->seedSession($type, 'carol', '10.10.0.8');
        $this->router->downOn([$activeMenu]);

        $this->applyAccount($account, false);

        return [$subscription, $account->fresh()];
    }

    // MIKROTIK-07-001: the credentials follow the address, so the address is a router-credential matter.

    public function test_an_asset_editor_without_nas_manage_cannot_repoint_a_registered_router(): void
    {
        $this->nas->forceFill(['provisioning_enabled' => true])->save();
        $editor = $this->userWithPermissions($this->company, 'network_asset.view', 'network_asset.update');
        $old = $this->nas->networkAsset->getAttribute('management_ip');
        $this->actingAs($editor);

        $this->putJson(route('admin.network-assets.update', $this->nas->networkAsset), $this->assetPayload(['management_ip' => '198.51.100.250']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('management_ip');

        $this->assertSame($old, $this->nas->networkAsset->fresh()->getAttribute('management_ip'));
        $this->assertTrue($this->nas->fresh()->provisioning_enabled);
    }

    public function test_the_same_editor_can_still_edit_everything_else_and_resubmit_the_unchanged_address(): void
    {
        $this->nas->forceFill(['provisioning_enabled' => true])->save();
        $editor = $this->userWithPermissions($this->company, 'network_asset.view', 'network_asset.update');
        $this->actingAs($editor);

        $this->put(route('admin.network-assets.update', $this->nas->networkAsset), $this->assetPayload(['name' => 'Core router, renamed']))->assertRedirect();

        $this->assertSame('Core router, renamed', $this->nas->networkAsset->fresh()->name);
        $this->assertTrue($this->nas->fresh()->provisioning_enabled);
    }

    public function test_an_editor_may_change_the_address_of_an_asset_that_is_not_a_nas_router(): void
    {
        $plain = NetworkAssetFactory::new()->create(['company_id' => $this->company->id, 'asset_type' => 'router', 'management_ip' => '192.0.2.210']);
        $this->actingAs($this->userWithPermissions($this->company, 'network_asset.view', 'network_asset.update'));

        $this->put(route('admin.network-assets.update', $plain), ['name' => $plain->name, 'asset_type' => 'router', 'management_ip' => '192.0.2.211'])->assertRedirect();

        $this->assertSame('192.0.2.211', $plain->fresh()->getAttribute('management_ip'));
    }

    public function test_an_administrator_changing_the_address_switches_the_write_flag_off_and_audits_it(): void
    {
        $this->nas->forceFill(['provisioning_enabled' => true])->save();

        $this->put(route('admin.network-assets.update', $this->nas->networkAsset), $this->assetPayload(['management_ip' => '192.0.2.201']))->assertRedirect();

        $this->assertSame('192.0.2.201', $this->nas->networkAsset->fresh()->getAttribute('management_ip'));
        $this->assertFalse($this->nas->fresh()->provisioning_enabled);
        $audit = Activity::query()->where('log_name', 'nas_device')->where('description', 'endpoint_changed')->firstOrFail();
        $this->assertSame('192.0.2.201', $audit->properties['to']);
        $this->assertTrue($audit->properties['writes_switched_off']);
        $this->assertStringNotContainsString('fake-router-api-password', json_encode($audit->properties));
    }

    public function test_any_change_of_the_address_switches_the_write_flag_off_but_other_edits_do_not(): void
    {
        $this->nas->forceFill(['provisioning_enabled' => true])->save();

        $this->nas->networkAsset->update(['name' => 'Renamed']);
        $this->assertTrue($this->nas->fresh()->provisioning_enabled);

        $this->nas->networkAsset->update(['management_ip' => '192.0.2.202']);
        $this->assertFalse($this->nas->fresh()->provisioning_enabled);
    }

    // MIKROTIK-07-004: access revocation must survive a failure between the router write and the session drop.

    /** @return array<string, array{0: string, 1: string}> */
    public static function serviceTypes(): array
    {
        return ['pppoe' => ['pppoe', 'ppp/active'], 'hotspot' => ['hotspot', 'ip/hotspot/active']];
    }

    #[DataProvider('serviceTypes')]
    public function test_a_failed_session_drop_after_the_isolir_write_is_retried_before_the_account_counts_as_synced(string $type, string $activeMenu): void
    {
        [$subscription, $account] = $this->onlineAccount($type);
        $this->router->downOn([$activeMenu]);

        SubscriptionService::suspend($subscription, 'unpaid', 'overdue');

        $this->assertSame('isolir', $this->router->account($type, 'carol')['profile']);
        $this->assertCount(1, $this->router->sessions($type, 'carol'));
        $account = $account->fresh();
        $this->assertSame('pending', $account->sync_status);
        $this->assertSame('home-20m', $account->provisioned_profile);

        $this->router->up();
        $this->travel(3)->minutes();
        $this->applyAccount($account);

        $this->assertSame([], $this->router->sessions($type, 'carol'));
        $account = $account->fresh();
        $this->assertSame('synced', $account->sync_status);
        $this->assertSame('isolir', $account->provisioned_profile);
    }

    #[DataProvider('serviceTypes')]
    public function test_a_failed_session_drop_after_the_secret_was_removed_is_retried(string $type, string $activeMenu): void
    {
        [$subscription, $account] = $this->onlineAccount($type);
        $this->router->downOn([$activeMenu]);

        SubscriptionService::terminate($subscription, 'moved away');

        $this->assertNull($this->router->account($type, 'carol'));
        $this->assertCount(1, $this->router->sessions($type, 'carol'));
        $this->assertSame('pending', $account->fresh()->sync_status);
        $this->assertSame('home-20m', $account->fresh()->provisioned_profile);

        $this->router->up();
        $this->travel(3)->minutes();
        $this->applyAccount($account);

        $this->assertSame([], $this->router->sessions($type, 'carol'));
        $account = $account->fresh();
        $this->assertSame('synced', $account->sync_status);
        $this->assertNull($account->provisioned_profile);
    }

    public function test_a_failed_session_drop_after_a_package_change_is_retried(): void
    {
        [$subscription, $account] = $this->onlineAccount('pppoe');
        $bigger = $this->packageWithRouterProfile($this->company, 'home-50m');
        $this->router->downOn(['ppp/active']);

        $subscription->update(['service_package_id' => $bigger->id]);

        $this->assertSame('home-50m', $this->router->account('pppoe', 'carol')['profile']);
        $this->assertCount(1, $this->router->sessions('pppoe', 'carol'));
        $this->assertSame('home-20m', $account->fresh()->provisioned_profile);

        $this->router->up();
        $this->travel(3)->minutes();
        $this->applyAccount($account);

        $this->assertSame([], $this->router->sessions('pppoe', 'carol'));
        $this->assertSame('home-50m', $account->fresh()->provisioned_profile);
    }

    // MIKROTIK-07-004 (re-review): a session drop is owed whenever the router was written for it, whatever the recorded and desired profiles say.

    #[DataProvider('serviceTypes')]
    public function test_a_failed_session_drop_after_correcting_drift_on_an_isolated_account_is_retried(string $type, string $activeMenu): void
    {
        [, $account] = $this->failedDriftCorrection($type, $activeMenu);

        $this->assertSame('isolir', $this->router->account($type, 'carol')['profile']);
        $this->assertCount(1, $this->router->sessions($type, 'carol'));
        $this->assertSame('pending', $account->sync_status);
        $this->assertSame('isolir', $account->provisioned_profile, 'recorded and desired profile match: only the marker says a drop is owed');
        $this->assertNotNull($account->pending_disconnect_at);

        $this->router->up();
        $this->travel(3)->minutes();
        $this->applyAccount($account, false);

        $this->assertSame([], $this->router->sessions($type, 'carol'));
        $account = $account->fresh();
        $this->assertSame('synced', $account->sync_status);
        $this->assertNull($account->pending_disconnect_at);
    }

    #[DataProvider('serviceTypes')]
    public function test_a_failed_session_drop_after_adopting_an_existing_secret_is_retried(string $type, string $activeMenu): void
    {
        $subscription = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company, 'home-20m'));
        $account = $this->networkAccountFor($subscription, $this->nas, ['username' => 'dave', 'service_type' => $type]);
        $this->router->seedAccount($type, 'dave', 'default', false, 'fake-manual-pass');
        $this->router->seedSession($type, 'dave', '10.10.0.8');
        $this->router->downOn([$activeMenu]);

        $this->applyAccount($account);

        $this->assertSame('home-20m', $this->router->account($type, 'dave')['profile']);
        $this->assertCount(1, $this->router->sessions($type, 'dave'));
        $account = $account->fresh();
        $this->assertSame('pending', $account->sync_status);
        $this->assertNull($account->provisioned_profile, 'the first apply never reached its record');
        $this->assertNotNull($account->pending_disconnect_at);

        $this->router->up();
        $this->travel(3)->minutes();
        $this->applyAccount($account, false); // the sync's retry needs no approval: the secret carries our comment now

        $this->assertSame([], $this->router->sessions($type, 'dave'));
        $account = $account->fresh();
        $this->assertSame('synced', $account->sync_status);
        $this->assertNull($account->pending_disconnect_at);
    }

    public function test_an_account_with_an_unrecorded_first_adoption_cannot_be_retargeted_until_the_drop_is_settled(): void
    {
        $subscription = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company, 'home-20m'));
        $account = $this->networkAccountFor($subscription, $this->nas, ['username' => 'dave', 'service_type' => 'pppoe']);
        $this->router->seedAccount('pppoe', 'dave', 'default', false, 'fake-manual-pass');
        $this->router->seedSession('pppoe', 'dave', '10.10.0.8');
        $this->router->downOn(['ppp/active']);
        $this->applyAccount($account);

        try {
            NetworkAccountService::update($account->fresh(), ['username' => 'dave2']);
            $this->fail('A retarget while the first drop is owed must be rejected.');
        } catch (ValidationException) {
            $this->assertSame('dave', $account->fresh()->username);
        }

        $this->router->up();
        $this->travel(3)->minutes();
        $this->applyAccount($account->fresh(), false);
        $this->assertSame([], $this->router->sessions('pppoe', 'dave'));

        $account = NetworkAccountService::update($account->fresh(), ['username' => 'dave2']);
        $this->assertSame('dave2', $account->username);
    }

    #[DataProvider('serviceTypes')]
    public function test_a_failed_session_drop_after_removing_a_secret_that_was_never_recorded_is_retried(string $type, string $activeMenu): void
    {
        $subscription = $this->subscriptionIn($this->company, 'terminated', null, $this->packageWithRouterProfile($this->company, 'home-20m'));
        $account = $this->networkAccountFor($subscription, $this->nas, ['username' => 'eve', 'service_type' => $type]);
        $this->router->seedAccount($type, 'eve', 'home-20m', false, 'fake-old-pass', 'inbils:'.$this->company->code.':'.$subscription->code);
        $this->router->seedSession($type, 'eve', '10.10.0.9');
        $this->router->downOn([$activeMenu]);

        $this->applyAccount($account, false);

        $this->assertNull($this->router->account($type, 'eve'));
        $this->assertCount(1, $this->router->sessions($type, 'eve'));
        $account = $account->fresh();
        $this->assertSame('pending', $account->sync_status);
        $this->assertNull($account->provisioned_profile, 'no record says the secret existed');
        $this->assertNotNull($account->pending_disconnect_at);

        $this->router->up();
        $this->travel(3)->minutes();
        $this->applyAccount($account, false);

        $this->assertSame([], $this->router->sessions($type, 'eve'));
        $account = $account->fresh();
        $this->assertSame('synced', $account->sync_status);
        $this->assertNull($account->pending_disconnect_at);
    }

    public function test_the_sync_requeues_a_failed_account_that_still_owes_a_session_drop(): void
    {
        $this->failedDriftCorrection('pppoe', 'ppp/active');
        $this->router->up();
        $this->travel(3)->minutes();
        NetworkAccount::query()->where('username', 'carol')->firstOrFail()->forceFill(['sync_status' => 'failed', 'last_attempt_at' => now()->subMinute()])->save(); // failed accounts wait an hour

        $this->artisan('network:sync', ['--company' => $this->company->id, '--nas' => $this->nas->id])->assertSuccessful();

        $this->assertSame([], $this->router->sessions('pppoe', 'carol'));
        $account = NetworkAccount::query()->where('username', 'carol')->firstOrFail();
        $this->assertSame('synced', $account->sync_status);
        $this->assertNull($account->pending_disconnect_at);
    }

    public function test_moving_an_account_that_owes_a_session_drop_settles_it_on_the_old_router(): void
    {
        [$subscription, $account] = $this->onlineAccount('pppoe');
        $this->router->downOn(['ppp/active']);
        SubscriptionService::suspend($subscription, 'unpaid', 'overdue'); // the profile write went through, the drop did not
        [$other, $otherRouter] = $this->nasRouter($this->company);
        $otherRouter->down('refused');
        $this->router->up();
        $this->travel(3)->minutes();

        $account->fresh()->update(['nas_device_id' => $other->id]);
        $this->applyAccount($account, false);

        $this->assertNull($this->router->account('pppoe', 'carol'));
        $this->assertSame([], $this->router->sessions('pppoe', 'carol'));
        $account = $account->fresh();
        $this->assertSame('pending', $account->sync_status, 'the new router is down');
        $this->assertNull($account->provisioned_profile);
        $this->assertNull($account->pending_disconnect_at, 'the debt was on the old router and is settled there');
    }

    // MIKROTIK-07-003: the router API password never lands in the session as old input.

    public function test_a_failed_router_form_never_flashes_the_api_password_into_the_session(): void
    {
        $asset = $this->nas->networkAsset;

        $this->post(route('admin.nas-devices.store'), ['network_asset_id' => $asset->id, 'api_port' => 70000, 'api_username' => 'inbils-api', 'api_password' => 'fake-flash-me-1'])
            ->assertSessionHasErrors('api_port');
        $this->assertSame('inbils-api', session('_old_input.api_username'));
        $this->assertNull(session('_old_input.api_password'));

        $this->put(route('admin.nas-devices.update', $this->nas), ['api_port' => 70000, 'api_password' => 'fake-flash-me-2'])
            ->assertSessionHasErrors('api_port');
        $this->assertNull(session('_old_input.api_password'));
        $this->assertStringNotContainsString('fake-flash-me', json_encode(session()->all()));
    }

    public function test_a_failed_account_form_never_flashes_the_subscriber_password_into_the_session(): void
    {
        $subscription = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company));

        $this->post(route('admin.subscriptions.network-account.store', $subscription), ['nas_device_id' => $this->nas->id, 'service_type' => 'l2tp', 'username' => 'valid.name', 'password' => 'fake-flash-me-3'])
            ->assertSessionHasErrors('service_type');

        $this->assertNull(session('_old_input.password'));
        $this->assertStringNotContainsString('fake-flash-me-3', json_encode(session()->all()));
    }

    // MIKROTIK-07-006: adoption takes over PPPoE secrets only, and only with an administrator's say-so.

    public function test_a_secret_of_another_service_is_never_adopted(): void
    {
        $this->router->seedAccount('pppoe', 'alice', 'default', false, 'fake-vpn-pass', 'site-to-site', ['service' => 'l2tp']);
        $this->router->forgetRequests();

        $this->applyAccount($this->account);

        $secret = $this->router->account('pppoe', 'alice');
        $this->assertSame('l2tp', $secret['service']);
        $this->assertSame('fake-vpn-pass', $secret['password']);
        $this->assertSame('default', $secret['profile']);
        $this->assertSame([], $this->router->writes());
        $account = $this->account->fresh();
        $this->assertSame('failed', $account->sync_status);
        $this->assertStringContainsString('l2tp', $account->last_error);
    }

    public function test_a_secret_open_to_any_service_is_adopted_and_narrowed_to_pppoe(): void
    {
        $this->router->seedAccount('pppoe', 'alice', 'default', false, 'fake-manual-pass', '', ['service' => 'any']);

        $this->applyAccount($this->account);

        $secret = $this->router->account('pppoe', 'alice');
        $this->assertSame('pppoe', $secret['service']);
        $this->assertSame('home-20m', $secret['profile']);
        $this->assertSame('synced', $this->account->fresh()->sync_status);
    }

    public function test_without_nas_manage_an_existing_secret_waits_for_an_administrator_who_adopts_it_with_resync(): void
    {
        $this->router->seedAccount('pppoe', 'bob', 'default', false, 'fake-manual-pass');
        $subscription = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company, 'home-20m'));
        $this->actingAs($this->userWithPermissions($this->company, 'network_asset.account.view', 'network_asset.account.manage'));

        $this->post(route('admin.subscriptions.network-account.store', $subscription), ['nas_device_id' => $this->nas->id, 'service_type' => 'pppoe', 'username' => 'bob', 'password' => 'fake-chosen-pw1'])
            ->assertRedirect();

        $this->assertSame([], $this->router->writes());
        $this->assertSame('fake-manual-pass', $this->router->account('pppoe', 'bob')['password']);
        $account = NetworkAccount::query()->where('username', 'bob')->firstOrFail();
        $this->assertSame('pending', $account->sync_status);
        $this->assertStringContainsString('administrator', $account->last_error);

        $this->actingAs($this->admin);
        $this->post(route('admin.subscriptions.network-account.resync', $subscription))->assertRedirect();

        $secret = $this->router->account('pppoe', 'bob');
        $this->assertSame('home-20m', $secret['profile']);
        $this->assertSame('fake-chosen-pw1', $secret['password']);
        $this->assertSame('inbils:'.$this->company->code.':'.$subscription->code, $secret['comment']);
        $this->assertSame('synced', $account->fresh()->sync_status);
    }

    public function test_an_administrator_saving_the_account_adopts_an_existing_secret_at_once(): void
    {
        $this->router->seedAccount('pppoe', 'dan', 'default', false, 'fake-manual-pass');
        $subscription = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company, 'home-20m'));

        $this->post(route('admin.subscriptions.network-account.store', $subscription), ['nas_device_id' => $this->nas->id, 'service_type' => 'pppoe', 'username' => 'dan', 'password' => 'fake-chosen-pw2'])
            ->assertRedirect();

        $this->assertSame('home-20m', $this->router->account('pppoe', 'dan')['profile']);
        $this->assertSame('synced', NetworkAccount::query()->where('username', 'dan')->firstOrFail()->sync_status);
    }

    public function test_a_secret_that_carries_our_comment_needs_no_approval(): void
    {
        $this->router->seedAccount('pppoe', 'alice', 'home-20m', false, 'fake-old-pass', 'inbils:'.$this->company->code.':'.$this->subscription->code);

        ApplyNetworkAccountJob::dispatch((int) $this->company->id, $this->account->id);

        $this->assertSame('synced', $this->account->fresh()->sync_status);
        $this->assertSame('fake-subscriber-secret', $this->router->account('pppoe', 'alice')['password']);
    }

    // MIKROTIK-07-011 (re-review): an administrator's approval covers the target the administrator saw, not whatever the account points to when the job runs.

    /** @return array<string, array{0: string}> */
    public static function staffRetargets(): array
    {
        return ['username' => ['username'], 'router' => ['router'], 'service type' => ['service_type'], 'password' => ['password']];
    }

    #[DataProvider('staffRetargets')]
    public function test_a_delayed_administrator_job_never_adopts_what_staff_retargeted_the_account_to(string $change): void
    {
        [$other, $otherRouter] = $this->nasRouter($this->company);
        $subscription = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company, 'home-20m'));
        $this->networkAccountFor($subscription, $this->nas, ['username' => 'frank', 'password' => 'fake-approved-pw']);
        // Secrets nobody approved, at every place staff can point the account to.
        $this->router->seedAccount('pppoe', 'frank', 'default', false, 'fake-manual-pass-1');
        $this->router->seedAccount('pppoe', 'gina', 'default', false, 'fake-manual-pass-2');
        $this->router->seedAccount('hotspot', 'frank', 'default', false, 'fake-manual-pass-3');
        $otherRouter->seedAccount('pppoe', 'frank', 'default', false, 'fake-manual-pass-4');
        Queue::fake();
        $this->post(route('admin.subscriptions.network-account.resync', $subscription))->assertRedirect();
        $approved = Queue::pushed(ApplyNetworkAccountJob::class)->sole();

        $this->actingAs($this->userWithPermissions($this->company, 'network_asset.account.view', 'network_asset.account.manage'));
        $this->put(route('admin.subscriptions.network-account.update', $subscription), match ($change) {
            'username' => ['username' => 'gina'],
            'router' => ['nas_device_id' => $other->id],
            'service_type' => ['service_type' => 'hotspot'],
            'password' => ['password' => 'fake-staff-chosen'],
        })->assertRedirect();
        app()->call([$approved, 'handle']); // the delayed run; dispatching it would only hit the fake queue again

        $this->assertSame([], $this->router->writes());
        $this->assertSame([], $otherRouter->writes());
        $this->assertSame('fake-manual-pass-1', $this->router->account('pppoe', 'frank')['password']);
        $this->assertSame('fake-manual-pass-2', $this->router->account('pppoe', 'gina')['password']);
        $this->assertSame('fake-manual-pass-3', $this->router->account('hotspot', 'frank')['password']);
        $this->assertSame('fake-manual-pass-4', $otherRouter->account('pppoe', 'frank')['password']);
        $account = NetworkAccount::query()->where('subscription_id', $subscription->id)->firstOrFail();
        $this->assertSame('pending', $account->sync_status);
        $this->assertStringContainsString('administrator', $account->last_error);
    }

    public function test_an_administrator_run_for_a_new_target_is_queued_next_to_the_stale_one_and_carries_no_secret(): void
    {
        Queue::fake();

        $this->post(route('admin.subscriptions.network-account.resync', $this->subscription))->assertRedirect();
        $this->put(route('admin.subscriptions.network-account.update', $this->subscription), ['username' => 'alice.new'])->assertRedirect();

        Queue::assertPushed(ApplyNetworkAccountJob::class, 2);
        foreach (Queue::pushed(ApplyNetworkAccountJob::class) as $job) {
            $this->assertStringNotContainsString('fake-subscriber-secret', serialize($job));
        }
    }

    // MIKROTIK-07-005: the one-time password never sits in the session in clear.

    public function test_the_one_time_password_never_sits_in_the_session_in_clear(): void
    {
        $subscription = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company, 'home-20m'));
        $show = route('admin.subscriptions.network-account.show', $subscription);

        $this->post(route('admin.subscriptions.network-account.store', $subscription), ['nas_device_id' => $this->nas->id, 'service_type' => 'pppoe', 'username' => 'erin'])->assertRedirect($show);
        $password = NetworkAccount::query()->where('username', 'erin')->firstOrFail()->password;
        $this->assertStringNotContainsString($password, json_encode(session()->all()));

        $page = $this->get($show);
        $this->assertSame($password, $page->inertiaPage()['flash']['network_account']['password']);
        $this->assertStringNotContainsString($password, json_encode(session()->all()));
        $this->get($show)->assertInertia(fn (Assert $inertia) => $inertia->missingFlash('network_account'));

        $this->post(route('admin.subscriptions.network-account.reveal', $subscription))->assertRedirect($show);
        $this->assertStringNotContainsString($password, json_encode(session()->all()));
        $this->assertSame($password, $this->get($show)->inertiaPage()['flash']['network_account']['password']);
        $this->get($show)->assertInertia(fn (Assert $inertia) => $inertia->missingFlash('network_account'));
    }

    /** @return array<string, array{0: string}> */
    public static function oneTimePasswordSources(): array
    {
        return ['after create' => ['create'], 'after reveal' => ['reveal']];
    }

    #[DataProvider('oneTimePasswordSources')]
    public function test_a_stale_inertia_version_keeps_the_one_time_password_sealed_until_the_next_valid_load(string $source): void
    {
        $subscription = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRouterProfile($this->company, 'home-20m'));
        $show = route('admin.subscriptions.network-account.show', $subscription);
        $this->post(route('admin.subscriptions.network-account.store', $subscription), ['nas_device_id' => $this->nas->id, 'service_type' => 'pppoe', 'username' => 'erin'])->assertRedirect($show);
        $password = NetworkAccount::query()->where('username', 'erin')->firstOrFail()->password;

        if ($source === 'reveal') {
            $this->get($show); // the display after the create is used up
            $this->post(route('admin.subscriptions.network-account.reveal', $subscription))->assertRedirect($show);
        }

        // After an asset deployment the browser's next visit carries the old version: Inertia answers 409 and reflashes the session.
        foreach ([1, 2] as $_) {
            $this->get($show, ['X-Inertia' => 'true', 'X-Inertia-Version' => 'a-stale-asset-version'])->assertStatus(409);
            $this->assertStringNotContainsString($password, json_encode(session()->all()));
        }

        $this->assertSame($password, $this->get($show)->inertiaPage()['flash']['network_account']['password']);
        $this->assertStringNotContainsString($password, json_encode(session()->all()));
        $this->get($show)->assertInertia(fn (Assert $inertia) => $inertia->missingFlash('network_account'));
    }
}
