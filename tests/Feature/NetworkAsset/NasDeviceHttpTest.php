<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAsset;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithNasCoa;
use Tests\Traits\InteractsWithRadius;

class NasDeviceHttpTest extends TestCase
{
    use BuildsNetworkFixtures;
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use InteractsWithNasCoa;
    use InteractsWithRadius;
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private string $realm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = $this->createCompanyUser();
        $this->company = $this->admin->company;
        $this->realm = $this->realmOf($this->company);
        $this->grantNetworkPermissionsToAdminRole();
        $this->fakeRadius();
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
            'nasname' => '198.51.100.'.(100 + $asset->id % 100),
            'coa_port' => 1700,
            'radius_secret' => 'fake-radius-secret-1',
        ];
    }

    private function nasDevice(?Company $company = null): NasDevice
    {
        return NasDevice::factory()->create(['company_id' => ($company ?? $this->company)->id]);
    }

    /** @return list<string> */
    private function contractKeys(): array
    {
        return ['id', 'network_asset', 'nasname', 'shortname', 'coa_port', 'has_radius_secret', 'radius_synced_at', 'last_error'];
    }

    public function test_index_lists_only_this_companys_nas_rows_with_the_contract_shape_and_no_secret(): void
    {
        $mine = $this->nasDevice();
        $this->nasDevice(Company::factory()->create(['is_active' => true]));

        $response = $this->get(route('admin.nas-devices.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('nasDevices.data', 1)
            ->where('nasDevices.data.0.id', $mine->id)
            ->where('nasDevices.data.0.nasname', $mine->nasname)
            ->where('nasDevices.data.0.shortname', $this->realm)
            ->where('nasDevices.data.0.coa_port', 1700)
            ->where('nasDevices.data.0.has_radius_secret', true)
            ->where('nasDevices.data.0.network_asset.code', $mine->networkAsset->code)
            ->where('can.create', true)
            ->has('filters'));
        $this->assertSame($this->contractKeys(), array_keys($response->inertiaProps()['nasDevices']['data'][0]));
        $this->assertStringNotContainsString('fake-radius-shared-secret', json_encode($response->inertiaProps()));
    }

    public function test_index_searches_by_asset_code_name_or_address_and_sorts_by_asset_code(): void
    {
        $first = $this->nasDevice();
        $second = $this->nasDevice();
        $first->networkAsset->update(['code' => 'AST-ZZZ', 'name' => 'Core router']);
        $second->networkAsset->update(['code' => 'AST-AAA', 'name' => 'Edge router']);
        $second->forceFill(['nasname' => '203.0.113.77'])->save();

        $this->get(route('admin.nas-devices.index', ['search' => 'zzz']))
            ->assertInertia(fn (Assert $page) => $page->has('nasDevices.data', 1)->where('nasDevices.data.0.id', $first->id));
        $this->get(route('admin.nas-devices.index', ['search' => 'Edge']))
            ->assertInertia(fn (Assert $page) => $page->has('nasDevices.data', 1)->where('nasDevices.data.0.id', $second->id));
        $this->get(route('admin.nas-devices.index', ['search' => '203.0.113']))
            ->assertInertia(fn (Assert $page) => $page->has('nasDevices.data', 1)->where('nasDevices.data.0.id', $second->id));
        $this->get(route('admin.nas-devices.index', ['sort_by' => 'code', 'sort_dir' => 'asc']))
            ->assertInertia(fn (Assert $page) => $page->where('nasDevices.data.0.id', $second->id)->where('nasDevices.data.1.id', $first->id));
        $this->get(route('admin.nas-devices.index', ['search' => '%']))
            ->assertInertia(fn (Assert $page) => $page->has('nasDevices.data', 0));
    }

    public function test_create_offers_only_unregistered_router_assets_with_an_address_and_the_realm(): void
    {
        $pop = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'POP Dago']);
        $free = $this->routerAsset(attributes: ['location_id' => $pop->id]);
        $registered = $this->nasDevice()->networkAsset;
        $this->routerAsset(attributes: ['management_ip' => null]);
        $this->routerAsset(attributes: ['asset_type' => 'switch']);
        $this->routerAsset(Company::factory()->create(['is_active' => true]));

        $response = $this->get(route('admin.nas-devices.create'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('routerAssets', 1)
            ->where('routerAssets.0', ['id' => $free->id, 'code' => $free->code, 'name' => $free->name, 'management_ip' => $free->management_ip, 'location_name' => 'POP Dago'])
            ->where('realm', $this->realm));
        $this->assertNotContains($registered->id, collect($response->inertiaProps()['routerAssets'])->pluck('id')->all());
    }

    public function test_create_has_no_realm_while_the_company_code_cannot_be_one(): void
    {
        $this->company->update(['code' => 'ACME_BAD CODE']);

        $this->get(route('admin.nas-devices.create'))->assertInertia(fn (Assert $page) => $page->where('realm', null));
    }

    public function test_store_registers_the_client_in_radius_encrypts_the_secret_and_audits_without_it(): void
    {
        $asset = $this->routerAsset();
        $this->radiusConnection()->table('nas')->insert(['nasname' => '127.0.0.1', 'shortname' => 'lab', 'secret' => 'fake-lab-secret']);

        $this->post(route('admin.nas-devices.store'), $this->payload($asset, ['nasname' => '198.51.100.20', 'coa_port' => 13799]))
            ->assertRedirect(route('admin.nas-devices.show', NasDevice::query()->firstOrFail()))
            ->assertSessionHas('success')
            ->assertSessionMissing('warning');

        $nas = NasDevice::query()->firstOrFail();
        $this->assertSame([$asset->id, '198.51.100.20', 13799, 'fake-radius-secret-1'], [$nas->network_asset_id, $nas->nasname, $nas->coa_port, $nas->radius_secret]);
        $this->assertStringNotContainsString('fake-radius-secret-1', (string) DB::table('nas_devices')->value('radius_secret'));
        $this->assertSame('198.51.100.20', $nas->radius_nasname);
        $this->assertNotNull($nas->radius_synced_at);
        $this->assertNull($nas->last_error);

        $row = $this->radiusRows('nas', ['nasname' => '198.51.100.20']);
        $this->assertCount(1, $row);
        $this->assertSame([$this->realm, 'fake-radius-secret-1'], [$row[0]['shortname'], $row[0]['secret']]);
        $this->assertCount(1, $this->radiusRows('nas', ['nasname' => '127.0.0.1']), 'rows Inbils did not write stay');

        $audit = Activity::query()->where('log_name', 'nas_device')->where('description', 'created')->firstOrFail();
        $this->assertSame($this->admin->id, $audit->causer_id);
        $this->assertSame(1, Activity::query()->where('log_name', 'nas_device')->where('description', 'radius_written')->count());
        foreach (Activity::query()->get() as $entry) {
            $this->assertStringNotContainsString('fake-radius-secret-1', json_encode($entry->properties));
        }
    }

    public function test_store_defaults_the_address_to_the_management_ip_and_the_port_to_1700(): void
    {
        $asset = $this->routerAsset();

        $this->post(route('admin.nas-devices.store'), ['network_asset_id' => $asset->id, 'radius_secret' => 'fake-radius-secret-2'])->assertRedirect();

        $nas = NasDevice::query()->firstOrFail();
        $this->assertSame([$asset->management_ip, 1700], [$nas->nasname, $nas->coa_port]);
    }

    public function test_store_with_the_switch_off_saves_the_row_writes_nothing_and_warns(): void
    {
        config(['services.radius.enabled' => false]);
        $asset = $this->routerAsset();

        $this->post(route('admin.nas-devices.store'), $this->payload($asset))->assertRedirect()->assertSessionHas('warning');

        $nas = NasDevice::query()->firstOrFail();
        $this->assertNull($nas->radius_synced_at);
        $this->assertNotNull($nas->last_error);
        $this->assertSame([], $this->radiusRows('nas'));
    }

    public function test_store_while_radius_is_down_never_fails_and_leaves_the_row_for_the_sync(): void
    {
        Schema::connection('radius')->drop('nas');
        $asset = $this->routerAsset();

        $this->post(route('admin.nas-devices.store'), $this->payload($asset))->assertRedirect()->assertSessionHas('warning');

        $nas = NasDevice::query()->firstOrFail();
        $this->assertNull($nas->radius_synced_at);
        $this->assertSame('The RADIUS database is unavailable.', $nas->last_error);
        $this->assertSame(1, Activity::query()->where('description', 'radius_failed')->count());
    }

    public function test_store_refuses_a_company_whose_code_cannot_be_a_realm(): void
    {
        $this->company->update(['code' => 'ACME_BAD CODE']);

        $this->postJson(route('admin.nas-devices.store'), $this->payload($this->routerAsset()))->assertUnprocessable()->assertJsonValidationErrors('realm');

        $this->assertSame(0, NasDevice::query()->count());
        $this->assertSame([], $this->radiusRows('nas'));
    }

    public function test_store_validation_refuses_what_a_nas_must_not_be(): void
    {
        $registered = $this->nasDevice();
        $foreign = $this->routerAsset(Company::factory()->create(['is_active' => true]));
        $foreignNas = $this->nasDevice(Company::factory()->create(['is_active' => true]));
        $cases = [
            'a switch' => [$this->payload($this->routerAsset(attributes: ['asset_type' => 'switch'])), 'network_asset_id'],
            "another company's asset" => [$this->payload($foreign), 'network_asset_id'],
            'no management ip' => [$this->payload($this->routerAsset(attributes: ['management_ip' => null])), 'network_asset_id'],
            'already registered' => [$this->payload($registered->networkAsset), 'network_asset_id'],
            'unknown asset' => [$this->payload($registered->networkAsset, ['network_asset_id' => 999999]), 'network_asset_id'],
            'address not an IPv4' => [$this->payload($this->routerAsset(), ['nasname' => 'router.example.net']), 'nasname'],
            'address is IPv6' => [$this->payload($this->routerAsset(), ['nasname' => '2001:db8::1']), 'nasname'],
            'address used here' => [$this->payload($this->routerAsset(), ['nasname' => $registered->nasname]), 'nasname'],
            'address used by another company' => [$this->payload($this->routerAsset(), ['nasname' => $foreignNas->nasname]), 'nasname'],
            'port 0' => [$this->payload($this->routerAsset(), ['coa_port' => 0]), 'coa_port'],
            'port 70000' => [$this->payload($this->routerAsset(), ['coa_port' => 70000]), 'coa_port'],
            'port text' => [$this->payload($this->routerAsset(), ['coa_port' => 'abc']), 'coa_port'],
            'no secret' => [$this->payload($this->routerAsset(), ['radius_secret' => '']), 'radius_secret'],
            'short secret' => [$this->payload($this->routerAsset(), ['radius_secret' => 'short']), 'radius_secret'],
            'long secret' => [$this->payload($this->routerAsset(), ['radius_secret' => str_repeat('s', 61)]), 'radius_secret'],
            'secret with a space' => [$this->payload($this->routerAsset(), ['radius_secret' => 'fake secret 123']), 'radius_secret'],
        ];

        foreach ($cases as $name => [$payload, $field]) {
            $response = $this->postJson(route('admin.nas-devices.store'), $payload);
            $this->assertSame(422, $response->getStatusCode(), $name);
            $response->assertJsonValidationErrors($field);
        }

        $this->assertSame(1, NasDevice::query()->count());
        $this->assertSame([], $this->radiusRows('nas'));
    }

    public function test_an_address_taken_by_another_company_is_refused_without_saying_so(): void
    {
        $foreignNas = $this->nasDevice(Company::factory()->create(['is_active' => true]));

        $response = $this->postJson(route('admin.nas-devices.store'), $this->payload($this->routerAsset(), ['nasname' => $foreignNas->nasname]));

        $response->assertJsonValidationErrors('nasname');
        $this->assertSame('This address is not available.', $response->json('errors.nasname.0'));
    }

    public function test_show_has_the_contract_props_and_the_sessions_open_on_the_router(): void
    {
        $nas = $this->nasDevice();
        $subscription = $this->subscriptionIn($this->company);
        $this->networkAccountFor($subscription, ['username' => "alice@{$this->realm}"]);
        $this->seedRadiusSession(['username' => "alice@{$this->realm}", 'nasipaddress' => $nas->nasname]);
        $this->seedRadiusSession(['username' => "ghost@{$this->realm}", 'nasipaddress' => $nas->nasname]);
        $this->seedRadiusSession(['username' => "elsewhere@{$this->realm}", 'nasipaddress' => '203.0.113.9']);
        $this->seedRadiusSession(['username' => "old@{$this->realm}", 'nasipaddress' => $nas->nasname, 'acctstoptime' => now()->format('Y-m-d H:i:s')]);

        $response = $this->get(route('admin.nas-devices.show', $nas));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('nasDevice.id', $nas->id)
            ->has('sessions', 2)
            ->where('isolirUrl', route('isolir.show', $this->company->code))
            ->where('radiusEnabled', true)
            ->where('can', ['update' => true]));
        $props = $response->inertiaProps();
        $this->assertSame($this->contractKeys(), array_keys($props['nasDevice']));
        $byUser = collect($props['sessions'])->keyBy('username');
        $this->assertSame(['session_id', 'nas_ip', 'nas_label', 'framed_ip', 'started_at', 'updated_at', 'username', 'subscription'], array_keys($byUser["alice@{$this->realm}"]));
        $this->assertSame(['id' => $subscription->id, 'code' => $subscription->code], $byUser["alice@{$this->realm}"]['subscription']);
        $this->assertNull($byUser["ghost@{$this->realm}"]['subscription']);
        $this->assertSame($nas->networkAsset->code.' - '.$nas->networkAsset->name, $byUser["alice@{$this->realm}"]['nas_label']);
        $this->assertStringNotContainsString('fake-radius-shared-secret', json_encode($props));
    }

    public function test_show_has_no_sessions_while_the_switch_is_off_or_radius_is_down(): void
    {
        $nas = $this->nasDevice();
        $this->seedRadiusSession(['nasipaddress' => $nas->nasname]);

        Schema::connection('radius')->drop('radacct');
        $this->get(route('admin.nas-devices.show', $nas))->assertOk()->assertInertia(fn (Assert $page) => $page->where('sessions', [])->where('radiusEnabled', true));

        config(['services.radius.enabled' => false]);
        $this->get(route('admin.nas-devices.show', $nas))->assertOk()->assertInertia(fn (Assert $page) => $page->where('sessions', [])->where('radiusEnabled', false));
    }

    public function test_edit_has_the_router_asset_the_realm_and_never_the_secret(): void
    {
        $nas = $this->nasDevice();

        $response = $this->get(route('admin.nas-devices.edit', $nas));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('nasDevice.id', $nas->id)
            ->where('nasDevice.has_radius_secret', true)
            ->where('realm', $this->realm)
            ->has('routerAssets', 1)
            ->where('routerAssets.0.id', $nas->network_asset_id));
        $this->assertStringNotContainsString('fake-radius-shared-secret', json_encode($response->inertiaProps()));
    }

    public function test_update_keeps_the_stored_secret_when_it_is_blank(): void
    {
        $nas = $this->nasDevice();

        foreach ([null, ''] as $blank) {
            $this->put(route('admin.nas-devices.update', $nas), ['coa_port' => 13799, 'radius_secret' => $blank])
                ->assertRedirect(route('admin.nas-devices.show', $nas));
        }

        $nas = $nas->fresh();
        $this->assertSame('fake-radius-shared-secret', $nas->radius_secret);
        $this->assertSame(13799, $nas->coa_port);
    }

    public function test_update_replaces_the_secret_in_radius_and_audits_secret_changed_without_it(): void
    {
        $nas = $this->nasDevice();
        $this->post(route('admin.nas-devices.update', $nas), ['_method' => 'PUT', 'radius_secret' => 'fake-first-secret-1'])->assertRedirect();

        $this->put(route('admin.nas-devices.update', $nas), ['radius_secret' => 'fake-new-radius-secret'])->assertRedirect()->assertSessionMissing('warning');

        $this->assertSame('fake-new-radius-secret', $nas->fresh()->radius_secret);
        $this->assertSame('fake-new-radius-secret', $this->radiusRows('nas', ['nasname' => $nas->nasname])[0]['secret']);
        $this->assertSame(2, Activity::query()->where('log_name', 'nas_device')->where('description', 'secret_changed')->count());
        foreach (Activity::query()->get() as $entry) {
            $this->assertStringNotContainsString('fake-new-radius-secret', json_encode($entry->properties));
            $this->assertStringNotContainsString('fake-first-secret-1', json_encode($entry->properties));
            $this->assertStringNotContainsString('fake-radius-shared-secret', json_encode($entry->properties));
        }
    }

    public function test_update_of_the_address_writes_the_new_client_and_removes_the_old_one(): void
    {
        $nas = $this->nasDevice();
        $this->put(route('admin.nas-devices.update', $nas), ['coa_port' => 1700])->assertRedirect();
        $old = $nas->nasname;
        $this->radiusConnection()->table('nas')->insert(['nasname' => '127.0.0.1', 'shortname' => 'lab', 'secret' => 'fake-lab-secret']);
        $nas->forceFill(['radius_nasname' => $old])->save();
        $this->radiusConnection()->table('nas')->insert(['nasname' => $old, 'shortname' => $this->realm, 'secret' => 'fake-radius-shared-secret']);

        $this->put(route('admin.nas-devices.update', $nas), ['nasname' => '203.0.113.50'])->assertRedirect();

        $this->assertSame([], $this->radiusRows('nas', ['nasname' => $old]));
        $this->assertCount(1, $this->radiusRows('nas', ['nasname' => '203.0.113.50']));
        $this->assertCount(1, $this->radiusRows('nas', ['nasname' => '127.0.0.1']));
        $this->assertSame('203.0.113.50', $nas->fresh()->radius_nasname);
        $audit = Activity::query()->where('log_name', 'nas_device')->where('description', 'updated')->firstOrFail();
        $this->assertSame(['nasname'], $audit->properties['changed']);
    }

    public function test_update_that_changes_nothing_writes_no_audit_entry_and_no_radius_row(): void
    {
        $nas = $this->nasDevice();

        $this->put(route('admin.nas-devices.update', $nas), ['coa_port' => 1700, 'nasname' => $nas->nasname])->assertRedirect();

        $this->assertSame(0, Activity::query()->where('log_name', 'nas_device')->count());
        $this->assertSame([], $this->radiusRows('nas'));
    }

    public function test_update_while_radius_is_down_keeps_the_change_and_marks_the_row_unsynced(): void
    {
        $nas = $this->nasDevice();
        Schema::connection('radius')->drop('nas');

        $this->put(route('admin.nas-devices.update', $nas), ['radius_secret' => 'fake-new-radius-secret'])->assertRedirect()->assertSessionHas('warning');

        $nas = $nas->fresh();
        $this->assertSame('fake-new-radius-secret', $nas->radius_secret);
        $this->assertNull($nas->radius_synced_at);
        $this->assertNotNull($nas->last_error);
    }

    public function test_update_validates_and_never_changes_the_router_asset(): void
    {
        $nas = $this->nasDevice();
        $other = $this->nasDevice();
        $asset = $this->routerAsset();

        $this->putJson(route('admin.nas-devices.update', $nas), ['coa_port' => 70000, 'radius_secret' => 'short', 'nasname' => $other->nasname])
            ->assertUnprocessable()->assertJsonValidationErrors(['coa_port', 'radius_secret', 'nasname']);
        $this->put(route('admin.nas-devices.update', $nas), ['network_asset_id' => $asset->id, 'coa_port' => 13799])->assertRedirect();

        $this->assertNotSame($asset->id, $nas->fresh()->network_asset_id);
    }

    public function test_delete_removes_the_radius_client_even_while_accounts_exist(): void
    {
        $nas = $this->nasDevice();
        $this->put(route('admin.nas-devices.update', $nas), ['coa_port' => 13799])->assertRedirect();
        $this->radiusConnection()->table('nas')->insert(['nasname' => '127.0.0.1', 'shortname' => 'lab', 'secret' => 'fake-lab-secret']);
        $this->radiusConnection()->table('nas')->insert(['nasname' => $nas->nasname, 'shortname' => $this->realm, 'secret' => 'fake-radius-shared-secret']);
        $nas->forceFill(['radius_nasname' => $nas->nasname])->save();
        $account = $this->networkAccountFor($this->subscriptionIn($this->company));

        $this->delete(route('admin.nas-devices.destroy', $nas))->assertRedirect(route('admin.nas-devices.index'))->assertSessionMissing('warning');

        $this->assertNull(NasDevice::query()->find($nas->id));
        $this->assertSame([], $this->radiusRows('nas', ['nasname' => $nas->nasname]));
        $this->assertCount(1, $this->radiusRows('nas', ['nasname' => '127.0.0.1']));
        $this->assertNotNull($account->fresh());
        $this->assertNotNull($nas->networkAsset->fresh());
        $this->assertSame(1, Activity::query()->where('log_name', 'nas_device')->where('description', 'deleted')->count());
    }

    public function test_delete_never_fails_when_radius_cannot_be_reached(): void
    {
        $nas = $this->nasDevice();
        $nas->forceFill(['radius_nasname' => $nas->nasname])->save();
        Schema::connection('radius')->drop('nas');

        $this->delete(route('admin.nas-devices.destroy', $nas))->assertRedirect(route('admin.nas-devices.index'))->assertSessionHas('warning');

        $this->assertNull(NasDevice::query()->find($nas->id));
    }

    public function test_the_reachability_check_route_is_gone(): void
    {
        $this->assertFalse(Route::has('admin.nas-devices.check'));
        $this->post('/admin/nas-devices/'.$this->nasDevice()->id.'/check')->assertNotFound();
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
            'update' => [fn () => $this->putJson(route('admin.nas-devices.update', $nas), ['coa_port' => 13799]), true],
            'destroy' => [fn () => $this->deleteJson(route('admin.nas-devices.destroy', $nas)), true],
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
        $this->assertSame([], $this->radiusRows('nas'));
    }

    public function test_another_companys_router_is_a_404_on_every_route_that_names_it(): void
    {
        $foreign = $this->nasDevice(Company::factory()->create(['is_active' => true]));

        $this->get(route('admin.nas-devices.show', $foreign))->assertNotFound();
        $this->get(route('admin.nas-devices.edit', $foreign))->assertNotFound();
        $this->put(route('admin.nas-devices.update', $foreign), ['coa_port' => 13799])->assertNotFound();
        $this->delete(route('admin.nas-devices.destroy', $foreign))->assertNotFound();
        $this->assertSame(1700, NasDevice::withoutCompany()->find($foreign->id)->coa_port);
    }

    public function test_a_failed_form_never_flashes_the_radius_secret_into_the_session(): void
    {
        $asset = $this->routerAsset();

        $this->post(route('admin.nas-devices.store'), $this->payload($asset, ['coa_port' => 70000, 'radius_secret' => 'fake-flash-me-1']))
            ->assertSessionHasErrors('coa_port');
        $this->assertNull(session('_old_input.radius_secret'));

        $this->put(route('admin.nas-devices.update', $this->nasDevice()), ['coa_port' => 70000, 'radius_secret' => 'fake-flash-me-2'])
            ->assertSessionHasErrors('coa_port');
        $this->assertStringNotContainsString('fake-flash-me', json_encode(session()->all()));
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->app['auth']->logout();

        $this->get(route('admin.nas-devices.index'))->assertRedirect(route('login'));
    }
}
