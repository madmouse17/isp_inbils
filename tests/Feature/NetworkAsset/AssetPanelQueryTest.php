<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\ServiceSubscription;
use App\Services\Core\IntegrationConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\NetworkAsset\Http\Resources\AssetPanelResource;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Queries\AssetPanelQuery;
use Modules\NetworkAsset\Services\AssetPanelService;
use Modules\NetworkAsset\Services\OdnService;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsOdnTopology;
use Tests\Traits\BuildsPanelFixtures;
use Tests\Traits\InteractsWithRadius;

/**
 * What AssetPanelQuery and AssetPanelResource send, beyond AssetPanelReadTest (spec .scratch/hardware-panel-ui, design
 * §4.2 and §4.3): the statement budget for every device type, the 30 KB payload in its worst case, both ends of a patch,
 * the ONT's choice of subscription, and the company boundary of every read.
 */
class AssetPanelQueryTest extends TestCase
{
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

    /** The wire payload as the browser gets it. */
    private function panel(NetworkAsset $asset, bool $withOdn = true): array
    {
        return json_decode(json_encode((new AssetPanelResource(AssetPanelQuery::for($asset, $withOdn)))->resolve()), true);
    }

    /** @return array<string, mixed> */
    private function port(array $panel, string $group, int $number): array
    {
        $port = collect(collect($panel['groups'])->firstWhere('key', $group)['ports'] ?? [])->firstWhere('number', $number);
        $this->assertNotNull($port, "No port {$group}:{$number} in the panel");

        return $port;
    }

    private function subscriber(string $name, ?OdnPort $port = null, string $status = 'active'): ServiceSubscription
    {
        $subscription = $this->makeSubscription($this->company, $status);
        DB::table('customers')->where('id', $subscription->customer_id)->update(['name' => $name]);
        NetworkAccount::factory()->create([
            'company_id' => $this->company->id,
            'subscription_id' => $subscription->id,
            'username' => Str::slug($name).'@'.$this->realmOf($this->company),
        ]);

        if ($port !== null) {
            OdnService::assignDrop($port, $subscription);
        }

        return $subscription;
    }

    private function goOnline(string $username): void
    {
        $this->seedRadiusSession(['username' => $username]);
    }

    /** @return int statements on the application database plus radacct queries that the work ran */
    private function statements(callable $work): int
    {
        // Reading the company's RADIUS row is one fixed statement per request (IntegrationConfig), not part of what a panel costs.
        app(IntegrationConfig::class)->current('radius');
        $radius = $this->radiusConnection();
        DB::flushQueryLog();
        $radius->flushQueryLog();
        DB::enableQueryLog();
        $radius->enableQueryLog();

        try {
            $work();

            return count(DB::getQueryLog()) + count($radius->getQueryLog());
        } finally {
            DB::disableQueryLog();
            $radius->disableQueryLog();
        }
    }

    public function test_every_device_type_stays_within_six_statements(): void
    {
        $olt = $this->panelOlt(32, 16, true);
        $this->goOnline('panel0@'.$this->realmOf($this->company));
        $odpOfOlt = NetworkAsset::query()->where('code', 'like', 'PNL-ODP-%')->firstOrFail();

        $oltOfTopology = $this->net['olt'];
        AssetPanelService::apply($oltOfTopology, 'zte-c320-gtgh', null, null);
        [, $odcOutputs] = $this->splitter($this->net['odc'], 8, OdnPort::query()->where('network_asset_id', $oltOfTopology->id)->where('number', 1)->firstOrFail());
        [, $odpOutputs] = $this->splitter($this->net['odp'], 8, $odcOutputs[0]);
        $budi = $this->subscriber('Budi', $odpOutputs[0]);
        $this->goOnline('budi@'.$this->realmOf($this->company));
        $ont = $this->makeAsset($this->company, 'onu_ont', $this->net['site'], ['code' => 'ONT-1']);
        AssetPanelService::apply($ont, 'generic-ont-1pon-4lan-2pots', null, null);
        DB::table('service_subscriptions')->where('id', $budi->id)->update(['ont_asset_id' => $ont->id]);
        $rack = $this->panelRack(42);
        AssetPanelService::place($this->panelRouter(), $rack->id, 3, 1);

        $devices = [
            'olt with 32 PON ports, linked uplinks and subscribers' => $olt,
            'olt of the topology under two splitter levels' => $oltOfTopology,
            'odc' => $this->net['odc'],
            'odp with 8 drops' => $this->net['odp'],
            'odp of the big olt' => $odpOfOlt,
            'ont' => $ont,
            'router' => $this->panelRouter(),
            'switch' => $this->panelSwitch(),
            'router without model (picker)' => $this->makeAsset($this->company, 'router', null, ['code' => 'RTR-BARE']),
            'rack with a device' => $rack,
        ];

        foreach ($devices as $name => $device) {
            $statements = $this->statements(fn () => $this->panel($device->refresh()));

            $this->assertLessThanOrEqual(6, $statements, "{$name} ran {$statements} statements");
        }
    }

