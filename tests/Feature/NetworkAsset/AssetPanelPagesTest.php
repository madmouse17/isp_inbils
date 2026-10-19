<?php

namespace Tests\Feature\NetworkAsset;

use App\Http\Middleware\RedirectAppOnlyRoles;
use App\Models\Core\ServiceSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\NetworkAsset\Models\AssetPort;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Services\AssetPanelService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\AsksInertiaForProps;
use Tests\Traits\BuildsIpamOdnFixtures;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsOdnTopology;
use Tests\Traits\BuildsPanelFixtures;
use Tests\Traits\InteractsWithRadius;

/**
 * The panel on the pages (spec .scratch/hardware-panel-ui, design §4.1 and §4.6): which prop each persona gets, the port
 * detail and port option reloads over HTTP, and the company boundary of every route. AssetPanelReadTest already pins the
 * deferred panel, the types without a panel, the ODN and NAS pages, the drop and link and rack option searches and the
 * trace page with and without odn.view.
 */
class AssetPanelPagesTest extends TestCase
{
    use AsksInertiaForProps;
    use BuildsIpamOdnFixtures;
    use BuildsNetworkFixtures;
    use BuildsOdnTopology;
    use BuildsPanelFixtures;
    use InteractsWithRadius;
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $net;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->bootIpamOdnWorld(true);
        $this->fakeRadius();
        $this->net = $this->topology($this->company);
    }

    /** @return array<string, mixed> the props of a partial reload that asks for one prop */
    private function props(string $url, string $component, string $prop): array
    {
        return $this->get($url, $this->partialReload($component, $prop))->assertOk()->json('props');
    }

    /** @return array<string, array{0: string, 1: bool, 2: bool, 3: bool}> persona, may edit, sees ODN, may assign drops */
    public static function personas(): array
    {
        return [
            'admin' => ['admin', true, true, true],
            'manager' => ['manager', true, true, true],
            'staff' => ['staff', false, true, true],
            'technician' => ['technician', false, true, false],
            'reader without ODN access' => ['viewer', false, false, false],
        ];
    }

    #[DataProvider('personas')]
    public function test_each_persona_gets_the_panel_props_its_permissions_allow(string $persona, bool $edits, bool $seesOdn, bool $assigns): void
    {
        $olt = $this->panelOlt(16, 1);
        $router = $this->panelRouter('mikrotik-hap-ac2');
        $rack = $this->panelRack(10);
        $odp = NetworkAsset::query()->where('code', 'like', 'PNL-ODP-%')->firstOrFail();

        if ($persona === 'technician') {
            // The web policies still say what a technician may read; the app-only redirect has its own test below.
            $this->withoutMiddleware(RedirectAppOnlyRoles::class);
        }

        $this->actingAs($persona === 'viewer' ? $this->userWithPermissions($this->company, 'network_asset.view') : $this->makeUser($this->company, $persona));
        $show = fn (NetworkAsset $asset): string => route('admin.network-assets.show', $asset);

        $offered = fn (NetworkAsset $asset, string $prop): bool => is_array($this->props($show($asset), 'Admin/NetworkAssets/Show', $prop)[$prop] ?? null);

        $this->assertSame($edits, $offered($router, 'linkAssetOptions'), 'link options');
        $this->assertSame($edits, $offered($rack, 'rackAssetOptions'), 'rack options');
        $this->assertSame($assigns, $offered($odp, 'dropSubscriptionOptions'), 'drop options');

        $this->get($show($olt))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/NetworkAssets/Show')->loadDeferredProps(
            fn (Assert $reload) => $reload->where('panel.asset.code', $olt->code)->when(
                $seesOdn,
                fn (Assert $odn) => $odn->missing('panel.odn_hidden')->has('panel.groups', 2),
                fn (Assert $hidden) => $hidden->where('panel.odn_hidden', true)->has('panel.groups', 1),
            ),
        ));
    }

    /** @return array<string, array{0: string}> */
    public static function appOnlyPersonas(): array
    {
        return ['technician' => ['technician'], 'customer' => ['customer']];
    }

    #[DataProvider('appOnlyPersonas')]
    public function test_an_app_only_persona_is_sent_to_the_app_page_from_the_panel_routes(string $persona): void
    {
        $switch = $this->panelSwitch();
        $peer = $this->panelSwitch();
        $rack = $this->panelRack(10);
        $port = $this->panelPort($switch, 'ether', 1);
        $before = AssetPort::query()->orderBy('id')->get()->toArray();
        $this->actingAs($this->makeUser($this->company, $persona));

        $this->get(route('admin.network-assets.show', $switch))->assertRedirect(route('app-only'));

        foreach (['panel', 'port', 'link', 'unlink', 'rack'] as $route) {
            $this->request($route, $switch, $port, $this->panelPort($peer, 'ether', 1), $rack)()->assertRedirect(route('app-only'));
        }

        $this->assertSame($before, AssetPort::query()->orderBy('id')->get()->toArray());
        $this->assertNull($switch->fresh()->rack_asset_id);
    }

    public function test_port_detail_is_sent_only_on_request_and_only_for_ports_of_that_asset(): void
    {
        $router = $this->panelRouter('mikrotik-hap-ac2');
        $olt = $this->panelOlt(16, 0);
        $own = $this->panelPort($router, 'ether', 1);
        $stray = $this->panelPort($olt, 'uplink', 1);
        $show = route('admin.network-assets.show', $router);
        $detail = function (mixed $port) use ($router): mixed {
            $props = $this->props(route('admin.network-assets.show', [$router, 'port' => $port]), 'Admin/NetworkAssets/Show', 'portDetail');

            $this->assertArrayHasKey('portDetail', $props);

            return $props['portDetail'];
        };

        $this->get($show)->assertOk()->assertInertia(fn (Assert $page) => $page->missing('portDetail'));

        $found = $detail($own->public_id);
        $this->assertSame([$own->public_id, 'asset', 'ether1'], [$found['id'], $found['source'], $found['name']]);
        $this->assertNull($detail($stray->public_id), 'a port of another asset');
        $this->assertNull($detail((string) Str::ulid()), 'an unknown port');
        $this->assertNull($detail(['x']), 'a list is not an id');
        $this->assertNull($detail(''));
    }

    public function test_an_odn_port_detail_needs_odn_view(): void
    {
        $odp = $this->makeAsset($this->company, 'odp', $this->net['site'], ['code' => 'ODP-DETAIL']);
        [, $outputs] = $this->splitter($odp, 8);
        $url = route('admin.odn.assets.show', [$odp, 'port' => $outputs[2]->public_id]);

        $this->assertSame($outputs[2]->public_id, $this->props($url, 'Admin/Odn/Asset', 'portDetail')['portDetail']['id']);

        $this->actingAs($this->userWithPermissions($this->company, 'network_asset.view', 'odn.view'));
        $this->assertSame($outputs[2]->public_id, $this->props($url, 'Admin/Odn/Asset', 'portDetail')['portDetail']['id']);

        $this->actingAs($this->userWithPermissions($this->company, 'network_asset.view'));
        $this->get(route('admin.network-assets.show', [$odp, 'port' => $outputs[2]->public_id]), $this->partialReload('Admin/NetworkAssets/Show', 'portDetail'))
            ->assertOk()
            ->assertJsonPath('props.portDetail', null);
    }

    public function test_link_port_options_exclude_ports_already_patched_and_every_asset_that_is_not_another_modelled_device(): void
    {
        $router = $this->panelRouter('mikrotik-hap-ac2');
        $switch = $this->panelSwitch();
        $stranger = $this->otherCompany();
        $foreign = $this->panelSwitch(company: $stranger);
        AssetPanelService::link($this->panelPort($router, 'ether', 1), $this->panelPort($switch, 'ether', 1)->id);
        AssetPanelService::link($this->panelPort($router, 'ether', 2), $this->panelPort($switch, 'ether', 3)->id);
        $options = fn (mixed $target): array => $this->props(route('admin.network-assets.show', [$router, 'link_asset' => $target]), 'Admin/NetworkAssets/Show', 'linkPortOptions')['linkPortOptions'];

        $this->assertSame(['2', '4', '5', '6', '7', '8'], array_column($options($switch->public_id), 'label'));
        $this->assertSame([$this->panelPort($switch, 'ether', 2)->public_id], [$options($switch->public_id)[0]['value']]);
        $this->assertSame([], $options($router->public_id), 'the asset itself');
        $this->assertSame([], $options($foreign->public_id), 'another company');
        $this->assertSame([], $options(null));
        $this->assertSame([], $options(['x']));
    }

    public function test_link_asset_options_leave_out_the_asset_itself(): void
    {
        $router = $this->panelRouter('mikrotik-hap-ac2');
        $switch = $this->panelSwitch();

        $search = fn (string $term): array => array_column($this->props(route('admin.network-assets.show', [$router, 'linkAsset_search' => $term]), 'Admin/NetworkAssets/Show', 'linkAssetOptions')['linkAssetOptions'], 'value');

        $this->assertSame([$switch->public_id], $search('SWI-'));
        $this->assertSame([], $search($router->code), 'the asset itself is not offered');
    }

    /** @return array<string, array{0: string}> */
    public static function writeRoutes(): array
    {
        return ['apply a model' => ['panel'], 'label a port' => ['port'], 'link a port' => ['link'], 'unlink a port' => ['unlink'], 'mount in a rack' => ['rack']];
    }

    /** @return callable(): TestResponse the request of a write route */
    private function request(string $route, NetworkAsset $asset, AssetPort $port, AssetPort $target, NetworkAsset $rack): callable
    {
        return match ($route) {
            'panel' => fn () => $this->putJson(route('admin.network-assets.panel.update', $asset), ['template_key' => 'generic-switch-24']),
            'port' => fn () => $this->patchJson(route('admin.network-assets.ports.update', [$asset, $port]), ['label' => 'x', 'admin_down' => true]),
            'link' => fn () => $this->postJson(route('admin.network-assets.ports.link.store', [$asset, $port]), ['target_port_id' => $target->public_id]),
            'unlink' => fn () => $this->deleteJson(route('admin.network-assets.ports.link.destroy', [$asset, $port])),
            'rack' => fn () => $this->putJson(route('admin.network-assets.rack-placement.update', $asset), ['rack_asset_id' => $rack->public_id, 'rack_unit' => 1, 'height_u' => 1]),
        };
    }

    #[DataProvider('writeRoutes')]
    public function test_a_guest_reaches_no_write_route(string $route): void
    {
        $switch = $this->panelSwitch();
        $peer = $this->panelSwitch();
        $rack = $this->panelRack(10);
        $port = $this->panelPort($switch, 'ether', 1);
        AssetPanelService::link($port, $this->panelPort($peer, 'ether', 1)->id);
        $before = [AssetPort::query()->orderBy('id')->get()->toArray(), NetworkAsset::query()->orderBy('id')->get()->toArray()];
        $this->app['auth']->forgetGuards();

        $this->request($route, $switch, $port, $this->panelPort($peer, 'ether', 2), $rack)()->assertUnauthorized();

        $this->actingAs($this->admin);
        $this->assertSame($before, [AssetPort::query()->orderBy('id')->get()->toArray(), NetworkAsset::query()->orderBy('id')->get()->toArray()]);
    }

    #[DataProvider('writeRoutes')]
    public function test_a_write_route_answers_404_for_an_asset_or_a_port_of_another_company_and_changes_nothing(string $route): void
    {
        $stranger = $this->otherCompany();
        $foreign = $this->panelSwitch(company: $stranger);
        $foreignPeer = $this->panelSwitch(company: $stranger);
        $foreignRack = $this->panelRack(10, $stranger);
        $foreignPort = $this->panelPort($foreign, 'ether', 1);
        $this->inCompany($stranger, fn () => AssetPanelService::link($foreignPort, $this->panelPort($foreignPeer, 'ether', 1)->id));
        $own = $this->panelSwitch();
        $ownRack = $this->panelRack(10);
        $ownPort = $this->panelPort($own, 'ether', 1);
        $snapshot = fn (): array => [
            AssetPort::withoutCompany()->orderBy('id')->get()->toArray(),
            NetworkAsset::withoutCompany()->orderBy('id')->get()->toArray(),
        ];
        $before = $snapshot();

        $this->request($route, $foreign, $foreignPort, $this->panelPort($foreignPeer, 'ether', 2), $foreignRack)()->assertNotFound();

        if (in_array($route, ['port', 'link', 'unlink'], true)) {
            $this->request($route, $own, $foreignPort, $ownPort, $ownRack)()->assertNotFound();
        }

        $this->assertSame($before, $snapshot());
    }

    public function test_a_show_page_and_the_trace_of_another_company_are_closed(): void
    {
        $stranger = $this->otherCompany();
        $foreign = $this->panelOlt(16, 2, true, $stranger);
        $foreignOdp = $this->inCompany($stranger, fn (): NetworkAsset => NetworkAsset::query()->where('code', 'like', 'PNL-ODP-%')->firstOrFail());
        $foreignSubscription = $this->inCompany($stranger, fn (): ServiceSubscription => ServiceSubscription::query()->firstOrFail());
        $foreignPon = $this->inCompany($stranger, fn (): OdnPort => OdnPort::query()->where('network_asset_id', $foreign->id)->where('number', 1)->firstOrFail());

        $this->get(route('admin.network-assets.show', $foreign))->assertNotFound();
        $this->get(route('admin.odn.assets.show', $foreignOdp))->assertNotFound();
        $this->get(route('admin.network-assets.show', [$this->panelRouter('mikrotik-hap-ac2'), 'port' => $foreignPon->public_id]), $this->partialReload('Admin/NetworkAssets/Show', 'portDetail'))
            ->assertOk()->assertJsonPath('props.portDetail', null);
        $this->get(route('admin.network-assets.trace', ['path_subscription' => $foreignSubscription->public_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('tracePath', null));
    }
}
