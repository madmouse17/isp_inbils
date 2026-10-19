<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\IntegrationConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\NetworkAsset\Http\Resources\AssetPanelResource;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Models\PortTemplate;
use Modules\NetworkAsset\Queries\AssetPanelQuery;
use Modules\NetworkAsset\Services\AssetPanelService;
use Modules\NetworkAsset\Services\NetworkAccountProvisioner;
use Modules\NetworkAsset\Services\OdnService;
use Modules\NetworkAsset\Support\AssetPanelProps;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Tests\TestCase;
use Tests\Traits\AsksInertiaForProps;
use Tests\Traits\BuildsIpamOdnFixtures;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsOdnTopology;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\InteractsWithNasCoa;
use Tests\Traits\InteractsWithRadius;

/**
 * The read side of the hardware panel (spec .scratch/hardware-panel-ui, design §4.2 to §4.5): what AssetPanelQuery and
 * AssetPanelResource send for each device type, the online and isolir state, the statement budget, the 30 KB payload,
 * the online-set cache and the deferred props on the pages. The broad suites live in the QA slice.
 */
class AssetPanelReadTest extends TestCase
{
    use AsksInertiaForProps;
    use BuildsIpamOdnFixtures;
    use BuildsNetworkFixtures;
    use BuildsOdnTopology;
    use BuildsSubscriptionBilling;
    use InteractsWithNasCoa;
    use InteractsWithRadius;
    use RefreshDatabase;