    public function test_the_worst_case_128_port_olt_with_every_port_fed_dropped_online_and_linked_stays_under_30_kb(): void
    {
        $olt = $this->panelOlt(128, 128, true);

        foreach (range(0, 127) as $index) {
            $this->goOnline("panel{$index}@".$this->realmOf($this->company));
        }

        $json = '';
        $statements = $this->statements(function () use ($olt, &$json): void {
            $json = json_encode((new AssetPanelResource(AssetPanelQuery::for($olt, true)))->resolve());
        });

        $panel = json_decode($json, true);
        $pon = collect($panel['groups'])->where('role', 'pon')->flatMap(fn (array $group): array => $group['ports']);
        $this->assertCount(128, $pon);
        $this->assertCount(128, $pon->filter(fn (array $port): bool => ($port['state']['occupied'] ?? false) && ($port['state']['online'] ?? false) && $port['behind'] === ['total' => 1, 'online' => 1] && isset($port['link'])));
        $this->assertSame(['occupied' => true], $this->port($panel, 'uplink', 1)['state']);
        $this->assertLessThanOrEqual(6, $statements);
        $this->assertLessThanOrEqual(30 * 1024, strlen($json), 'worst case payload of '.strlen($json).' bytes');
    }

    public function test_both_ends_of_a_patch_show_the_other_end_and_a_port_taken_down_or_labelled_says_so(): void
    {
        $olt = $this->panelDevice('olt', 'zte-c320-gtgh', null);
        $router = $this->panelRouter('mikrotik-rb4011igs-rm');
        AssetPanelService::link($this->panelPort($olt, 'uplink', 1), $this->panelPort($router, 'sfp', 1)->id);
        AssetPanelService::updatePort($this->panelPort($olt, 'uplink', 1), ['label' => 'ke RTR']);
        AssetPanelService::updatePort($this->panelPort($router, 'sfp', 1), ['admin_down' => true]);
        AssetPanelService::updatePort($this->panelPort($router, 'ether', 4), ['admin_down' => true]);

        $oltPanel = $this->panel($olt);
        $routerPanel = $this->panel($router);

        $uplink = $this->port($oltPanel, 'uplink', 1);
        $this->assertSame(['10GE-1', 'ke RTR', ['occupied' => true], ['asset' => $router->code, 'port' => 'sfp-sfpplus1']], [$uplink['name'], $uplink['label'], $uplink['state'], $uplink['link']]);
        $sfp = $this->port($routerPanel, 'sfp', 1);
        $this->assertSame(['sfp-sfpplus1', ['occupied' => true, 'admin_down' => true], ['asset' => $olt->code, 'port' => '10GE-1']], [$sfp['name'], $sfp['state'], $sfp['link']]);
        $this->assertSame(['admin_down' => true], $this->port($routerPanel, 'ether', 4)['state'], 'down but not patched');
        $this->assertSame(['id', 'number', 'name', 'state'], array_keys($this->port($routerPanel, 'ether', 5)), 'a free port keeps no label and no link');
    }

