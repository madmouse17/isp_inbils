<?php

namespace Tests\Feature\NetworkAsset;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Modules\NetworkAsset\Models\AssetPort;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Models\OdnSplitter;
use Modules\NetworkAsset\Models\PortTemplate;
use Modules\NetworkAsset\Services\AssetPanelService;
use Modules\NetworkAsset\Support\PanelLayout;
use Modules\NetworkAsset\Support\PortTemplateCatalogue;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;
use Tests\Traits\BuildsOdnTopology;

/**
 * The write side of the hardware panel (spec .scratch/hardware-panel-ui, design §4.7): applying a model, the 256 port
 * cap, linking ports, company scoping, delete restrictions and rack placement. The broad suites live in the QA slice.
 */
class AssetPanelWriteRulesTest extends TestCase
{
    use BuildsIpamOdnFixtures;
    use BuildsOdnTopology;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->bootIpamOdnWorld(true);
    }

    /** @param array<string, mixed> $attributes */
    private function asset(string $type, string $code, array $attributes = []): NetworkAsset
    {
        return $this->makeAsset($this->company, $type, null, ['code' => $code, ...$attributes]);
    }

    /** @param array<string, mixed> $body */
    private function apply(NetworkAsset $asset, array $body): TestResponse
    {
        return $this->putJson(route('admin.network-assets.panel.update', $asset), $body);
    }

    private function withModel(NetworkAsset $asset, string $templateKey): NetworkAsset
    {
        AssetPanelService::apply($asset, $templateKey, null, null);

        return $asset->refresh();
    }

    private function port(NetworkAsset $asset, string $name): AssetPort
    {
        return AssetPort::query()->where('network_asset_id', $asset->id)->where('name', $name)->firstOrFail();
    }

    /** @return list<string> */
    private function portNames(NetworkAsset $asset): array
    {
        return AssetPort::query()->where('network_asset_id', $asset->id)->orderBy('id')->pluck('name')->all();
    }

    /** @return array<int, string> id => updated_at of every port row */
    private function portStamps(): array
    {
        return AssetPort::query()->orderBy('id')->get(['id', 'updated_at'])->mapWithKeys(fn (AssetPort $port): array => [$port->id => $port->updated_at->toDateTimeString()])->all();
    }

    /** @return list<array<string, mixed>> the catalogue rows as stored, without the timestamp every sync touches */
    private function catalogue(): array
    {
        return PortTemplate::query()->orderBy('key')->get()->map(fn (PortTemplate $row): array => Arr::except($row->getAttributes(), ['updated_at']))->all();
    }

    private function link(AssetPort $from, AssetPort $to): TestResponse
    {
        return $this->postJson(route('admin.network-assets.ports.link.store', [$from->asset, $from]), ['target_port_id' => $to->public_id]);
    }

    private function unlink(AssetPort $port): TestResponse
    {
        return $this->deleteJson(route('admin.network-assets.ports.link.destroy', [$port->asset, $port]));
    }

    /** @param array<string, mixed> $body */
    private function place(NetworkAsset $asset, array $body): TestResponse
    {
        return $this->putJson(route('admin.network-assets.rack-placement.update', $asset), $body);
    }

    /** @return array{0: NetworkAsset, 1: NetworkAsset} two switches, 8 ports each */
    private function twoSwitches(): array
    {
        return [
            $this->withModel($this->asset('switch', 'SW-A'), 'generic-switch-8'),
            $this->withModel($this->asset('switch', 'SW-B'), 'generic-switch-8'),
        ];
    }

    public function test_applying_a_catalogue_model_creates_one_port_row_per_port_with_the_vendor_names(): void
    {
        $router = $this->asset('router', 'RTR-1');

        $this->apply($router, ['template_key' => 'mikrotik-rb4011igs-rm'])->assertRedirect();

        $router->refresh();
        $this->assertSame(['ether1', 'ether2', 'ether3', 'ether4', 'ether5', 'ether6', 'ether7', 'ether8', 'ether9', 'ether10', 'sfp-sfpplus1'], $this->portNames($router));
        $this->assertSame('mikrotik-rb4011igs-rm', $router->template_key);
        $this->assertSame(1, $router->height_u);
        $this->assertCount(1, $router->port_layout['rows']);

        $sfp = $this->port($router, 'sfp-sfpplus1');
        $this->assertSame(['sfp', 1, 'sfp'], [$sfp->group_key, $sfp->number, $sfp->kind]);
        $this->assertSame($this->company->id, $sfp->company_id);
        $this->assertTrue(Str::isUlid($sfp->public_id));
        $this->assertSame('rj45', $this->port($router, 'ether6')->kind);
        $this->assertSame(6, $this->port($router, 'ether6')->number);
    }

    public function test_reapplying_the_same_model_changes_nothing(): void
    {
        $router = $this->asset('router', 'RTR-1');
        $this->travelTo(now()->startOfMinute());
        $this->apply($router, ['template_key' => 'mikrotik-hap-ac2'])->assertRedirect();
        $ports = $this->portStamps();
        $assetStamp = $router->fresh()->updated_at;

        $this->travel(5)->minutes();
        $this->apply($router, ['template_key' => 'mikrotik-hap-ac2'])->assertRedirect();

        $this->assertSame($ports, $this->portStamps());
        $this->assertEquals($assetStamp, $router->fresh()->updated_at);
        $this->assertSame(1, Activity::query()->where('log_name', 'network_asset')->where('description', 'panel_applied')->count());
    }

    public function test_changing_the_model_keeps_label_and_link_of_ports_in_both_and_drops_the_free_rest(): void
    {
        $switch = $this->withModel($this->asset('switch', 'SW-1'), 'mikrotik-crs326-24g-2s-rm');
        $peer = $this->withModel($this->asset('router', 'RTR-2'), 'mikrotik-hap-ac2');
        $this->assertCount(26, $this->portNames($switch));
        $this->port($switch, 'ether1')->update(['label' => 'ke gedung B', 'note' => 'VLAN 100']);
        $this->link($this->port($switch, 'ether1'), $this->port($peer, 'ether1'))->assertRedirect();

        $this->apply($switch, ['template_key' => 'generic-switch-8'])->assertRedirect();

        $this->assertSame(['1', '2', '3', '4', '5', '6', '7', '8'], $this->portNames($switch));
        $kept = AssetPort::query()->where('network_asset_id', $switch->id)->where('group_key', 'ether')->where('number', 1)->firstOrFail();
        $this->assertSame(['ke gedung B', 'VLAN 100'], [$kept->label, $kept->note]);
        $this->assertSame($this->port($peer, 'ether1')->id, $kept->linked_port_id);
        $this->assertSame($kept->id, $this->port($peer, 'ether1')->linked_port_id);
        $this->assertSame('generic-switch-8', $switch->fresh()->template_key);
    }

    public function test_changing_the_model_refuses_to_remove_a_linked_port_and_names_it(): void
    {
        $switch = $this->withModel($this->asset('switch', 'SW-1'), 'mikrotik-crs326-24g-2s-rm');
        $peer = $this->withModel($this->asset('router', 'RTR-2'), 'mikrotik-hap-ac2');
        $this->link($this->port($switch, 'ether20'), $this->port($peer, 'ether2'))->assertRedirect();

        $this->apply($switch, ['template_key' => 'generic-switch-8'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ports')
            ->assertJsonPath('errors.ports.0', fn (string $message): bool => str_contains($message, 'ether20') && str_contains($message, 'Lepas sambungannya'));

        $this->assertCount(26, $this->portNames($switch));
        $this->assertSame('mikrotik-crs326-24g-2s-rm', $switch->fresh()->template_key);
    }

    public function test_a_model_of_another_asset_type_or_an_unknown_key_is_refused(): void
    {
        $router = $this->asset('router', 'RTR-1');

        $this->apply($router, ['template_key' => 'zte-c320-gtgh'])->assertStatus(422)->assertJsonValidationErrors('template_key');
        $this->apply($router, ['template_key' => 'no-such-model'])->assertStatus(422)->assertJsonValidationErrors('template_key');
        $this->apply($router, [])->assertStatus(422);
        $this->apply($this->asset('radio', 'RAD-1'), ['template_key' => 'mikrotik-hap-ac2'])->assertNotFound();

        $this->assertDatabaseCount('asset_ports', 0);
        $this->assertNull($router->fresh()->template_key);
    }

    public function test_a_custom_layout_is_capped_at_256_ports(): void
    {
        $rows = fn (int $ports): array => array_map(
            fn (int $row): array => [['group' => "g{$row}", 'kind' => 'rj45', 'count' => min(64, $ports - ($row - 1) * 64)]],
            range(1, (int) ceil($ports / 64)),
        );
        $within = $this->asset('switch', 'SW-256');
        $over = $this->asset('switch', 'SW-257');

        $this->apply($within, ['layout' => ['rows' => $rows(256)]])->assertRedirect();
        $this->apply($over, ['layout' => ['rows' => [...$rows(256), [['group' => 'extra', 'kind' => 'sfp', 'count' => 1]]]]])
            ->assertStatus(422)
            ->assertJsonPath('errors.layout.0', fn (string $message): bool => str_contains($message, '256'));

        $this->assertSame(256, AssetPort::query()->where('network_asset_id', $within->id)->count());
        $this->assertSame(0, AssetPort::query()->where('network_asset_id', $over->id)->count());
        $this->assertNull($within->fresh()->template_key);
        $this->assertSame(256, collect($within->fresh()->port_layout['rows'])->flatten(1)->sum('count'));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function brokenLayouts(): array
    {
        $port = ['group' => 'ether', 'kind' => 'rj45', 'count' => 4];

        return [
            'no rows' => [['rows' => []]],
            'nine rows' => [['rows' => array_fill(0, 9, [$port])]],
            'seventeen blocks in a row' => [['rows' => [array_fill(0, 17, $port)]]],
            'unknown kind' => [['rows' => [[[...$port, 'kind' => 'coax']]]]],
            'count zero' => [['rows' => [[[...$port, 'count' => 0]]]]],
            'count 65' => [['rows' => [[[...$port, 'count' => 65]]]]],
            'bad group key' => [['rows' => [[[...$port, 'group' => 'Ether 1']]]]],
            'group and fixture in one block' => [['rows' => [[[...$port, 'fixture' => 'power']]]]],
            'unknown fixture' => [['rows' => [[['fixture' => 'toaster']]]]],
            'block that is neither' => [['rows' => [[['title' => 'x']]]]],
            'same group and number twice' => [['rows' => [[$port, $port]]]],
            'one group with two kinds' => [['rows' => [[$port, ['group' => 'ether', 'kind' => 'sfp', 'count' => 1, 'from' => 5]]]]],
            'name without {port} in a group of many' => [['rows' => [[[...$port, 'name' => 'port']]]]],
            'slot placeholder without slot' => [['rows' => [[[...$port, 'name' => '{slot}/{port}']]]]],
            'pon role on a router' => [['rows' => [[['group' => 'pon1', 'kind' => 'sfp', 'count' => 2, 'role' => 'pon']]]]],
            'only fixtures' => [['rows' => [[['fixture' => 'power']]]]],
        ];
    }

    /** @param array<string, mixed> $layout */
    #[DataProvider('brokenLayouts')]
    public function test_a_broken_custom_layout_is_refused_and_nothing_is_written(array $layout): void
    {
        $router = $this->asset('router', 'RTR-1');

        $response = $this->apply($router, ['layout' => $layout])->assertStatus(422);

        $this->assertTrue(collect(array_keys($response->json('errors')))->every(fn (string $key): bool => str_starts_with($key, 'layout')));
        $this->assertDatabaseCount('asset_ports', 0);
        $this->assertNull($router->fresh()->port_layout);
    }

    public function test_a_custom_layout_is_refused_for_odn_enclosures_and_for_a_model_plus_a_layout(): void
    {
        $layout = ['rows' => [[['group' => 'ether', 'kind' => 'rj45', 'count' => 2]]]];

        $this->apply($this->asset('odp', 'ODP-1'), ['layout' => $layout])->assertStatus(422)->assertJsonValidationErrors('layout');
        $this->apply($this->asset('odc', 'ODC-1'), ['layout' => $layout])->assertStatus(422)->assertJsonValidationErrors('layout');
        $this->apply($this->asset('router', 'RTR-1'), ['template_key' => 'mikrotik-hap-ac2', 'layout' => $layout])->assertStatus(422);
    }

    public function test_an_olt_model_creates_the_missing_pon_ports_through_the_odn_register_and_only_uplinks_as_port_rows(): void
    {
        $olt = $this->asset('olt', 'OLT-1');
        $this->ponPorts($olt, 3);

        $this->apply($olt, ['template_key' => 'zte-c320-gtgh'])->assertRedirect();

        $this->assertSame(range(1, 32), OdnPort::query()->where('network_asset_id', $olt->id)->where('kind', 'pon')->orderBy('number')->pluck('number')->all());
        $this->assertSame(['10GE-1', '10GE-2', 'GE-1', 'GE-2', 'GE-RJ45-1', 'GE-RJ45-2'], $this->portNames($olt));
        $this->assertSame(2, $olt->fresh()->height_u);

        $this->apply($olt, ['template_key' => 'zte-c320-gtgh'])->assertRedirect();
        $this->assertSame(32, OdnPort::query()->where('network_asset_id', $olt->id)->count());
    }

    public function test_a_custom_olt_of_128_ports_is_created_in_chunks_of_64_and_an_olt_with_more_ports_than_the_model_is_refused(): void
    {
        $custom = $this->asset('olt', 'OLT-128');
        $rows = array_map(fn (int $slot): array => [['group' => "pon{$slot}", 'kind' => 'sfp', 'count' => 16, 'role' => 'pon', 'slot' => $slot, 'name' => 'PON {slot}/{port}']], range(1, 8));

        $this->apply($custom, ['layout' => ['rows' => $rows], 'height_u' => 4])->assertRedirect();

        $this->assertSame(128, OdnPort::query()->where('network_asset_id', $custom->id)->where('kind', 'pon')->count());
        $this->assertSame(0, AssetPort::query()->where('network_asset_id', $custom->id)->count());
        $this->assertSame(4, $custom->fresh()->height_u);

        $crowded = $this->asset('olt', 'OLT-40');
        $this->ponPorts($crowded, 40);
        $this->apply($crowded, ['template_key' => 'zte-c320-gtgh'])
            ->assertStatus(422)
            ->assertJsonPath('errors.template_key.0', 'OLT ini sudah punya 40 port PON terdaftar; model ini hanya 32.');
        $this->assertSame(40, OdnPort::query()->where('network_asset_id', $crowded->id)->count());
        $this->assertNull($crowded->fresh()->template_key);
    }

    public function test_a_generic_odp_model_creates_its_first_splitter_and_leaves_existing_ones(): void
    {
        $fresh = $this->asset('odp', 'ODP-NEW');
        $used = $this->asset('odp', 'ODP-USED');
        $this->splitter($used, 4);

        $this->apply($fresh, ['template_key' => 'generic-odp-8'])->assertRedirect();
        $this->apply($fresh, ['template_key' => 'generic-odp-8'])->assertRedirect();
        $this->apply($used, ['template_key' => 'generic-odp-16'])->assertRedirect();

        $this->assertSame([8], OdnSplitter::query()->where('network_asset_id', $fresh->id)->pluck('outputs')->all());
        $this->assertSame([4], OdnSplitter::query()->where('network_asset_id', $used->id)->pluck('outputs')->all());
        $this->assertSame(0, AssetPort::query()->count());
        $this->assertSame('generic-odp-16', $used->fresh()->template_key);
    }

    public function test_linking_writes_both_directions_and_is_audited(): void
    {
        [$a, $b] = $this->twoSwitches();
        $one = $this->port($a, '1');
        $two = $this->port($b, '2');

        $this->link($one, $two)->assertRedirect();

        $this->assertSame($two->id, $one->fresh()->linked_port_id);
        $this->assertSame($one->id, $two->fresh()->linked_port_id);
        $audit = Activity::query()->where('log_name', 'network_asset')->where('description', 'port_linked')->firstOrFail();
        $this->assertSame($this->admin->id, $audit->causer_id);
        $this->assertSame([$one->id, $two->id], $audit->properties->only(['port_id', 'target_port_id'])->values()->all());
    }

    public function test_only_free_ports_can_be_linked(): void
    {
        [$a, $b] = $this->twoSwitches();
        $c = $this->withModel($this->asset('switch', 'SW-C'), 'generic-switch-8');
        $this->link($this->port($a, '1'), $this->port($b, '2'))->assertRedirect();

        $this->link($this->port($c, '1'), $this->port($b, '2'))->assertStatus(422)->assertJsonValidationErrors('target_port_id');
        $this->link($this->port($a, '1'), $this->port($c, '3'))->assertStatus(422);

        $this->assertNull($this->port($c, '1')->linked_port_id);
        $this->assertNull($this->port($c, '3')->linked_port_id);
        $this->assertSame($this->port($b, '2')->id, $this->port($a, '1')->linked_port_id);
    }

    public function test_a_port_cannot_be_linked_to_itself_or_to_a_port_of_its_own_asset(): void
    {
        [$a] = $this->twoSwitches();

        $this->link($this->port($a, '1'), $this->port($a, '1'))->assertStatus(422)->assertJsonValidationErrors('target_port_id');
        $this->link($this->port($a, '1'), $this->port($a, '2'))->assertStatus(422)->assertJsonValidationErrors('target_port_id');

        $this->assertSame(0, AssetPort::query()->whereNotNull('linked_port_id')->count());
    }

    public function test_unlinking_clears_both_sides_and_a_free_port_is_422(): void
    {
        [$a, $b] = $this->twoSwitches();
        $this->link($this->port($a, '1'), $this->port($b, '2'))->assertRedirect();

        $this->unlink($this->port($b, '2'))->assertRedirect();

        $this->assertSame(0, AssetPort::query()->whereNotNull('linked_port_id')->count());
        $this->assertSame(1, Activity::query()->where('description', 'port_unlinked')->count());
        $this->unlink($this->port($a, '1'))->assertStatus(422)->assertJsonValidationErrors('port');
    }

    public function test_another_companys_ports_and_assets_are_never_reachable(): void
    {
        [$a] = $this->twoSwitches();
        $other = $this->otherCompany();
        $foreignAsset = $this->makeAsset($other, 'switch', null, ['code' => 'SW-FOREIGN']);
        $this->inCompany($other, fn () => AssetPanelService::apply($foreignAsset, 'generic-switch-8', null, null));
        $foreign = $this->inCompany($other, fn (): AssetPort => AssetPort::query()->where('network_asset_id', $foreignAsset->id)->orderBy('id')->firstOrFail());

        $this->link($this->port($a, '1'), $foreign)->assertStatus(422)->assertJsonValidationErrors('target_port_id');
        $this->postJson(route('admin.network-assets.ports.link.store', [$foreignAsset, $foreign]), ['target_port_id' => $this->port($a, '1')->public_id])->assertNotFound();
        $this->apply($foreignAsset, ['template_key' => 'generic-switch-24'])->assertNotFound();
        $this->putJson(route('admin.network-assets.rack-placement.update', $foreignAsset), ['rack_asset_id' => null])->assertNotFound();
        $this->patchJson(route('admin.network-assets.ports.update', [$a, $foreign]), ['label' => 'x', 'admin_down' => false])->assertNotFound();

        $service = fn () => AssetPanelService::link($this->port($a, '1'), $foreign->id);
        $this->assertSame(['target_port_id'], array_keys($this->validationErrors($service)));
        $this->assertNull($foreign->fresh()->linked_port_id);
        $this->assertNull($this->port($a, '1')->linked_port_id);
    }

    public function test_a_port_of_another_asset_in_the_url_is_404(): void
    {
        [$a, $b] = $this->twoSwitches();
        $stray = $this->port($b, '1');

        $this->patchJson(route('admin.network-assets.ports.update', [$a, $stray]), ['label' => 'x', 'admin_down' => false])->assertNotFound();
        $this->postJson(route('admin.network-assets.ports.link.store', [$a, $stray]), ['target_port_id' => $this->port($b, '2')->public_id])->assertNotFound();
        $this->deleteJson(route('admin.network-assets.ports.link.destroy', [$a, $stray]))->assertNotFound();
    }

    public function test_label_note_and_admin_down_are_updated_and_the_link_column_is_not_writable(): void
    {
        [$a, $b] = $this->twoSwitches();
        $port = $this->port($a, '3');

        $this->patchJson(route('admin.network-assets.ports.update', [$a, $port]), [
            'label' => 'uplink ke ODC',
            'note' => 'VLAN 100',
            'admin_down' => true,
            'linked_port_id' => $this->port($b, '3')->id,
            'network_asset_id' => $b->id,
        ])->assertRedirect();

        $port->refresh();
        $this->assertSame(['uplink ke ODC', 'VLAN 100', true, null, $a->id], [$port->label, $port->note, $port->admin_down, $port->linked_port_id, $port->network_asset_id]);
        $this->patchJson(route('admin.network-assets.ports.update', [$a, $port]), ['label' => str_repeat('x', 65), 'admin_down' => false])->assertStatus(422)->assertJsonValidationErrors('label');
        $this->patchJson(route('admin.network-assets.ports.update', [$a, $port]), ['label' => null])->assertStatus(422)->assertJsonValidationErrors('admin_down');
    }

    public function test_a_linked_port_row_cannot_be_deleted_and_an_asset_with_a_linked_port_cannot_be_removed(): void
    {
        [$a, $b] = $this->twoSwitches();
        $this->link($this->port($a, '1'), $this->port($b, '1'))->assertRedirect();

        try {
            DB::table('asset_ports')->where('id', $this->port($a, '1')->id)->delete();
            $this->fail('The foreign key on linked_port_id must restrict the delete.');
        } catch (QueryException $e) {
            $this->assertSame(1451, $e->errorInfo[1]);
        }

        $this->assertSame(
            ['lifecycle' => ['Port aset ini masih terhubung ke port lain. Lepas sambungannya dulu.']],
            $this->validationErrors(fn () => AssetPanelService::assertDeletable($a)),
        );
        $this->deleteJson(route('admin.network-assets.destroy', $a))->assertStatus(422)->assertJsonValidationErrors('lifecycle');
        $this->assertNotNull($a->fresh());
        $this->assertSame(8, AssetPort::query()->where('network_asset_id', $a->id)->count());
    }

    public function test_the_factory_builds_a_valid_port_row(): void
    {
        $asset = $this->asset('router', 'RTR-F');

        $port = AssetPort::factory()->create(['network_asset_id' => $asset->id, 'group_key' => 'ether', 'number' => 1, 'name' => 'ether1']);

        $this->assertSame($this->company->id, $port->company_id);
        $this->assertSame(['rj45', false, null], [$port->kind, $port->admin_down, $port->linked_port_id]);
        $this->assertSame($asset->id, $port->asset->id);
    }

    public function test_panel_writes_need_network_asset_update(): void
    {
        [$a, $b] = $this->twoSwitches();
        $rack = $this->asset('rack', 'RACK-1', ['height_u' => 42]);
        $this->link($this->port($a, '1'), $this->port($b, '1'))->assertRedirect();
        $this->actingAs($this->makeUser($this->company, 'staff'));

        $this->apply($a, ['template_key' => 'generic-switch-24'])->assertForbidden();
        $this->patchJson(route('admin.network-assets.ports.update', [$a, $this->port($a, '2')]), ['admin_down' => true])->assertForbidden();
        $this->link($this->port($a, '2'), $this->port($b, '2'))->assertForbidden();
        $this->unlink($this->port($a, '1'))->assertForbidden();
        $this->place($a, ['rack_asset_id' => $rack->public_id, 'rack_unit' => 1, 'height_u' => 1])->assertForbidden();

        $this->assertSame('generic-switch-8', $a->fresh()->template_key);
        $this->assertSame($this->port($b, '1')->id, $this->port($a, '1')->linked_port_id);
        $this->assertNull($a->fresh()->rack_asset_id);

        $this->actingAs($this->makeUser($this->company, 'manager'));
        $this->link($this->port($a, '2'), $this->port($b, '2'))->assertRedirect();
    }

    public function test_applying_a_model_to_an_olt_or_odp_also_needs_odn_manage_and_writes_no_odn_rows_without_it(): void
    {
        $olt = $this->asset('olt', 'OLT-GUARD');
        $odp = $this->asset('odp', 'ODP-GUARD');
        $router = $this->asset('router', 'RTR-GUARD');
        $this->companyRole($this->company, 'panel-only', ['network_asset.view', 'network_asset.update', 'app.web']);
        $this->actingAs($this->makeUser($this->company, 'panel-only'));

        $this->apply($olt, ['template_key' => 'zte-c320-gtgh'])->assertForbidden();
        $this->apply($odp, ['template_key' => 'generic-odp-8'])->assertForbidden();
        $this->apply($router, ['template_key' => 'mikrotik-hap-ac2'])->assertRedirect();

        $this->assertSame(0, OdnPort::query()->count());
        $this->assertSame(0, OdnSplitter::query()->count());
        $this->assertNull($olt->fresh()->template_key);
    }

    public function test_an_asset_is_placed_inside_the_rack_and_an_overlap_names_the_occupant(): void
    {
        $rack = $this->asset('rack', 'RACK-1');
        $olt = $this->asset('olt', 'OLT-U3');
        $router = $this->asset('router', 'RTR-U4');
        $this->apply($rack, ['height_u' => 10])->assertRedirect();
        $this->assertSame(10, $rack->fresh()->height_u);

        $this->place($olt, ['rack_asset_id' => $rack->public_id, 'rack_unit' => 3, 'height_u' => 2])->assertRedirect();
        $this->place($router, ['rack_asset_id' => $rack->public_id, 'rack_unit' => 4, 'height_u' => 1])
            ->assertStatus(422)
            ->assertJsonPath('errors.rack_unit.0', 'U3–U4 sudah terisi OLT-U3.');
        $this->place($router, ['rack_asset_id' => $rack->public_id, 'rack_unit' => 5, 'height_u' => 1])->assertRedirect();
        $this->place($olt, ['rack_asset_id' => $rack->public_id, 'rack_unit' => 4, 'height_u' => 2])->assertStatus(422);
        $this->place($olt, ['rack_asset_id' => $rack->public_id, 'rack_unit' => 2, 'height_u' => 2])->assertRedirect();

        $olt->refresh();
        $this->assertSame([$rack->id, 2, 2], [$olt->rack_asset_id, $olt->rack_unit, $olt->height_u]);
        $this->assertSame($rack->id, $olt->rack->id);
        $this->assertSame(['rack_placed', 'rack_placed', 'rack_placed'], Activity::query()->where('description', 'like', 'rack_%')->pluck('description')->all());
    }

    public function test_a_placement_past_the_rack_top_is_refused_and_a_rack_without_a_size_takes_nothing(): void
    {
        $rack = $this->asset('rack', 'RACK-1');
        $router = $this->asset('router', 'RTR-1');

        $this->place($router, ['rack_asset_id' => $rack->public_id, 'rack_unit' => 1, 'height_u' => 1])
            ->assertStatus(422)
            ->assertJsonPath('errors.rack_asset_id.0', 'Rak belum punya ukuran.');

        $this->apply($rack, ['height_u' => 10])->assertRedirect();
        $this->place($router, ['rack_asset_id' => $rack->public_id, 'rack_unit' => 10, 'height_u' => 2])
            ->assertStatus(422)
            ->assertJsonPath('errors.rack_unit.0', 'Posisi melewati tinggi rak (10U).');
        $this->place($router, ['rack_asset_id' => $rack->public_id, 'rack_unit' => 0, 'height_u' => 1])->assertStatus(422)->assertJsonValidationErrors('rack_unit');
        $this->place($router, ['rack_asset_id' => $rack->public_id, 'rack_unit' => 1])->assertStatus(422)->assertJsonValidationErrors('height_u');

        $this->assertNull($router->fresh()->rack_asset_id);
    }

    public function test_the_rack_target_must_be_a_rack_of_the_same_company_and_not_the_asset_itself(): void
    {
        $router = $this->asset('router', 'RTR-1');
        $other = $this->otherCompany();
        $foreignRack = $this->makeAsset($other, 'rack', null, ['code' => 'RACK-FOREIGN', 'height_u' => 42]);
        $notARack = $this->asset('switch', 'SW-1');

        $this->place($router, ['rack_asset_id' => $foreignRack->public_id, 'rack_unit' => 1, 'height_u' => 1])->assertStatus(422)->assertJsonValidationErrors('rack_asset_id');
        $this->place($router, ['rack_asset_id' => $notARack->public_id, 'rack_unit' => 1, 'height_u' => 1])->assertStatus(422)->assertJsonValidationErrors('rack_asset_id');
        $this->place($router, ['rack_asset_id' => $router->public_id, 'rack_unit' => 1, 'height_u' => 1])->assertStatus(422)->assertJsonValidationErrors('rack_asset_id');
        $this->place($router, ['rack_asset_id' => 5, 'rack_unit' => 1, 'height_u' => 1])->assertStatus(422)->assertJsonValidationErrors('rack_asset_id');

        $this->assertNull($router->fresh()->rack_asset_id);
    }

    public function test_unplacing_clears_rack_and_unit_and_a_rack_cannot_shrink_below_its_highest_device(): void
    {
        $rack = $this->asset('rack', 'RACK-1');
        $router = $this->asset('router', 'RTR-1');
        $this->apply($rack, ['height_u' => 10])->assertRedirect();
        $this->place($router, ['rack_asset_id' => $rack->public_id, 'rack_unit' => 6, 'height_u' => 2])->assertRedirect();

        $this->apply($rack, ['height_u' => 6])->assertStatus(422)->assertJsonValidationErrors('height_u');
        $this->apply($rack, ['height_u' => 7])->assertRedirect();
        $this->deleteJson(route('admin.network-assets.destroy', $rack))->assertStatus(422)->assertJsonValidationErrors('lifecycle');
        $this->assertSame(
            ['lifecycle' => ['Rak ini masih berisi perangkat. Kosongkan rak dulu.']],
            $this->validationErrors(fn () => AssetPanelService::assertDeletable($rack)),
        );

        $this->place($router, ['rack_asset_id' => null])->assertRedirect();

        $router->refresh();
        $this->assertSame([null, null], [$router->rack_asset_id, $router->rack_unit]);
        $this->assertSame(1, Activity::query()->where('description', 'rack_unplaced')->count());
        $this->deleteJson(route('admin.network-assets.destroy', $rack))->assertRedirect();
    }

    public function test_the_catalogue_holds_the_designed_rows_and_syncing_again_changes_nothing_and_never_deletes(): void
    {
        PortTemplate::query()->create(['key' => 'custom-by-hand', 'vendor' => 'Umum', 'model' => 'Ditambah operator', 'asset_type' => 'switch', 'layout' => ['rows' => [[['group' => 'ether', 'kind' => 'rj45', 'count' => 2]]]]]);
        $before = $this->catalogue();

        $this->assertSame(14, PortTemplateCatalogue::sync());

        $this->assertSame($before, $this->catalogue());
        $this->assertSame(15, PortTemplate::query()->count());
        $this->assertCount(14, PortTemplateCatalogue::keys());
        $this->assertContains('mikrotik-ccr2004-16g-2s', PortTemplateCatalogue::keys());
    }

    public function test_a_mounted_device_keeps_the_height_it_was_placed_with_when_its_model_changes(): void
    {
        $rack = $this->asset('rack', 'RACK-1');
        $mounted = $this->asset('router', 'RTR-MOUNTED');
        $loose = $this->asset('router', 'RTR-LOOSE');
        $this->apply($rack, ['height_u' => 10])->assertRedirect();
        $this->place($mounted, ['rack_asset_id' => $rack->public_id, 'rack_unit' => 4, 'height_u' => 2])->assertRedirect();

        $this->apply($mounted, ['template_key' => 'mikrotik-rb4011igs-rm'])->assertRedirect();
        $this->apply($loose, ['template_key' => 'mikrotik-rb4011igs-rm'])->assertRedirect();

        $this->assertSame([2, 4], [$mounted->fresh()->height_u, $mounted->fresh()->rack_unit]);
        $this->assertSame(1, $loose->fresh()->height_u);
    }

    public function test_a_custom_layout_is_stored_in_reading_order_without_keys_it_does_not_know(): void
    {
        $router = $this->asset('router', 'RTR-1');
        $layout = ['rows' => [
            [['fixture' => 'console', 'title' => 'Console'], ['group' => 'ether', 'kind' => 'rj45', 'count' => 2, 'stack' => 2, 'speed' => '1G', 'junk' => 'x'], ['fixture' => 'power', 'count' => 9]],
            [['group' => 'sfp', 'kind' => 'sfp', 'count' => 1]],
        ]];

        $this->apply($router, ['layout' => $layout, 'height_u' => 1])->assertRedirect();

        $router->refresh();
        $this->assertEquals(['rows' => [
            [['fixture' => 'console', 'title' => 'Console'], ['group' => 'ether', 'kind' => 'rj45', 'count' => 2, 'stack' => 2, 'speed' => '1G'], ['fixture' => 'power']],
            [['group' => 'sfp', 'kind' => 'sfp', 'count' => 1]],
        ]], $router->port_layout);
        $this->assertSame(['ether1', 'ether2', 'sfp1'], $this->portNames($router));
        $this->assertSame([null, 1], [$router->template_key, $router->height_u]);
    }

    public function test_the_layout_helpers_read_the_catalogue_as_the_design_tables_say(): void
    {
        $layout = fn (string $key): array => PortTemplate::query()->where('key', $key)->firstOrFail()->layout;
        $pon = fn (string $key): array => array_map(fn (array $slot): string => "{$slot['group']}#{$slot['number']} {$slot['name']}", PanelLayout::ponSlots($layout($key)));

        $zte = $pon('zte-c320-gtgh');
        $this->assertCount(32, $zte);
        $this->assertSame(['pon1#1 gpon-olt_1/1/1', 'pon1#16 gpon-olt_1/1/16', 'pon2#1 gpon-olt_1/2/1', 'pon2#16 gpon-olt_1/2/16'], [$zte[1], $zte[16], $zte[17], $zte[32]]);

        $huawei = $pon('huawei-ma5608t-gpfd');
        $this->assertSame(['pon0#1 0/0/0', 'pon0#16 0/0/15', 'pon1#1 0/1/0', 'pon1#16 0/1/15'], [$huawei[1], $huawei[16], $huawei[17], $huawei[32]]);

        $this->assertSame(38, PanelLayout::portCount($layout('zte-c320-gtgh')));
        $this->assertCount(6, PanelLayout::assetPortRows($layout('zte-c320-gtgh')));
        $this->assertSame(['sfp', 'sfp-sfpplus1'], [PanelLayout::assetPortRows($layout('mikrotik-rb4011igs-rm'))[10]['group_key'], PanelLayout::assetPortRows($layout('mikrotik-rb4011igs-rm'))[10]['name']]);
        $this->assertSame([8], array_column(PanelLayout::splitterBlocks($layout('generic-odp-8')), 'count'));
        $this->assertSame('PON 3', PanelLayout::portName(['kind' => 'sfp', 'role' => 'pon'], 3));
    }

    public function test_the_panel_columns_cannot_be_set_through_the_asset_forms(): void
    {
        $rack = $this->asset('rack', 'RACK-1', ['height_u' => 42]);
        $router = $this->asset('router', 'RTR-1');

        $this->putJson(route('admin.network-assets.update', $router), [
            'name' => 'Router baru',
            'asset_type' => 'router',
            'template_key' => 'mikrotik-hap-ac2',
            'port_layout' => ['rows' => []],
            'rack_asset_id' => $rack->id,
            'rack_unit' => 1,
            'height_u' => 1,
        ])->assertRedirect();

        $router->refresh();
        $this->assertSame('Router baru', $router->name);
        $this->assertSame([null, null, null, null, null], [$router->template_key, $router->port_layout, $router->rack_asset_id, $router->rack_unit, $router->height_u]);
    }

    /**
     * @param  callable(): mixed  $call
     * @return array<string, list<string>>
     */
    private function validationErrors(callable $call): array
    {
        try {
            $call();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        $this->fail('A ValidationException was expected.');
    }
}
