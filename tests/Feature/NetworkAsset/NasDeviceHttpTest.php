<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\NetworkAsset\Jobs\SyncNasDeviceJob;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAsset;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithRouterOs;

class NasDeviceHttpTest extends TestCase
{
    use BuildsNetworkFixtures;
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use InteractsWithRouterOs;
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = $this->createCompanyUser();
        $this->company = $this->admin->company;
        $this->grantNetworkPermissionsToAdminRole();
        $this->fakeRouterOs();
        $this->actingAs($this->admin);
    }

    /** @param array<string, mixed> $attributes */
    private function routerAsset(?Company $company = null, array $attributes = []): NetworkAsset
    {
        static $next = 10;

        return NetworkAssetFactory::new()->create($attributes + [
            'company_id' => ($company ?? $this->company)->id,
            'asset_type' => 'router',
            'management_ip' => '192.0.2.'.$next++,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(NetworkAsset $asset, array $overrides = []): array
    {
        return $overrides + [
            'network_asset_id' => $asset->id,
            'api_port' => 443,
            'api_username' => 'inbils-api',
            'api_password' => 'fake-router-secret-1',
            'isolir_profile' => 'isolir',
        ];
    }

    private function nasDevice(?Company $company = null): NasDevice
    {
        return NasDevice::factory()->create(['company_id' => ($company ?? $this->company)->id]);
    }

    /** @return list<string> */
    private function contractKeys(): array
    {
        return ['id', 'network_asset', 'api_port', 'api_username', 'has_api_password', 'isolir_profile', 'provisioning_enabled', 'routeros_version', 'last_checked_at', 'last_seen_at', 'unreachable_since', 'last_error', 'unmanaged_count', 'accounts_count', 'accounts_failed_count'];
    }

    public function test_index_lists_only_this_companys_routers_with_the_contract_shape_and_no_password(): void
    {
        $mine = $this->nasDevice();
        $this->nasDevice(Company::factory()->create(['is_active' => true]));
        $this->networkAccountFor($this->subscriptionIn($this->company), $mine);

        $response = $this->get(route('admin.nas-devices.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('nasDevices.data', 1)
            ->where('nasDevices.data.0.id', $mine->id)
            ->where('nasDevices.data.0.accounts_count', 1)
            ->where('nasDevices.data.0.accounts_failed_count', 0)
            ->where('nasDevices.data.0.has_api_password', true)
            ->where('nasDevices.data.0.network_asset.code', $mine->networkAsset->code)
            ->where('can.create', true)
            ->has('filters'));
        $this->assertSame($this->contractKeys(), array_keys($response->inertiaProps()['nasDevices']['data'][0]));
        $this->assertStringNotContainsString('fake-router-api-password', json_encode($response->inertiaProps()));
    }

    public function test_index_searches_by_asset_code_name_or_address_and_sorts_by_asset_code(): void
    {
        $first = $this->nasDevice();
        $second = $this->nasDevice();
        $first->networkAsset->update(['code' => 'AST-ZZZ', 'name' => 'Core router']);
        $second->networkAsset->update(['code' => 'AST-AAA', 'name' => 'Edge router']);

        $this->get(route('admin.nas-devices.index', ['search' => 'zzz']))
            ->assertInertia(fn (Assert $page) => $page->has('nasDevices.data', 1)->where('nasDevices.data.0.id', $first->id));
        $this->get(route('admin.nas-devices.index', ['search' => 'Edge']))
            ->assertInertia(fn (Assert $page) => $page->has('nasDevices.data', 1)->where('nasDevices.data.0.id', $second->id));
        $this->get(route('admin.nas-devices.index', ['sort_by' => 'code', 'sort_dir' => 'asc']))
            ->assertInertia(fn (Assert $page) => $page->where('nasDevices.data.0.id', $second->id)->where('nasDevices.data.1.id', $first->id));
        $this->get(route('admin.nas-devices.index', ['search' => '%']))
            ->assertInertia(fn (Assert $page) => $page->has('nasDevices.data', 0));
    }

    public function test_create_offers_only_router_assets_with_an_address_that_are_not_registered_yet(): void
    {
        $pop = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'POP Dago']);
        $free = $this->routerAsset(attributes: ['location_id' => $pop->id]);
        $registered = $this->nasDevice()->networkAsset;
        $this->routerAsset(attributes: ['management_ip' => null]);
        $this->routerAsset(attributes: ['asset_type' => 'switch']);
        $this->routerAsset(Company::factory()->create(['is_active' => true]));

        $this->get(route('admin.nas-devices.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('routerAssets', 1)
                ->where('routerAssets.0', ['id' => $free->id, 'code' => $free->code, 'name' => $free->name, 'management_ip' => $free->management_ip, 'location_name' => 'POP Dago']));
        $this->assertNotContains($registered->id, collect($this->get(route('admin.nas-devices.create'))->inertiaProps()['routerAssets'])->pluck('id')->all());
    }

    public function test_store_registers_the_router_with_writes_off_encrypts_the_password_and_audits_without_it(): void
    {
        $asset = $this->routerAsset();

        $this->post(route('admin.nas-devices.store'), $this->payload($asset, ['api_port' => 8729]))
            ->assertRedirect(route('admin.nas-devices.show', NasDevice::query()->firstOrFail()));

        $nas = NasDevice::query()->firstOrFail();
        $this->assertSame($asset->id, $nas->network_asset_id);
        $this->assertSame(8729, $nas->api_port);
        $this->assertFalse($nas->provisioning_enabled);
        $this->assertSame('fake-router-secret-1', $nas->api_password);
        $this->assertStringNotContainsString('fake-router-secret-1', (string) DB::table('nas_devices')->value('api_password'));
        $audit = Activity::query()->where('log_name', 'nas_device')->where('description', 'created')->firstOrFail();
        $this->assertSame($this->admin->id, $audit->causer_id);
        $this->assertStringNotContainsString('fake-router-secret-1', json_encode($audit->properties));
    }

    public function test_store_defaults_the_port_and_the_isolir_profile(): void
    {
        $asset = $this->routerAsset();

        $this->post(route('admin.nas-devices.store'), ['network_asset_id' => $asset->id, 'api_username' => 'u', 'api_password' => 'fake-pass'])->assertRedirect();

        $nas = NasDevice::query()->firstOrFail();
        $this->assertSame(443, $nas->api_port);
        $this->assertSame('isolir', $nas->isolir_profile);
    }

    public function test_store_validation_refuses_what_a_router_must_not_be(): void
    {
        $registered = $this->nasDevice()->networkAsset;
        $foreign = $this->routerAsset(Company::factory()->create(['is_active' => true]));
        $cases = [
            'a switch' => [$this->payload($this->routerAsset(attributes: ['asset_type' => 'switch'])), 'network_asset_id'],
            "another company's asset" => [$this->payload($foreign), 'network_asset_id'],
            'no management ip' => [$this->payload($this->routerAsset(attributes: ['management_ip' => null])), 'network_asset_id'],
            'already registered' => [$this->payload($registered), 'network_asset_id'],
            'unknown asset' => [$this->payload($registered, ['network_asset_id' => 999999]), 'network_asset_id'],
            'port 0' => [$this->payload($this->routerAsset(), ['api_port' => 0]), 'api_port'],
            'port 70000' => [$this->payload($this->routerAsset(), ['api_port' => 70000]), 'api_port'],
            'port text' => [$this->payload($this->routerAsset(), ['api_port' => 'abc']), 'api_port'],
            'isolir profile with spaces' => [$this->payload($this->routerAsset(), ['isolir_profile' => 'bad profile!']), 'isolir_profile'],
            'isolir profile too long' => [$this->payload($this->routerAsset(), ['isolir_profile' => str_repeat('a', 65)]), 'isolir_profile'],
            'no username' => [$this->payload($this->routerAsset(), ['api_username' => '']), 'api_username'],
            'long username' => [$this->payload($this->routerAsset(), ['api_username' => str_repeat('u', 65)]), 'api_username'],
            'no password' => [$this->payload($this->routerAsset(), ['api_password' => '']), 'api_password'],
            'long password' => [$this->payload($this->routerAsset(), ['api_password' => str_repeat('p', 129)]), 'api_password'],
        ];

        foreach ($cases as $name => [$payload, $field]) {
            $response = $this->postJson(route('admin.nas-devices.store'), $payload);
            $this->assertSame(422, $response->getStatusCode(), $name);
            $response->assertJsonValidationErrors($field);
        }

        $this->assertSame(1, NasDevice::query()->count());
    }

    public function test_show_has_the_contract_props(): void
    {
        $nas = $this->nasDevice();
        $nas->forceFill(['unmanaged_count' => 2, 'unmanaged_usernames' => ['stray-1', 'stray-2'], 'routeros_version' => '7.16.2 (stable)'])->save();
        $account = $this->networkAccountFor($this->subscriptionIn($this->company), $nas);

        $response = $this->get(route('admin.nas-devices.show', $nas));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('nasDevice.id', $nas->id)
            ->where('nasDevice.routeros_version', '7.16.2 (stable)')
            ->where('nasDevice.accounts_count', 1)
            ->has('accounts.data', 1)
            ->where('accounts.data.0.id', $account->id)
            ->where('accounts.data.0.subscription.code', $account->subscription->code)
            ->where('accounts.data.0.subscription.customer_name', $account->subscription->customer->name)
            ->where('unmanagedUsernames', ['stray-1', 'stray-2'])
            ->where('isolirUrl', route('isolir.show', $this->company->code))
            ->where('installEnabled', true)
            ->where('can', ['update' => true, 'check' => true]));
        $this->assertSame($this->contractKeys(), array_keys($response->inertiaProps()['nasDevice']));
        $this->assertSame(['id', 'nas_device_id', 'nas_label', 'service_type', 'username', 'sync_status', 'last_error', 'provisioned_profile', 'provisioned_at', 'online', 'last_online_at', 'current_address', 'subscription'], array_keys($response->inertiaProps()['accounts']['data'][0]));
        $encoded = json_encode($response->inertiaProps());
        $this->assertStringNotContainsString('fake-router-api-password', $encoded);
        $this->assertStringNotContainsString('fake-subscriber-secret', $encoded);
    }

    public function test_show_tells_whether_the_installation_switch_is_off(): void
    {
        $this->disableRouterOs();

        $this->get(route('admin.nas-devices.show', $this->nasDevice()))
            ->assertInertia(fn (Assert $page) => $page->where('installEnabled', false));
    }

    public function test_edit_has_the_router_asset_and_never_the_password(): void
    {
        $nas = $this->nasDevice();

        $response = $this->get(route('admin.nas-devices.edit', $nas));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('nasDevice.id', $nas->id)
            ->where('nasDevice.has_api_password', true)
            ->has('routerAssets', 1)
            ->where('routerAssets.0.id', $nas->network_asset_id));
        $this->assertStringNotContainsString('fake-router-api-password', json_encode($response->inertiaProps()));
    }

    public function test_update_keeps_the_stored_password_when_it_is_blank(): void
    {
        $nas = $this->nasDevice();

        foreach ([null, ''] as $blank) {
            $this->put(route('admin.nas-devices.update', $nas), ['api_port' => 8729, 'api_password' => $blank, 'provisioning_enabled' => true])
                ->assertRedirect(route('admin.nas-devices.show', $nas));
        }

        $nas = $nas->fresh();
        $this->assertSame('fake-router-api-password', $nas->api_password);
        $this->assertSame(8729, $nas->api_port);
        $this->assertTrue($nas->provisioning_enabled);
    }

    public function test_update_replaces_the_password_and_audits_only_credentials_changed(): void
    {
        $nas = $this->nasDevice();

        $this->put(route('admin.nas-devices.update', $nas), ['api_username' => 'new-user', 'api_password' => 'fake-new-router-pass', 'isolir_profile' => 'blocked'])->assertRedirect();

        $this->assertSame('fake-new-router-pass', $nas->fresh()->api_password);
        $audit = Activity::query()->where('log_name', 'nas_device')->where('description', 'updated')->firstOrFail();
        $this->assertTrue($audit->properties['credentials_changed']);
        $this->assertEqualsCanonicalizing(['api_username', 'isolir_profile'], $audit->properties['changed']);
        $this->assertStringNotContainsString('fake-new-router-pass', json_encode($audit->properties));
        $this->assertStringNotContainsString('fake-router-api-password', json_encode($audit->properties));
    }

    public function test_update_that_changes_nothing_writes_no_audit_entry(): void
    {
        $nas = $this->nasDevice();

        $this->put(route('admin.nas-devices.update', $nas), ['api_port' => 443, 'isolir_profile' => 'isolir'])->assertRedirect();

        $this->assertSame(0, Activity::query()->where('log_name', 'nas_device')->where('description', 'updated')->count());
    }

    public function test_update_validates_and_never_changes_the_router_asset(): void
    {
        $nas = $this->nasDevice();
        $other = $this->routerAsset();

        $this->putJson(route('admin.nas-devices.update', $nas), ['api_port' => 70000, 'isolir_profile' => 'bad profile'])
            ->assertUnprocessable()->assertJsonValidationErrors(['api_port', 'isolir_profile']);
        $this->put(route('admin.nas-devices.update', $nas), ['network_asset_id' => $other->id, 'api_port' => 8729])->assertRedirect();

        $this->assertNotSame($other->id, $nas->fresh()->network_asset_id);
    }

    public function test_a_router_with_network_accounts_cannot_be_deleted_and_one_without_can(): void
    {
        $used = $this->nasDevice();
        $this->networkAccountFor($this->subscriptionIn($this->company), $used);
        $free = $this->nasDevice();

        $this->deleteJson(route('admin.nas-devices.destroy', $used))->assertUnprocessable()->assertJsonValidationErrors('nas_device');
        $this->assertNotNull($used->fresh());

        $this->delete(route('admin.nas-devices.destroy', $free))->assertRedirect(route('admin.nas-devices.index'));
        $this->assertNull(NasDevice::query()->find($free->id));
        $this->assertSame(1, Activity::query()->where('log_name', 'nas_device')->where('description', 'deleted')->count());
        $this->assertNotNull($free->networkAsset->fresh());
    }

    public function test_check_queues_exactly_one_sync_job_for_that_router(): void
    {
        Queue::fake();
        $nas = $this->nasDevice();

        $this->post(route('admin.nas-devices.check', $nas))->assertRedirect()->assertSessionHas('success');

        Queue::assertPushed(SyncNasDeviceJob::class, 1);
        Queue::assertPushed(SyncNasDeviceJob::class, fn (SyncNasDeviceJob $job): bool => $job->nasDeviceId === $nas->id && $job->companyId === $this->company->id);
    }

    public function test_check_runs_the_probe_and_the_router_page_shows_the_result(): void
    {
        $nas = $this->nasDevice();
        $this->routerFor($nas);

        $this->post(route('admin.nas-devices.check', $nas))->assertRedirect();

        $this->get(route('admin.nas-devices.show', $nas))
            ->assertInertia(fn (Assert $page) => $page->where('nasDevice.routeros_version', '7.16.2 (stable)')->where('nasDevice.last_error', null));
    }

    public function test_check_is_throttled_to_six_a_minute(): void
    {
        Queue::fake();
        $nas = $this->nasDevice();

        foreach (range(1, 6) as $_) {
            $this->post(route('admin.nas-devices.check', $nas))->assertRedirect();
        }

        $this->post(route('admin.nas-devices.check', $nas))->assertStatus(429);
        // Unique until processing: the five repeats while one check is still queued are dropped, not piled up.
        Queue::assertPushed(SyncNasDeviceJob::class, 1);
    }

    public function test_every_route_needs_its_permission(): void
    {
        $nas = $this->nasDevice();
        $nobody = $this->userWithPermissions($this->company);
        $viewer = $this->userWithPermissions($this->company, 'network_asset.nas.view');
        $calls = [
            'index' => [fn () => $this->get(route('admin.nas-devices.index')), false],
            'show' => [fn () => $this->get(route('admin.nas-devices.show', $nas)), false],
            'create' => [fn () => $this->get(route('admin.nas-devices.create')), true],
            'store' => [fn () => $this->postJson(route('admin.nas-devices.store'), $this->payload($this->routerAsset())), true],
            'edit' => [fn () => $this->get(route('admin.nas-devices.edit', $nas)), true],
            'update' => [fn () => $this->putJson(route('admin.nas-devices.update', $nas), ['api_port' => 8729]), true],
            'destroy' => [fn () => $this->deleteJson(route('admin.nas-devices.destroy', $nas)), true],
            'check' => [fn () => $this->postJson(route('admin.nas-devices.check', $nas)), true],
        ];

        foreach ($calls as $name => [$call, $needsManage]) {
            $this->actingAs($nobody);
            $this->assertSame(403, $call()->getStatusCode(), "$name without any permission");

            $this->actingAs($viewer);
            $needsManage
                ? $this->assertSame(403, $call()->getStatusCode(), "$name with view only")
                : $this->assertSame(200, $call()->getStatusCode(), "$name with view");
        }

        $this->assertNotNull($nas->fresh());
    }

    public function test_another_companys_router_is_a_404_on_every_route_that_names_it(): void
    {
        $foreign = $this->nasDevice(Company::factory()->create(['is_active' => true]));

        $this->get(route('admin.nas-devices.show', $foreign))->assertNotFound();
        $this->get(route('admin.nas-devices.edit', $foreign))->assertNotFound();
        $this->put(route('admin.nas-devices.update', $foreign), ['api_port' => 8729])->assertNotFound();
        $this->delete(route('admin.nas-devices.destroy', $foreign))->assertNotFound();
        $this->post(route('admin.nas-devices.check', $foreign))->assertNotFound();
        $this->assertNotNull(NasDevice::withoutCompany()->find($foreign->id));
        $this->assertSame(443, NasDevice::withoutCompany()->find($foreign->id)->api_port);
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->app['auth']->logout();

        $this->get(route('admin.nas-devices.index'))->assertRedirect(route('login'));
    }
}