    public function test_an_ont_takes_its_drop_from_its_own_subscription_before_the_one_that_points_at_it(): void
    {
        [, $outputs] = $this->splitter($this->net['odp'], 8);
        $ont = $this->makeAsset($this->company, 'onu_ont', $this->net['site'], ['code' => 'ONT-1']);
        AssetPanelService::apply($ont, 'generic-ont-1pon-4lan-2pots', null, null);
        $pointing = $this->subscriber('Pointing', $outputs[0]);
        $own = $this->subscriber('Own', $outputs[1]);
        DB::table('service_subscriptions')->where('id', $pointing->id)->update(['ont_asset_id' => $ont->id]);
        DB::table('network_assets')->where('id', $ont->id)->update(['subscription_id' => $own->id]);
        DB::table('service_subscriptions')->where('id', $own->id)->update(['blocked_at' => now()]);

        $pon = $this->port($this->panel($ont->refresh()), 'pon', 1);

        $this->assertSame(['subscription' => $own->code, 'customer' => 'Own'], $pon['drop']);
        $this->assertSame(['occupied' => true, 'isolir' => true], $pon['state'], 'a blocked subscription lights the ONT port as isolir');

        DB::table('network_assets')->where('id', $ont->id)->update(['subscription_id' => null]);
        $pon = $this->port($this->panel($ont->refresh()), 'pon', 1);

        $this->assertSame(['subscription' => $pointing->code, 'customer' => 'Pointing'], $pon['drop']);
        $this->assertSame(['occupied' => true], $pon['state']);
    }

    public function test_another_companys_rows_never_appear(): void
    {
        $stranger = $this->otherCompany();
        $foreignOlt = $this->panelOlt(16, 4, true, $stranger);
        $foreignRack = $this->panelRack(42, $stranger);
        $foreignRouter = $this->panelRouter('mikrotik-hap-ac2', $stranger);
        $foreignOdp = $this->inCompany($stranger, fn (): NetworkAsset => NetworkAsset::query()->where('code', 'like', 'PNL-ODP-%')->firstOrFail());
        $foreignSubscription = $this->inCompany($stranger, fn (): ServiceSubscription => ServiceSubscription::query()->firstOrFail());
        $foreignPon = $this->inCompany($stranger, fn (): OdnPort => OdnPort::query()->where('network_asset_id', $foreignOlt->id)->where('number', 1)->firstOrFail());
        $foreignUplink = $this->panelPort($foreignOlt, 'uplink', 1);
        $this->goOnline('panel0@'.$this->realmOf($stranger));

        $olt = $this->panelOlt(16, 2, true);
        $rack = $this->panelRack(42);
        $router = $this->panelRouter('mikrotik-hap-ac2');
        AssetPanelService::place($router, $rack->id, 2, 1);
        DB::table('network_assets')->where('id', $foreignRouter->id)->update(['rack_asset_id' => $rack->id, 'rack_unit' => 5, 'height_u' => 1]);
        $own = NetworkAsset::query()->where('code', 'like', 'PNL-ODP-%')->firstOrFail();

        $oltJson = json_encode($this->panel($olt));
        $odpJson = json_encode($this->panel($own));
        $rackPanel = $this->panel($rack);

        foreach ([$foreignOlt->code, $foreignOdp->code, $foreignRouter->code, $foreignRack->code] as $code) {
            $this->assertStringNotContainsString($code, $oltJson.$odpJson.json_encode($rackPanel));
        }

        $this->assertSame(['total' => 1, 'online' => 0], $this->port($this->panel($olt), 'pon1', 1)['behind'], 'only the own subscriber is counted, offline');
        $this->assertSame([$router->code], array_column($rackPanel['rack']['items'], 'code'), 'a device of another company mounted in the rack by bad data is not listed');
        $this->assertNull(AssetPanelQuery::portDetail($olt, $foreignPon->public_id, true));
        $this->assertNull(AssetPanelQuery::portDetail($olt, $foreignUplink->public_id, true));
        $this->assertNull(AssetPanelQuery::portDetail($router, $foreignUplink->public_id, true));
        $this->assertNull(AssetPanelQuery::tracePath($foreignSubscription->public_id));
        $this->assertSame([], AssetPanelQuery::freePortOptions($router, $foreignRouter->public_id));
    }

    public function test_forgetting_the_online_set_of_one_company_leaves_the_cache_of_another(): void
    {
        [, $outputs] = $this->splitter($this->net['odp'], 8);
        $this->subscriber('Budi', $outputs[0]);
        $this->goOnline('budi@'.$this->realmOf($this->company));
        $online = fn (): bool => $this->port($this->panel($this->net['odp']), 's1', 1)['state']['online'] ?? false;

        $this->assertTrue($online());
        $this->radiusConnection()->table('radacct')->update(['acctstoptime' => now()->format('Y-m-d H:i:s')]);

        AssetPanelQuery::forgetOnline($this->otherCompany()->id);
        $this->assertTrue($online(), 'the cached set of this company is still served');

        AssetPanelQuery::forgetOnline($this->company->id);
        $this->assertFalse($online(), 'its own forget makes RADIUS be asked again');
    }
}