    /** @var array<string, mixed> */
    protected array $net;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->bootIpamOdnWorld(true);
        $this->fakeRadius();
        $this->net = $this->topology($this->company);
    }

    private function realm(): string
    {
        return $this->realmOf($this->company);
    }

    /** The wire payload as the browser gets it: through JSON, so an empty state is [] and absent keys are absent. */
    protected function panel(NetworkAsset $asset, bool $withOdn = true): array
    {
        return json_decode(json_encode((new AssetPanelResource(AssetPanelQuery::for($asset, $withOdn)))->resolve()), true);
    }

    /** @return array<string, mixed> */
    private function port(array $panel, string $group, int $number): array
    {
        $ports = collect($panel['groups'])->firstWhere('key', $group)['ports'] ?? [];
        $port = collect($ports)->firstWhere('number', $number);
        $this->assertNotNull($port, "No port {$group}:{$number} in the panel");

        return $port;
    }

    /** @return array<string, bool> the flags of a port that are true */
    private function state(array $panel, string $group, int $number): array
    {
        return $this->port($panel, $group, $number)['state'];
    }

    protected function withModel(NetworkAsset $asset, string $templateKey): NetworkAsset
    {
        AssetPanelService::apply($asset, $templateKey, null, null);

        return $asset->refresh();
    }

    private function ponPort(NetworkAsset $olt, int $number): OdnPort
    {
        return OdnPort::query()->where('network_asset_id', $olt->id)->where('kind', OdnPort::KIND_PON)->where('number', $number)->firstOrFail();
    }

    /** An active (by default) subscription of a customer called $name, with its RADIUS account, and its drop on $port when given. */
    private function subscriber(string $name, ?OdnPort $port = null, string $status = 'active'): ServiceSubscription
    {
        $subscription = $this->makeSubscription($this->company, $status);
        DB::table('customers')->where('id', $subscription->customer_id)->update(['name' => $name]);
        NetworkAccount::factory()->create([
            'company_id' => $this->company->id,
            'subscription_id' => $subscription->id,
            'username' => Str::slug($name).'@'.$this->realm(),
        ]);

        if ($port !== null) {
            OdnService::assignDrop($port, $subscription);
        }

        return $subscription;
    }

    private function goOnline(ServiceSubscription $subscription): void
    {
        $this->seedRadiusSession(['username' => NetworkAccount::query()->where('subscription_id', $subscription->id)->value('username')]);
    }

    /** Written below the observers: a block or status change made through the model would queue the apply job. */
    private function block(ServiceSubscription $subscription): void
    {
        DB::table('service_subscriptions')->where('id', $subscription->id)->update(['blocked_at' => now()]);
    }

    /**
     * Counts the statements a piece of work ran: on the application database and on the radius connection.
     *
     * @return array{0: int, 1: int}
     */
    private function statements(callable $work): array
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

            return [count(DB::getQueryLog()), count($radius->getQueryLog())];
        } finally {
            DB::disableQueryLog();
            $radius->disableQueryLog();
        }
    }

    /**
     * The OLT of the topology with a custom layout of $pon PON ports in rows of 16 (the last row also holds 4 uplinks).
     * Every PON port feeds a 1:2 splitter on the ODP; the first $drops of those splitters give output 1 to an active
     * subscriber with a RADIUS account, and every other one of those subscribers is online.
     */
    protected function bigOlt(int $pon, int $drops): NetworkAsset
    {
        $rows = [];

        for ($slot = 1, $left = $pon; $left > 0; $slot++, $left -= 16) {
            $rows[] = [['group' => "pon{$slot}", 'kind' => 'sfp', 'count' => min(16, $left), 'role' => 'pon', 'slot' => $slot, 'name' => 'gpon-olt_1/{slot}/{port}', 'title' => "Slot {$slot}"]];
        }

        $rows[array_key_last($rows)][] = ['group' => 'uplink', 'kind' => 'sfp', 'count' => 4, 'name' => 'UP{port}', 'title' => 'Uplink'];
        AssetPanelService::apply($this->net['olt'], null, ['rows' => $rows], null);

        $customer = Customer::factory()->create(['company_id' => $this->company->id]);
        $subscriptions = ServiceSubscription::factory()->count($drops)->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'service_package_id' => ServicePackageFactory::new()->create(['company_id' => $this->company->id])->id,
            'installation_address_id' => CustomerAddress::factory()->create(['company_id' => $this->company->id, 'customer_id' => $customer->id, 'label' => 'Rumah'])->id,
            'status' => 'active',
        ]);

        foreach (OdnPort::query()->where('network_asset_id', $this->net['olt']->id)->orderBy('number')->get() as $index => $ponPort) {
            [, $outputs] = $this->splitter($this->net['odp'], 2, $ponPort);
            $subscription = $subscriptions[$index] ?? null;

            if ($subscription === null) {
                continue;
            }

            $username = "bulk{$index}@{$this->realm()}";
            NetworkAccount::factory()->create(['company_id' => $this->company->id, 'subscription_id' => $subscription->id, 'username' => $username]);
            OdnService::assignDrop($outputs[0], $subscription);

            if ($index % 2 === 0) {
                $this->seedRadiusSession(['username' => $username]);
            }
        }

        return $this->net['olt']->refresh();
    }

    public function test_the_olt_panel_names_pon_ports_from_the_model_and_joins_the_odn_register(): void
    {
        $olt = $this->withModel($this->net['olt'], 'zte-c320-gtgh');
        $this->splitter($this->net['odc'], 8, $this->ponPort($olt, 4));
        $template = PortTemplate::query()->where('key', 'zte-c320-gtgh')->firstOrFail();

        $panel = $this->panel($olt);

        $this->assertSame(['id' => $olt->public_id, 'code' => 'OLT-A1', 'type' => 'olt'], $panel['asset']);
        $this->assertSame(['zte-c320-gtgh', 'ZTE', $template->model, 2], [$panel['template']['key'], $panel['template']['vendor'], $panel['template']['model'], $panel['template']['height_u']]);
        $this->assertSame($olt->port_layout, $panel['template']['layout']);
        $this->assertSame(['known' => true], $panel['online']);
        $this->assertSame(['pon1', 'pon2', 'uplink', 'uplinkge', 'uplinkcu'], array_column($panel['groups'], 'key'));
        $this->assertSame(['pon', 'pon', 'port', 'port', 'port'], array_column($panel['groups'], 'role'));
        $this->assertSame([16, 16, 2, 2, 2], array_map(fn (array $group): int => count($group['ports']), $panel['groups']));

        $fed = $this->port($panel, 'pon1', 4);
        $this->assertSame($this->ponPort($olt, 4)->public_id, $fed['id']);
        $this->assertSame('gpon-olt_1/1/4', $fed['name']);
        $this->assertSame(['occupied' => true], $fed['state']);
        $this->assertSame(['asset' => 'ODC-A1', 'port' => '1:8'], $fed['link']);
        $this->assertSame(['total' => 0, 'online' => 0], $fed['behind']);

        $free = $this->port($panel, 'pon2', 1);
        $this->assertSame('gpon-olt_1/2/1', $free['name']);
        $this->assertSame([], $free['state']);
        $this->assertSame(['id', 'number', 'name', 'state'], array_keys($free), 'a free port sends nothing but its identity');
    }

    public function test_huawei_pon_names_are_zero_based(): void
    {
        $huawei = $this->withModel($this->makeAsset($this->company, 'olt', null, ['code' => 'OLT-HW']), 'huawei-ma5608t-gpfd');

        $panel = $this->panel($huawei);

        $this->assertSame(['pon0', 'pon1', 'uplink'], array_column($panel['groups'], 'key'));
        $this->assertSame(['0/0/0', '0/0/15', '0/1/0'], [$this->port($panel, 'pon0', 1)['name'], $this->port($panel, 'pon0', 16)['name'], $this->port($panel, 'pon1', 1)['name']]);
        $this->assertSame($this->ponPort($huawei, 17)->public_id, $this->port($panel, 'pon1', 1)['id'], 'the register ordinal 17 is port 0 of slot 1');
    }

    public function test_a_model_slot_without_a_register_row_is_unregistered_and_extra_pon_ports_get_their_own_group(): void
    {
        $olt = $this->withModel($this->net['olt'], 'zte-c320-gtgo');
        OdnService::deletePort($this->ponPort($olt, 3));
        OdnService::createPonPorts($olt, 2);

        $panel = $this->panel($olt);

        $this->assertSame(['id' => null, 'number' => 3, 'name' => 'gpon-olt_1/1/3', 'state' => ['unregistered' => true]], $this->port($panel, 'pon1', 3));

        $extra = collect($panel['groups'])->firstWhere('key', 'ponx');
        $this->assertSame(['pon', 'Port PON di luar model', ['PON 17', 'PON 18']], [$extra['role'], $extra['label'], array_column($extra['ports'], 'name')]);

        $rows = $panel['template']['layout']['rows'];
        $this->assertSame(['ponx', 2], [$rows[array_key_last($rows)][0]['group'], $rows[array_key_last($rows)][0]['count']], 'the extra ports are drawn in a row of their own');
    }

    public function test_the_odp_panel_has_one_in_and_one_out_group_per_splitter_with_the_input_link_and_drops(): void
    {
        [, $odcOutputs] = $this->splitter($this->net['odc'], 8, $this->ponPorts($this->net['olt'], 3)[2]);
        [, $odpOutputs] = $this->splitter($this->net['odp'], 8, $odcOutputs[1], 'Utara');
        $budi = $this->subscriber('Budi', $odpOutputs[2]);

        $panel = $this->panel($this->net['odp']);

        $this->assertSame(['in1', 's1'], array_column($panel['groups'], 'key'));
        $this->assertSame(['splitter_in', 'splitter_out'], array_column($panel['groups'], 'role'));
        $this->assertSame(['sc', 'sc'], array_column($panel['groups'], 'kind'));
        $this->assertSame('Splitter Utara', $panel['groups'][1]['label']);

        $in = $this->port($panel, 'in1', 1);
        $this->assertSame([null, 'IN', ['occupied' => true], ['asset' => 'ODC-A1', 'port' => '1:8 · keluaran 2']], [$in['id'], $in['name'], $in['state'], $in['link']]);

        $held = $this->port($panel, 's1', 3);
        $this->assertSame($odpOutputs[2]->public_id, $held['id']);
        $this->assertSame(['occupied' => true], $held['state'], 'no session, so no online flag');
        $this->assertSame(['subscription' => $budi->code, 'customer' => 'Budi'], $held['drop']);
        $this->assertSame([], $this->state($panel, 's1', 4));

        $this->assertSame([[
            ['group' => 'in1', 'kind' => 'sc', 'count' => 1, 'name' => 'IN', 'title' => 'Masuk'],
            ['group' => 's1', 'kind' => 'sc', 'count' => 8, 'stack' => 1, 'order' => 'row', 'name' => '{port}', 'role' => 'splitter', 'title' => 'Splitter Utara'],
        ]], $panel['template']['layout']['rows']);
        $this->assertNull($panel['template']['key']);
    }

    public function test_pon_ports_count_subscribers_and_online_behind_two_splitter_levels(): void
    {
        $olt = $this->withModel($this->net['olt'], 'zte-c320-gtgh');
        [, $odcOutputs] = $this->splitter($this->net['odc'], 4, $this->ponPort($olt, 1));
        [, $firstOdp] = $this->splitter($this->net['odp'], 8, $odcOutputs[0]);
        $secondOdp = $this->odpAt($this->company, $this->net['site'], 'ODP-A12');
        [, $secondOutputs] = $this->splitter($secondOdp, 8, $odcOutputs[1]);

        $ani = $this->subscriber('Ani', $firstOdp[0]);
        $budi = $this->subscriber('Budi', $secondOutputs[0]);
        $this->subscriber('Cici', $firstOdp[1]);
        $this->subscriber('Dedi', $firstOdp[2]);
        $this->subscriber('Eka', $secondOutputs[1]);
        $this->goOnline($ani);
        $this->goOnline($budi);

        $pon = $this->port($this->panel($olt), 'pon1', 1);
        $this->assertSame(['total' => 5, 'online' => 2], $pon['behind']);
        $this->assertSame(['occupied' => true, 'online' => true], $pon['state']);

        $odc = $this->panel($this->net['odc']);
        $this->assertSame(['total' => 3, 'online' => 1], $this->port($odc, 's1', 1)['behind']);
        $this->assertSame(['total' => 2, 'online' => 1], $this->port($odc, 's1', 2)['behind']);
        $this->assertSame(['occupied' => true, 'online' => true], $this->state($odc, 's1', 1));
        $this->assertArrayNotHasKey('behind', $this->port($odc, 's1', 3));
    }

    public function test_online_and_isolir_state_come_from_radius_and_the_subscription(): void
    {
        [, $outputs] = $this->splitter($this->net['odp'], 8);
        $this->goOnline($this->subscriber('Live', $outputs[0]));
        $this->subscriber('Quiet', $outputs[1]);
        $this->subscriber('Suspended', $outputs[2], 'suspended');
        $this->block($this->subscriber('Blocked', $outputs[3]));
        $this->subscriber('Stale', $outputs[4]);
        $this->subscriber('Ended', $outputs[5]);
        $this->seedRadiusSession(['username' => 'stale@'.$this->realm(), 'acctstarttime' => now()->subMinutes(40)->format('Y-m-d H:i:s'), 'acctupdatetime' => now()->subMinutes(20)->format('Y-m-d H:i:s')]);
        $this->seedRadiusSession(['username' => 'ended@'.$this->realm(), 'acctstoptime' => now()->subMinute()->format('Y-m-d H:i:s')]);

        $panel = $this->panel($this->net['odp']);

        $this->assertSame(['occupied' => true, 'online' => true], $this->state($panel, 's1', 1));
        $this->assertSame(['occupied' => true], $this->state($panel, 's1', 2), 'held, no session: offline');
        $this->assertSame(['occupied' => true, 'isolir' => true], $this->state($panel, 's1', 3));
        $this->assertSame(['occupied' => true, 'isolir' => true], $this->state($panel, 's1', 4));
        $this->assertSame(['occupied' => true], $this->state($panel, 's1', 5), 'a session without an update for 15 minutes is a leftover');
        $this->assertSame(['occupied' => true], $this->state($panel, 's1', 6), 'a closed session is not online');
        $this->assertSame([], $this->state($panel, 's1', 7));
        $this->assertSame(['known' => true], $panel['online']);
    }

    public function test_isolir_matches_the_provisioner_rule_for_every_status_and_block(): void
    {
        [, $outputs] = $this->splitter($this->net['odp'], 8);
        $subscriptions = [];

        foreach (['pending', 'active', 'suspended'] as $status) {
            foreach ([false, true] as $blocked) {
                $subscription = $this->subscriber("{$status} ".($blocked ? 'blocked' : 'open'), $outputs[count($subscriptions)], $status);

                if ($blocked) {
                    $this->block($subscription);
                }

                $subscriptions[] = $subscription;
            }
        }

        $panel = $this->panel($this->net['odp']);

        foreach ($subscriptions as $index => $subscription) {
            $expected = app(NetworkAccountProvisioner::class)->isolatedBecause($subscription->fresh()) !== [];
            $state = $this->state($panel, 's1', $index + 1);

            $this->assertSame($expected, isset($state['isolir']), "{$subscription->status}, blocked ".json_encode($subscription->fresh()->blocked_at !== null));
        }
    }

    public function test_online_is_unknown_while_radius_is_off_or_down_and_a_failure_is_not_cached(): void
    {
        [, $outputs] = $this->splitter($this->net['odp'], 8);
        $budi = $this->subscriber('Budi', $outputs[0]);

        $this->disableRadius();
        $off = $this->panel($this->net['odp']);

        $this->assertSame(['known' => false, 'reason' => 'disabled'], $off['online']);
        $this->assertSame(['occupied' => true], $this->state($off, 's1', 1), 'the page renders, without online flags');

        $this->breakRadius();
        $down = $this->panel($this->net['odp']);

        $this->assertSame(['known' => false, 'reason' => 'unavailable'], $down['online']);
        $this->assertStringNotContainsString('fake_radius', json_encode($down), 'the driver error stays out of the payload');

        $this->fakeRadius();
        $this->goOnline($budi);
        $back = $this->panel($this->net['odp']);

        $this->assertSame(['known' => true], $back['online']);
        $this->assertSame(['occupied' => true, 'online' => true], $this->state($back, 's1', 1));
    }

    public function test_the_online_set_is_one_radacct_query_cached_for_15_seconds(): void
    {
        [, $outputs] = $this->splitter($this->net['odp'], 8);
        $budi = $this->subscriber('Budi', $outputs[0]);
        $this->subscriber('Wati', $outputs[1]);
        $this->goOnline($budi);

        [, $firstRadius] = $this->statements(fn () => $this->assertSame(['occupied' => true, 'online' => true], $this->state($this->panel($this->net['odp']), 's1', 1)));
        [, $cachedRadius] = $this->statements(fn () => $this->panel($this->net['odp']));

        $this->assertSame(1, $firstRadius, 'one radacct query for all usernames of the panel');
        $this->assertSame(0, $cachedRadius, 'the second read is answered from the cache');

        $this->radiusConnection()->table('radacct')->update(['acctstoptime' => now()->format('Y-m-d H:i:s')]);
        $this->travel(14)->seconds();
        $this->assertSame(['occupied' => true, 'online' => true], $this->state($this->panel($this->net['odp']), 's1', 1), 'still the set of 14 seconds ago');

        $this->travel(2)->seconds();
        [, $expiredRadius] = $this->statements(fn () => $this->assertSame(['occupied' => true], $this->state($this->panel($this->net['odp']), 's1', 1)));
        $this->assertSame(1, $expiredRadius, 'after 15 seconds RADIUS is asked again');
    }

    public function test_an_apply_job_forgets_the_online_set_of_its_company(): void
    {
        $nas = $this->nasClient($this->company);
        $this->coaAnswers((string) $nas->nasname, 'ack');
        [, $outputs] = $this->splitter($this->net['odp'], 8);
        $subscription = $this->subscriptionIn($this->company, 'active', null, $this->packageWithRadiusProfile($this->company));
        $account = $this->networkAccountFor($subscription, ['username' => 'budi@'.$this->realm()]);
        OdnService::assignDrop($outputs[0], $subscription);
        $this->applyAccount($account);
        $this->seedRadiusSession(['username' => 'budi@'.$this->realm(), 'nasipaddress' => (string) $nas->nasname]);

        $this->assertSame(['occupied' => true, 'online' => true], $this->state($this->panel($this->net['odp']), 's1', 1));

        $this->radiusConnection()->table('radacct')->update(['acctstoptime' => now()->format('Y-m-d H:i:s')]);
        $this->assertSame(['occupied' => true, 'online' => true], $this->state($this->panel($this->net['odp']), 's1', 1), 'cached until the account is applied again');

        $this->applyAccount($account);

        $this->assertSame(['occupied' => true], $this->state($this->panel($this->net['odp']), 's1', 1), 'the apply job threw the cached set away');
    }

    public function test_the_panel_of_a_128_port_olt_runs_at_most_six_queries(): void
    {
        $olt = $this->bigOlt(128, 64);

        [$app, $radius] = $this->statements(fn () => $this->panel($olt));

        $this->assertLessThanOrEqual(6, $app + $radius, "{$app} application statements and {$radius} radacct queries");
        $this->assertSame(1, $radius);
    }

    public function test_the_query_count_does_not_grow_from_8_to_128_ports(): void
    {
        $small = $this->makeAsset($this->company, 'olt', null, ['code' => 'OLT-SMALL']);
        AssetPanelService::apply($small, null, ['rows' => [[['group' => 'pon1', 'kind' => 'sfp', 'count' => 8, 'role' => 'pon', 'slot' => 1, 'name' => 'gpon-olt_1/{slot}/{port}']]]], null);
        [, $outputs] = $this->splitter($this->net['odp'], 2, $this->ponPort($small, 1));
        $this->goOnline($this->subscriber('Budi', $outputs[0]));
        $small->refresh();
        [$smallApp, $smallRadius] = $this->statements(fn () => $this->panel($small));

        $big = $this->bigOlt(128, 64);
        [$bigApp, $bigRadius] = $this->statements(fn () => $this->panel($big));

        $this->assertSame([$smallApp, $smallRadius], [$bigApp, $bigRadius]);
    }

    public function test_the_128_port_olt_payload_stays_under_30_kb(): void
    {
        $olt = $this->bigOlt(128, 64);

        $json = json_encode((new AssetPanelResource(AssetPanelQuery::for($olt, true)))->resolve());

        $panel = json_decode($json, true);
        $this->assertCount(128, collect($panel['groups'])->where('role', 'pon')->flatMap(fn (array $group): array => $group['ports']));
        $this->assertSame(['occupied' => true, 'online' => true], $this->state($panel, 'pon1', 1));
        $this->assertSame(['occupied' => true], $this->state($panel, 'pon1', 2));
        $this->assertStringContainsString('"state":{}', $json, 'an empty state is a JSON object');
        $this->assertStringNotContainsString('"state":[]', $json);
        $this->assertLessThanOrEqual(30 * 1024, strlen($json), 'payload of '.strlen($json).' bytes');
    }

    public function test_without_odn_view_the_odn_backed_parts_are_left_out(): void
    {
        $olt = $this->withModel($this->net['olt'], 'zte-c320-gtgh');
        $this->splitter($this->net['odp'], 8);

        $oltPanel = $this->panel($olt, false);
        $odpPanel = $this->panel($this->net['odp'], false);

        $this->assertSame(['uplink', 'uplinkge', 'uplinkcu'], array_column($oltPanel['groups'], 'key'));
        $this->assertTrue($oltPanel['odn_hidden']);
        $drawn = collect($oltPanel['template']['layout']['rows'])->flatten(1)->whereNotNull('group')->pluck('group')->all();
        $this->assertSame(['uplink', 'uplinkge', 'uplinkcu'], $drawn, 'no drawn block is left without a group');
        $this->assertSame([], $odpPanel['groups']);
        $this->assertNull($odpPanel['template']);
        $this->assertTrue($odpPanel['odn_hidden']);
        $this->assertArrayNotHasKey('odn_hidden', $this->panel($olt));
    }

    public function test_the_ont_pon_port_shows_the_drop_of_its_subscription(): void
    {
        [, $outputs] = $this->splitter($this->net['odp'], 8);
        $ont = $this->withModel($this->makeAsset($this->company, 'onu_ont', $this->net['site'], ['code' => 'ONT-1']), 'generic-ont-1pon-4lan-2pots');
        $budi = $this->subscriber('Budi', $outputs[0]);
        DB::table('service_subscriptions')->where('id', $budi->id)->update(['ont_asset_id' => $ont->id]);
        $this->goOnline($budi);

        $panel = $this->panel($ont);

        $pon = $this->port($panel, 'pon', 1);
        $this->assertSame(['occupied' => true, 'online' => true], $pon['state']);
        $this->assertSame(['subscription' => $budi->code, 'customer' => 'Budi'], $pon['drop']);
        $this->assertSame(['asset' => 'ODP-A11', 'port' => '1:8 · keluaran 1'], $pon['link']);
        $this->assertSame([], $this->state($panel, 'lan', 1));
        $this->assertSame(['pon', 'lan', 'tel'], array_column($panel['groups'], 'key'));
        $this->assertSame([], $this->state($this->panel($ont, false), 'pon', 1), 'without odn.view the drop is not shown');

        $detail = AssetPanelQuery::portDetail($ont, $pon['id'], true);
        $this->assertSame(['asset', $budi->code, 'Budi'], [$detail['source'], $detail['drop']['subscription']['code'], $detail['drop']['customer']['name']]);
        $this->assertNull(AssetPanelQuery::portDetail($ont, $pon['id'], false)['drop'], 'detail of the ONT port keeps the drop behind odn.view');
    }

    public function test_a_device_without_a_model_gets_the_picker_and_a_rack_lists_what_is_mounted(): void
    {
        $router = $this->makeAsset($this->company, 'router', null, ['code' => 'RTR-1', 'vendor' => 'MikroTik', 'model' => 'ccr2004-16g-2s+']);

        $picker = $this->panel($router);

        $this->assertNull($picker['template']);
        $this->assertSame([], $picker['groups']);
        $this->assertSame('mikrotik-ccr2004-16g-2s', $picker['picker']['suggested']);
        $this->assertContains('mikrotik-hap-ac2', array_column($picker['picker']['options'], 'key'));
        $this->assertSame('MikroTik CCR2004-16G-2S+', collect($picker['picker']['options'])->firstWhere('key', 'mikrotik-ccr2004-16g-2s')['label']);
        $this->assertNull($this->panel($this->makeAsset($this->company, 'router', null, ['vendor' => 'Cisco', 'model' => '1841']))['picker']['suggested']);

        $rack = $this->makeAsset($this->company, 'rack', null, ['code' => 'RACK-1']);
        $this->assertSame(['height_u' => null, 'items' => []], $this->panel($rack)['rack']);
        AssetPanelService::apply($rack, null, null, 42);
        $olt = $this->withModel($this->net['olt'], 'zte-c320-gtgh');
        $router = $this->withModel($router, 'mikrotik-ccr2004-16g-2s');
        AssetPanelService::place($router, $rack->id, 13, 1);
        AssetPanelService::place($olt, $rack->id, 10, 2);

        $panel = $this->panel($rack->refresh());

        $this->assertNull($panel['template']);
        $this->assertSame([], $panel['groups']);
        $this->assertArrayNotHasKey('picker', $panel);
        $this->assertSame(42, $panel['rack']['height_u']);
        $this->assertSame([['RTR-1', 'router', 13, 1], ['OLT-A1', 'olt', 10, 2]], array_map(fn (array $item): array => [$item['code'], $item['type'], $item['unit'], $item['height']], $panel['rack']['items']));
        $this->assertSame($router->port_layout, $panel['rack']['items'][0]['layout']);
    }

    public function test_a_device_keeps_the_catalogue_picker_once_it_has_a_model_so_ganti_model_can_offer_it(): void
    {
        $router = $this->withModel($this->makeAsset($this->company, 'router', null, ['code' => 'RTR-1', 'vendor' => 'MikroTik', 'model' => 'ccr2004-16g-2s+']), 'mikrotik-hap-ac2');
        $olt = $this->withModel($this->net['olt'], 'zte-c320-gtgh');
        $ont = $this->withModel($this->makeAsset($this->company, 'onu_ont', $this->net['site'], ['code' => 'ONT-1']), 'generic-ont-1pon-4lan-2pots');
        $custom = $this->makeAsset($this->company, 'switch', null, ['code' => 'SW-CUSTOM']);
        AssetPanelService::apply($custom, null, ['rows' => [[['group' => 'ether', 'kind' => 'rj45', 'count' => 4]]]], null);
        $keys = fn (array $panel): array => array_column($panel['picker']['options'] ?? [], 'key');

        $modelled = $this->panel($router);

        $this->assertSame(['mikrotik-hap-ac2', 'MikroTik'], [$modelled['template']['key'], $modelled['template']['vendor']]);
        $this->assertCount(1, $modelled['groups'], 'the panel is drawn as before');
        $this->assertEqualsCanonicalizing(['mikrotik-hap-ac2', 'mikrotik-rb4011igs-rm', 'mikrotik-ccr2004-16g-2s'], $keys($modelled), 'the routers of the catalogue, not the OLTs');
        $this->assertTrue(array_is_list($modelled['picker']['options']));
        $this->assertSame('mikrotik-ccr2004-16g-2s', $modelled['picker']['suggested'], 'the suggestion is what the asset text names, not what is applied');
        $this->assertSame(['key' => 'mikrotik-hap-ac2', 'label' => 'MikroTik hAP ac² (RBD52G-5HacD2HnD)', 'height_u' => null], collect($modelled['picker']['options'])->firstWhere('key', 'mikrotik-hap-ac2'));
        $this->assertEqualsCanonicalizing(['zte-c320-gtgh', 'zte-c320-gtgo', 'huawei-ma5608t-gpfd'], $keys($this->panel($olt)));
        $this->assertSame(['generic-ont-1pon-4lan-2pots'], $keys($this->panel($ont)));
        $this->assertContains('generic-switch-24', $keys($this->panel($custom->refresh())), 'a custom layout can pick a catalogue model too');
        $this->assertNotContains('mikrotik-hap-ac2', $keys($this->panel($custom)), 'only the models of its own type');

        // The template's vendor and model and the picker are one read of the catalogue, whatever the type.
        $reads = function (NetworkAsset $asset): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->panel($asset->refresh());
            $count = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'port_templates'))->count();
            DB::disableQueryLog();

            return $count;
        };
        $this->assertSame([1, 1, 1, 1], [$reads($router), $reads($olt), $reads($ont), $reads($custom)]);

        // A unit that changed type since its model was applied still names that model and is offered the new type's.
        DB::table('network_assets')->where('id', $router->id)->update(['asset_type' => 'switch']);
        $changed = $this->panel($router->refresh());

        $this->assertSame(['mikrotik-hap-ac2', 'MikroTik'], [$changed['template']['key'], $changed['template']['vendor']]);
        $this->assertNotContains('mikrotik-hap-ac2', $keys($changed));
        $this->assertContains('generic-switch-8', $keys($changed));
        $this->assertTrue(array_is_list($changed['picker']['options']));
        $this->assertSame(1, $reads($router));

        // An ODP takes a model while it has no splitter; an ODC and a rack have no catalogue row at all.
        $this->assertEqualsCanonicalizing(['generic-odp-8', 'generic-odp-16'], $keys($this->panel($this->net['odp'])));
        $this->splitter($this->net['odp'], 8);
        $this->splitter($this->net['odc'], 8);
        $this->assertArrayNotHasKey('picker', $this->panel($this->net['odp']));
        $this->assertArrayNotHasKey('picker', $this->panel($this->net['odc']));
        $this->assertArrayNotHasKey('picker', $this->panel($this->makeAsset($this->company, 'rack', null, ['code' => 'RACK-9'])));
    }

    public function test_the_trace_path_carries_one_lit_port_per_hop(): void
    {
        $olt = $this->withModel($this->net['olt'], 'zte-c320-gtgh');
        [, $odcOutputs] = $this->splitter($this->net['odc'], 4, $this->ponPort($olt, 18));
        [, $odpOutputs] = $this->splitter($this->net['odp'], 8, $odcOutputs[1]);
        $ont = $this->withModel($this->makeAsset($this->company, 'onu_ont', $this->net['site'], ['code' => 'ONT-1']), 'generic-ont-1pon-4lan-2pots');
        $budi = $this->subscriber('Budi', $odpOutputs[2]);
        DB::table('service_subscriptions')->where('id', $budi->id)->update(['ont_asset_id' => $ont->id]);
        $this->goOnline($budi);

        $path = null;
        [$app, $radius] = $this->statements(function () use ($budi, &$path): void {
            $path = AssetPanelQuery::tracePath($budi->public_id);
        });
        $path = json_decode(json_encode($path), true);

        $this->assertLessThanOrEqual(4, $app + $radius);
        $this->assertSame(['id' => $budi->public_id, 'code' => $budi->code, 'customer' => 'Budi'], $path['subscription']);
        $this->assertSame(['occupied' => true, 'online' => true], $path['state']);
        $this->assertSame(['known' => true], $path['online']);
        $this->assertSame(['OLT-A1', 'ODC-A1', 'ODP-A11', 'ONT-1'], array_map(fn (array $hop): string => $hop['asset']['code'], $path['hops']));
        $this->assertSame(['olt', 'odc', 'odp', 'onu_ont'], array_map(fn (array $hop): string => $hop['asset']['type'], $path['hops']));
        $this->assertSame(['gpon-olt_1/2/2', 'ODC-A1 · 1:4 · 2', 'ODP-A11 · 1:8 · 3', 'Budi'], array_column($path['hops'], 'label'));
        $this->assertSame([['group' => 'pon2', 'number' => 2], ['group' => 's1', 'number' => 2], ['group' => 's1', 'number' => 3], ['group' => 'pon', 'number' => 1]], array_column($path['hops'], 'lit'));
        $this->assertSame($olt->port_layout, $path['hops'][0]['layout']);
        $this->assertSame(8, $path['hops'][2]['layout']['rows'][0][1]['count'], 'a splitter hop draws its own splitter only');
        $this->assertSame($ont->port_layout, $path['hops'][3]['layout']);

        $other = $this->makeSubscription($this->company);
        $this->assertSame([], AssetPanelQuery::tracePath($other->public_id)['hops'], 'no drop, no path');
        $this->assertNull(AssetPanelQuery::tracePath((string) Str::ulid()));
        $this->assertNull(AssetPanelQuery::tracePath('not-an-id'));
    }

    public function test_port_detail_is_read_for_ports_of_that_asset_only(): void
    {
        $olt = $this->withModel($this->net['olt'], 'zte-c320-gtgh');
        [, $odcOutputs] = $this->splitter($this->net['odc'], 8, $this->ponPort($olt, 1));
        [, $odpOutputs] = $this->splitter($this->net['odp'], 8, $odcOutputs[0]);
        $budi = $this->subscriber('Budi', $odpOutputs[0]);
        $this->goOnline($budi);
        $router = $this->withModel($this->makeAsset($this->company, 'router', null, ['code' => 'RTR-1']), 'mikrotik-rb4011igs-rm');
        $uplink = DB::table('asset_ports')->where('network_asset_id', $olt->id)->where('group_key', 'uplink')->where('number', 1)->first();
        $sfp = DB::table('asset_ports')->where('network_asset_id', $router->id)->where('name', 'sfp-sfpplus1')->first();
        DB::table('asset_ports')->where('id', $uplink->id)->update(['linked_port_id' => $sfp->id, 'label' => 'ke RTR', 'note' => 'VLAN 100']);
        DB::table('asset_ports')->where('id', $sfp->id)->update(['linked_port_id' => $uplink->id]);

        $drop = AssetPanelQuery::portDetail($this->net['odp'], $odpOutputs[0]->public_id, true);
        $this->assertSame([$odpOutputs[0]->public_id, 'odn', '1', null, null], [$drop['id'], $drop['source'], $drop['name'], $drop['feeds'], $drop['link']]);
        $this->assertSame(['id' => $budi->public_id, 'code' => $budi->code, 'status' => 'active', 'blocked' => false], $drop['drop']['subscription']);
        $this->assertSame('Budi', $drop['drop']['customer']['name']);
        $this->assertTrue($drop['drop']['session']['online']);
        $this->assertNotNull($drop['drop']['session']['since']);
        $this->assertNotNull($drop['drop']['package']);

        $pon = AssetPanelQuery::portDetail($olt, $this->ponPort($olt, 1)->public_id, true);
        $this->assertSame(['gpon-olt_1/1/1', null, ['asset' => ['id' => $this->net['odc']->public_id, 'code' => 'ODC-A1'], 'splitter' => '1:8'], ['total' => 1, 'online' => 1]], [$pon['name'], $pon['drop'], $pon['feeds'], $pon['behind']]);

        $linked = AssetPanelQuery::portDetail($olt, $uplink->public_id, true);
        $this->assertSame(['asset', 'ke RTR', 'VLAN 100', false], [$linked['source'], $linked['label'], $linked['note'], $linked['admin_down']]);
        $this->assertSame(['asset' => ['id' => $router->public_id, 'code' => 'RTR-1', 'name' => $router->name], 'port' => ['id' => $sfp->public_id, 'name' => 'sfp-sfpplus1']], $linked['link']);

        $this->assertNull(AssetPanelQuery::portDetail($this->net['odp'], $uplink->public_id, true), 'a port of another asset');
        $this->assertNull(AssetPanelQuery::portDetail($olt, $odpOutputs[0]->public_id, true), 'an ODN port of another asset');
        $this->assertNull(AssetPanelQuery::portDetail($this->net['odp'], $odpOutputs[0]->public_id, false), 'ODN ports need odn.view');
        $this->assertNull(AssetPanelQuery::portDetail($olt, 'nope', true));

        $this->disableRadius();
        $this->assertNull(AssetPanelQuery::portDetail($this->net['odp'], $odpOutputs[0]->public_id, true)['drop']['session'], 'RADIUS off: unknown, not offline');
    }

    public function test_free_port_options_list_the_free_ports_of_another_asset_of_the_company(): void
    {
        $router = $this->withModel($this->makeAsset($this->company, 'router', null, ['code' => 'RTR-1']), 'mikrotik-hap-ac2');
        $switch = $this->withModel($this->makeAsset($this->company, 'switch', null, ['code' => 'SW-1']), 'generic-switch-8');
        $taken = DB::table('asset_ports')->where('network_asset_id', $switch->id)->where('number', 2)->value('id');
        $mine = DB::table('asset_ports')->where('network_asset_id', $router->id)->where('number', 1)->value('id');
        DB::table('asset_ports')->where('id', $taken)->update(['linked_port_id' => $mine]);
        DB::table('asset_ports')->where('id', $mine)->update(['linked_port_id' => $taken]);
        DB::table('asset_ports')->where('network_asset_id', $switch->id)->where('number', 3)->update(['label' => 'Lantai 2']);
        $stranger = $this->otherCompany();
        $foreign = $this->inCompany($stranger, fn () => $this->withModel($this->makeAsset($stranger, 'switch', null, ['code' => 'SW-X']), 'generic-switch-8'));

        $options = AssetPanelQuery::freePortOptions($router, $switch->public_id);

        $this->assertSame(['1', '3 · Lantai 2', '4', '5', '6', '7', '8'], array_column($options, 'label'));
        $this->assertSame(DB::table('asset_ports')->where('network_asset_id', $switch->id)->where('number', 1)->value('public_id'), $options[0]['value']);
        $this->assertSame([], AssetPanelQuery::freePortOptions($router, $router->public_id), 'the asset itself');
        $this->assertSame([], AssetPanelQuery::freePortOptions($router, $foreign->public_id), 'another company');
        $this->assertSame([], AssetPanelQuery::freePortOptions($router, 'nope'));
        $this->assertSame([], AssetPanelQuery::freePortOptions($router, null));
        $this->assertSame([], AssetPanelQuery::freePortOptions($router, ['x']));
    }

    public function test_the_show_page_defers_the_panel_and_the_deferred_reload_delivers_it(): void
    {
        $router = $this->withModel($this->makeAsset($this->company, 'router', null, ['code' => 'RTR-1']), 'mikrotik-hap-ac2');

        $this->get(route('admin.network-assets.show', $router))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/NetworkAssets/Show')
                ->missing('panel')
                ->missing('portDetail')
                ->missing('linkAssetOptions')
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('panel.asset.code', 'RTR-1')
                    ->where('panel.template.key', 'mikrotik-hap-ac2')
                    ->has('panel.groups', 1)));

        $raw = $this->get(route('admin.network-assets.show', $router), $this->partialReload('Admin/NetworkAssets/Show', 'panel'))->assertOk()->getContent();
        $this->assertStringContainsString('"state":{}', $raw, 'a free port is an empty object on the wire');
    }

    public function test_link_and_rack_options_are_found_by_code_or_name_and_only_offered_to_editors(): void
    {
        $router = $this->withModel($this->makeAsset($this->company, 'router', null, ['code' => 'RTR-1']), 'mikrotik-hap-ac2');
        $this->withModel($this->makeAsset($this->company, 'switch', null, ['code' => 'SW-LANTAI', 'name' => 'Switch lantai 2', 'vendor' => 'Umum', 'model' => '8 port']), 'generic-switch-8');
        $this->makeAsset($this->company, 'switch', null, ['code' => 'SW-BARE']);
        $this->makeAsset($this->company, 'odp', null, ['code' => 'SW-ODP']);
        $rack = $this->makeAsset($this->company, 'rack', null, ['code' => 'RACK-1']);
        $options = fn (NetworkAsset $asset, string $prop, string $term): array => $this->get(
            route('admin.network-assets.show', [$asset, $prop.'_search' => $term]),
            $this->partialReload('Admin/NetworkAssets/Show', $prop.'Options'),
        )->assertOk()->json('props.'.$prop.'Options') ?? [];
        $codes = fn (array $found): array => array_map(fn (array $option): string => explode(' · ', $option['label'])[0], $found);

        $this->assertSame([['value' => NetworkAsset::query()->where('code', 'SW-LANTAI')->value('public_id'), 'label' => 'SW-LANTAI · Switch lantai 2', 'description' => 'Umum 8 port']], $options($router, 'linkAsset', 'SW-'), 'only assets that have ports: no unmodelled switch, no ODP');
        $this->assertSame(['RTR-1'], $codes($options($rack, 'rackAsset', 'RTR')));
        $this->assertNotContains('RACK-1', $codes($options($rack, 'rackAsset', 'RACK')), 'a rack does not go into a rack');

        $this->actingAs($this->userWithPermissions($this->company, 'network_asset.view'));
        $this->assertSame([], $options($router, 'linkAsset', 'SW-'), 'a reader gets no link options');
        $this->assertSame([], $options($rack, 'rackAsset', 'RTR'));
    }

    public function test_types_without_a_panel_and_users_who_cannot_view_the_asset_get_no_panel_props(): void
    {
        $radio = $this->makeAsset($this->company, 'radio', null, ['code' => 'RAD-1']);
        $router = $this->makeAsset($this->company, 'router', null, ['code' => 'RTR-1']);

        $page = $this->get(route('admin.network-assets.show', $radio))->assertOk();
        $page->assertInertia(fn (Assert $assert) => $assert->missing('panel')->missing('portDetail')->missing('linkAssetOptions'));
        $this->assertArrayNotHasKey('deferredProps', $page->viewData('page'));

        $nobody = $this->userWithPermissions($this->company, 'network_asset.nas.view');
        $request = Request::create('/');
        $request->setUserResolver(fn () => $nobody);
        $this->assertSame([], AssetPanelProps::for($request, $router));
    }

    public function test_an_asset_with_a_linked_port_or_mounted_devices_cannot_be_deleted_and_says_why(): void
    {
        $router = $this->withModel($this->makeAsset($this->company, 'router', null, ['code' => 'RTR-1']), 'mikrotik-hap-ac2');
        $switch = $this->withModel($this->makeAsset($this->company, 'switch', null, ['code' => 'SW-1']), 'generic-switch-8');
        $a = DB::table('asset_ports')->where('network_asset_id', $router->id)->where('number', 1)->value('id');
        $b = DB::table('asset_ports')->where('network_asset_id', $switch->id)->where('number', 1)->value('id');
        DB::table('asset_ports')->where('id', $a)->update(['linked_port_id' => $b]);
        DB::table('asset_ports')->where('id', $b)->update(['linked_port_id' => $a]);
        $rack = $this->makeAsset($this->company, 'rack', null, ['code' => 'RACK-1']);
        AssetPanelService::apply($rack, null, null, 42);
        AssetPanelService::place($this->net['olt'], $rack->id, 3, 2);

        $this->deleteJson(route('admin.network-assets.destroy', $router))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Port aset ini masih terhubung ke port lain. Lepas sambungannya dulu.']);
        $this->deleteJson(route('admin.network-assets.destroy', $rack))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Rak ini masih berisi perangkat. Kosongkan rak dulu.']);
    }

    public function test_the_odn_and_nas_pages_carry_the_panel_of_their_asset(): void
    {
        $this->splitter($this->net['odp'], 8);
        $nas = $this->nasClient($this->company);
        $this->withModel($nas->networkAsset, 'mikrotik-hap-ac2');

        $this->get(route('admin.odn.assets.show', $this->net['odp']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Odn/Asset')
            ->missing('panel')
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('panel.asset.type', 'odp')->has('panel.groups', 2)));

        $this->get(route('admin.nas-devices.show', $nas))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/NasDevices/Show')
            ->missing('panel')
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('panel.asset.code', $nas->networkAsset->code)->where('panel.template.key', 'mikrotik-hap-ac2')));
    }

    public function test_drop_subscription_options_hold_only_subscriptions_this_odp_can_serve(): void
    {
        [, $outputs] = $this->splitter($this->net['odp'], 8);
        $anywhere = $this->subscriber('Anywhere');
        $under = $this->subscriber('Under pop');
        $elsewhere = $this->subscriber('Elsewhere pop');
        $held = $this->subscriber('Already held', $outputs[0]);
        $ended = $this->subscriber('Ended', null, 'terminated');
        $suspended = $this->subscriber('Suspended', null, 'suspended');
        $otherPop = $this->makeLocation($this->company, 'POP-Z', 'pop');
        DB::table('service_subscriptions')->where('id', $under->id)->update(['serving_pop_id' => $this->net['pop']->id]);
        DB::table('service_subscriptions')->where('id', $elsewhere->id)->update(['serving_pop_id' => $otherPop->id]);
        $stranger = $this->otherCompany();
        $foreign = $this->inCompany($stranger, fn () => $this->makeSubscription($stranger));
        DB::table('customers')->where('id', $foreign->customer_id)->update(['name' => 'Foreign person']);

        $options = fn (string $term): array => collect($this->get(
            route('admin.network-assets.show', [$this->net['odp'], 'dropSubscription_search' => $term]),
            $this->partialReload('Admin/NetworkAssets/Show', 'dropSubscriptionOptions'),
        )->assertOk()->json('props.dropSubscriptionOptions'))->pluck('label', 'value')->all();

        $found = $options('SUB');

        $this->assertEqualsCanonicalizing([$anywhere->public_id, $under->public_id, $suspended->public_id], array_keys($found));
        $this->assertSame("{$under->code} · Under pop", $found[$under->public_id]);
        $this->assertNotContains($elsewhere->public_id, array_keys($found), 'a subscription of another POP');
        $this->assertNotContains($held->public_id, array_keys($found), 'a subscription that has a drop');
        $this->assertNotContains($ended->public_id, array_keys($found), 'a terminated subscription');
        $this->assertSame([$anywhere->public_id], array_keys($options('Anywhere')), 'found by customer name');
        $this->assertSame([], $options('Foreign'));
    }

    public function test_the_trace_page_sends_the_fibre_path_only_with_odn_view(): void
    {
        [, $outputs] = $this->splitter($this->net['odp'], 8);
        $budi = $this->subscriber('Budi', $outputs[0]);

        $this->get(route('admin.network-assets.trace', ['path_subscription' => $budi->public_id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/NetworkAssets/Trace')->where('tracePath.subscription.code', $budi->code)->has('tracePath.hops', 2));
        $this->get(route('admin.network-assets.trace'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('tracePath', null));
        $this->get(route('admin.network-assets.trace', ['path_subscription' => ['x']]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('tracePath', null));

        $viewer = $this->userWithPermissions($this->company, 'network_asset.view');
        $this->actingAs($viewer)->get(route('admin.network-assets.trace', ['path_subscription' => $budi->public_id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('tracePath', null));
    }

    public function test_trace_results_carry_location_customer_and_subscription_flat(): void
    {
        [, $outputs] = $this->splitter($this->net['odp'], 8);
        $budi = $this->subscriber('Budi', $outputs[0]);
        $ont = $this->net['olt'];
        DB::table('network_assets')->where('id', $ont->id)->update(['customer_id' => $budi->customer_id, 'subscription_id' => $budi->id]);

        $this->get(route('admin.network-assets.trace', ['search' => $ont->code]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.0.subscription.code', $budi->code)
                ->where('results.0.customer.id', $budi->customer->public_id)
                ->missing('results.0.subscription.data')
                ->has('results.0.location.name'));
    }
}
