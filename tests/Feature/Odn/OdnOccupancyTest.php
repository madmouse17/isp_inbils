<?php

namespace Tests\Feature\Odn;

use App\Models\Core\ServiceSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Queries\OdnOccupancyQuery;
use Modules\NetworkAsset\Services\OdnService;
use Tests\TestCase;
use Tests\Traits\AsksInertiaForProps;
use Tests\Traits\BuildsIpamOdnFixtures;
use Tests\Traits\BuildsOdnTopology;

class OdnOccupancyTest extends TestCase
{
    use AsksInertiaForProps;
    use BuildsIpamOdnFixtures;
    use BuildsOdnTopology;
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $net;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->bootIpamOdnWorld();
        $this->net = $this->topology($this->company);
    }

    /** @return list<ServiceSubscription> */
    private function dropAll(array $ports, int $count, string $status = 'active'): array
    {
        $subscriptions = [];

        foreach (array_slice($ports, 0, $count) as $port) {
            $subscription = $this->makeSubscription($this->company, $status, $this->net['pop']);
            OdnService::assignDrop($port, $subscription);
            $subscriptions[] = $subscription;
        }

        return $subscriptions;
    }

    /** @return list<array{value: int, label: string, description: string|null}> the free upstream ports a search for $term finds */
    private function inputPortOptions(string $term): array
    {
        return (OdnOccupancyQuery::inputPortOptions(Request::create('/', 'GET', ['inputPort_search' => $term])))();
    }

    /** @return list<array{value: int, label: string, description: string|null}> the free ODP ports under the POP a search for $term finds */
    private function dropPortOptions(?int $popId, string $term = 'ODP'): array
    {
        return (OdnOccupancyQuery::dropPortOptions(Request::create('/', 'GET', ['dropPort_search' => $term]), $popId))();
    }

    /** @return array<string, mixed> the ODP row of the occupancy list */
    private function odpRow(NetworkAsset $odp): array
    {
        $row = collect(OdnOccupancyQuery::odps([])->items())->firstWhere('asset_id', $odp->public_id);
        $this->assertNotNull($row, "{$odp->code} should be listed");

        return $row;
    }

    public function test_an_odp_with_one_1_to_8_splitter_and_three_drops_is_37_5_percent_used(): void
    {
        [, $outputs] = $this->splitter($this->net['odp'], 8);
        $this->dropAll($outputs, 3);

        $row = $this->odpRow($this->net['odp']);

        $this->assertSame(8, $row['capacity']);
        $this->assertSame(3, $row['used']);
        $this->assertSame(5, $row['free']);
        $this->assertSame(37.5, $row['percent']);
        $this->assertSame('POP-A > RACK-A1 > SITE-A11', $row['location_path']);
        $this->assertSame('ODP-A11', $row['code']);
    }

    public function test_capacity_sums_every_splitter_in_the_odp_and_an_empty_odp_is_zero(): void
    {
        $this->splitter($this->net['odp'], 8);
        $this->splitter($this->net['odp'], 4);
        $empty = $this->odpAt($this->company, $this->net['site'], 'ODP-A12');

        $this->assertSame(12, $this->odpRow($this->net['odp'])['capacity']);
        $this->assertSame(['capacity' => 0, 'used' => 0, 'free' => 0, 'percent' => 0.0], array_intersect_key($this->odpRow($empty), array_flip(['capacity', 'used', 'free', 'percent'])));
    }

    public function test_an_output_that_feeds_another_splitter_counts_as_used(): void
    {
        [, $odpOutputs] = $this->splitter($this->net['odp'], 4);
        $this->splitter($this->net['odp'], 2, $odpOutputs[0]);

        $row = $this->odpRow($this->net['odp']);

        $this->assertSame(6, $row['capacity']);
        $this->assertSame(1, $row['used'], 'the cascaded output is used, the new splitter\'s two outputs are free');
    }

    public function test_an_odc_output_feeding_an_odp_splitter_counts_as_used_on_the_asset_page(): void
    {
        $pon = $this->ponPorts($this->net['olt'], 1);
        [$odcSplitter, $odcOutputs] = $this->splitter($this->net['odc'], 4, $pon[0]);
        [$odpSplitter] = $this->splitter($this->net['odp'], 8, $odcOutputs[0]);

        $this->get(route('admin.odn.assets.show', $this->net['odc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('splitters.0.id', $odcSplitter->public_id)
                ->where('splitters.0.ports.0.feeds_splitter_id', $odpSplitter->public_id)
                ->where('splitters.0.ports.1.feeds_splitter_id', null)
                ->where('splitters.0.input.port_id', $pon[0]->public_id)
                ->where('splitters.0.input.label', 'OLT-A1 · PON 1')
                ->where('splitters.0.ports.2.feeds_splitter_id', null));
        $freeLabels = array_column($this->inputPortOptions('A1'), 'label');
        $this->assertNotContains('ODC-A1 · 1:4 · keluaran 1', $freeLabels, 'an output that feeds a splitter is not offered again');
        $this->assertContains('ODC-A1 · 1:4 · keluaran 2', $freeLabels);
    }

    public function test_pon_subscriber_counts_sum_through_two_splitter_levels(): void
    {
        $pon = $this->ponPorts($this->net['olt'], 2);
        [, $odcOutputs] = $this->splitter($this->net['odc'], 4, $pon[0]);
        $secondSite = $this->makeLocation($this->company, 'SITE-A12', 'site', $this->net['rack']);
        $secondOdp = $this->odpAt($this->company, $secondSite, 'ODP-A12');
        [, $firstOdpOutputs] = $this->splitter($this->net['odp'], 8, $odcOutputs[0]);
        [, $secondOdpOutputs] = $this->splitter($secondOdp, 8, $odcOutputs[1]);
        $this->dropAll($firstOdpOutputs, 3);
        $this->dropAll($secondOdpOutputs, 2);

        $counts = OdnOccupancyQuery::subscribersBehind([$pon[0]->id, $pon[1]->id]);

        $this->assertSame([$pon[0]->id => 5, $pon[1]->id => 0], $counts);
    }

    public function test_a_splitter_straight_on_the_pon_port_counts_too(): void
    {
        $pon = $this->ponPorts($this->net['olt'], 1);
        [, $outputs] = $this->splitter($this->net['odp'], 4, $pon[0]);
        $this->dropAll($outputs, 4);

        $this->assertSame([$pon[0]->id => 4], OdnOccupancyQuery::subscribersBehind([$pon[0]->id]));
    }

    public function test_the_pon_port_list_shows_subscribers_olt_code_and_number(): void
    {
        $pon = $this->ponPorts($this->net['olt'], 3);
        [, $outputs] = $this->splitter($this->net['odp'], 4, $pon[1]);
        $this->dropAll($outputs, 2);

        $page = OdnOccupancyQuery::ponPorts([])->items();

        $this->assertSame([1, 2, 3], array_column($page, 'number'));
        $this->assertSame([0, 2, 0], array_column($page, 'subscribers'));
        $this->assertSame(['OLT-A1', 'OLT-A1', 'OLT-A1'], array_column($page, 'olt_code'));
        $this->assertSame($this->net['olt']->public_id, $page[0]['olt_asset_id']);
    }

    public function test_the_unmapped_list_shows_active_subscriptions_without_a_drop_and_hides_the_rest(): void
    {
        [, $outputs] = $this->splitter($this->net['odp'], 4);
        $unmapped = $this->makeSubscription($this->company, 'active', $this->net['pop']);
        $mapped = $this->makeSubscription($this->company, 'active', $this->net['pop']);
        $terminated = $this->makeSubscription($this->company, 'terminated', $this->net['pop']);
        $pending = $this->makeSubscription($this->company, 'pending', $this->net['pop']);
        OdnService::assignDrop($outputs[0], $mapped);

        $items = OdnOccupancyQuery::unmapped([])->items();

        $this->assertSame([$unmapped->public_id], array_column($items, 'subscription_id'));
        $this->assertSame($unmapped->code, $items[0]['code']);
        $this->assertSame($unmapped->customer->name, $items[0]['customer_name']);
        $this->assertSame('POP-A', $items[0]['serving_pop_code']);
        $this->assertSame('active', $items[0]['status']);
        $this->assertNotContains($terminated->public_id, array_column($items, 'subscription_id'));
        $this->assertNotContains($pending->public_id, array_column($items, 'subscription_id'));
    }

    public function test_a_subscription_leaves_the_unmapped_list_when_its_drop_is_recorded_and_returns_when_released(): void
    {
        [, $outputs] = $this->splitter($this->net['odp'], 4);
        $subscription = $this->makeSubscription($this->company, 'active');

        $this->assertSame([$subscription->public_id], array_column(OdnOccupancyQuery::unmapped([])->items(), 'subscription_id'));
        OdnService::assignDrop($outputs[0], $subscription);
        $this->assertSame([], OdnOccupancyQuery::unmapped([])->items());
        OdnService::releaseDrop($outputs[0]);
        $this->assertSame([$subscription->public_id], array_column(OdnOccupancyQuery::unmapped([])->items(), 'subscription_id'));
    }

    public function test_drop_port_options_list_only_free_ports_of_odps_under_the_pop(): void
    {
        [, $outputs] = $this->splitter($this->net['odp'], 4);
        $this->dropAll($outputs, 1);
        $this->splitter($this->net['odp'], 2, $outputs[1]);
        $this->splitter($this->net['odc'], 4);

        $options = $this->dropPortOptions($this->net['pop']->id);

        $this->assertSame(['ODP-A11 · 1:4 · port 3', 'ODP-A11 · 1:4 · port 4', 'ODP-A11 · 1:2 · port 1', 'ODP-A11 · 1:2 · port 2'], array_column($options, 'label'));
        $this->assertSame(['POP-A > RACK-A1 > SITE-A11'], array_values(array_unique(array_column($options, 'description'))));
        $this->assertSame(['value', 'label', 'description', 'odp_name', 'free_ports', 'total_ports', 'lat', 'lng'], array_keys($options[0]));
    }

    public function test_drop_port_options_narrow_to_what_was_typed(): void
    {
        [, $outputs] = $this->splitter($this->net['odp'], 4, null, 'Tray 9');
        $second = $this->odpAt($this->company, $this->makeLocation($this->company, 'SITE-A12', 'site', $this->net['rack']), 'ODP-A12');
        $this->splitter($second, 2);

        $this->assertCount(6, $this->dropPortOptions($this->net['pop']->id, 'ODP'));
        $this->assertSame(['ODP-A12 · 1:2 · port 1', 'ODP-A12 · 1:2 · port 2'], array_column($this->dropPortOptions($this->net['pop']->id, 'A12'), 'label'));
        $this->assertSame(array_column($outputs, 'public_id'), array_column($this->dropPortOptions($this->net['pop']->id, 'Tray 9'), 'value'), 'the label of the splitter is searched too');
        $this->assertSame([], $this->dropPortOptions($this->net['pop']->id, 'A'), 'one character is not a search');
    }

    public function test_a_full_odp_and_an_odp_under_another_pop_are_not_offered(): void
    {
        [, $fullOutputs] = $this->splitter($this->net['odp'], 2);
        $this->dropAll($fullOutputs, 2);
        $other = $this->topology($this->company, 'B');
        $this->splitter($other['odp'], 4);
        $atPop = $this->makeAsset($this->company, 'odp', $this->net['pop'], ['code' => 'ODP-AT-POP']);
        $this->splitter($atPop, 4);

        $this->assertSame([], $this->dropPortOptions($this->net['pop']->id), 'full ODPs, other POPs and an ODP at the POP itself are left out');
        $this->assertCount(4, $this->dropPortOptions($other['pop']->id));
    }

    public function test_drop_port_options_for_a_missing_pop_are_empty(): void
    {
        $this->splitter($this->net['odp'], 4);
        $foreign = $this->topology($this->otherCompany(), 'X');

        $this->assertSame([], $this->dropPortOptions(null));
        $this->assertSame([], $this->dropPortOptions(0));
        $this->assertSame([], $this->dropPortOptions(987654));
        $this->assertSame([], $this->dropPortOptions($foreign['pop']->id), 'a POP of another company is no POP here');
    }

    public function test_the_occupancy_never_includes_another_companys_ports(): void
    {
        $other = $this->otherCompany();
        $foreign = $this->topology($other, 'X');
        $foreignOutputs = $this->inCompany($other, fn () => $this->splitter($foreign['odp'], 8)[1]);
        $this->inCompany($other, fn () => OdnService::assignDrop($foreignOutputs[0], $this->makeSubscription($other)));
        $this->splitter($this->net['odp'], 4);

        $this->assertSame([$this->net['odp']->public_id], array_column(OdnOccupancyQuery::odps([])->items(), 'asset_id'));
        $this->assertSame([], OdnOccupancyQuery::ponPorts([])->items());
        $this->assertCount(4, $this->dropPortOptions($this->net['pop']->id));
    }

    public function test_the_index_page_carries_the_three_tabs_filters_and_pops(): void
    {
        $pon = $this->ponPorts($this->net['olt'], 2);
        [, $outputs] = $this->splitter($this->net['odp'], 4, $pon[0]);
        $this->dropAll($outputs, 4);
        $second = $this->odpAt($this->company, $this->makeLocation($this->company, 'SITE-A12', 'site', $this->net['rack']), 'ODP-A12');
        $this->splitter($second, 8);
        $unmapped = $this->makeSubscription($this->company, 'active', $this->net['pop']);

        $this->get(route('admin.odn.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Odn/Index', false)
                ->has('odps.data', 2)
                ->where('odps.data.0.code', 'ODP-A11')
                ->where('odps.data.0.percent', 100)
                ->where('odps.data.1.code', 'ODP-A12')
                ->where('odps.data.1.percent', 0)
                ->where('odps.meta.per_page', 25)
                ->has('ponPorts.data', 2)
                ->where('ponPorts.data.0.subscribers', 4)
                ->where('ponPorts.data.1.subscribers', 0)
                ->has('unmapped.data', 1)
                ->where('unmapped.data.0.subscription_id', $unmapped->public_id)
                ->where('filters', ['pop_id' => null, 'min_percent' => null, 'search' => null])
                ->has('pops', 1)
                ->where('pops.0.code', 'POP-A'));
    }

    public function test_the_index_filters_by_pop_minimum_percent_and_search(): void
    {
        [, $outputs] = $this->splitter($this->net['odp'], 4);
        $this->dropAll($outputs, 4);
        $second = $this->odpAt($this->company, $this->makeLocation($this->company, 'SITE-A12', 'site', $this->net['rack']), 'ODP-A12');
        $this->splitter($second, 8);
        $other = $this->topology($this->company, 'B');
        $this->splitter($other['odp'], 4);

        $codes = fn (array $query): array => collect($this->get(route('admin.odn.index', $query))->viewData('page')['props']['odps']['data'] ?? [])->pluck('code')->all();

        $this->assertSame(['ODP-A11', 'ODP-A12', 'ODP-B11'], $codes([]));
        $this->assertSame(['ODP-A11', 'ODP-A12'], $codes(['pop_id' => $this->net['pop']->public_id]));
        $this->assertSame(['ODP-B11'], $codes(['pop_id' => $other['pop']->public_id]));
        $this->assertSame(['ODP-A11'], $codes(['min_percent' => 100]), 'full ODPs only');
        $this->assertSame(['ODP-A11'], $codes(['min_percent' => 50]));
        $this->assertSame(['ODP-A12'], $codes(['search' => 'A12']));
        $this->assertSame([], $codes(['pop_id' => 987654]));
    }

    public function test_an_array_search_is_ignored_instead_of_failing(): void
    {
        $this->splitter($this->net['odp'], 4);

        $this->get(route('admin.odn.index', ['search' => ['A11']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('odps.data.0.code', 'ODP-A11'));
    }

    public function test_the_asset_page_for_an_olt_lists_pon_ports_with_subscribers(): void
    {
        $pon = $this->ponPorts($this->net['olt'], 2);
        [$splitter, $outputs] = $this->splitter($this->net['odp'], 4, $pon[0]);
        $this->dropAll($outputs, 2);

        $this->get(route('admin.odn.assets.show', $this->net['olt']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Odn/Asset', false)
                ->where('asset.code', 'OLT-A1')
                ->where('asset.asset_type', 'olt')
                ->where('asset.location_path', 'POP-A')
                ->has('ponPorts', 2)
                ->where('ponPorts.0.id', $pon[0]->public_id)
                ->where('ponPorts.0.number', 1)
                ->where('ponPorts.0.splitter_id', $splitter->public_id)
                ->where('ponPorts.0.subscribers', 2)
                ->where('ponPorts.1.splitter_id', null)
                ->where('ponPorts.1.subscribers', 0)
                ->where('splitters', [])
                ->missing('inputPortOptions')
                ->where('can.manage', true)
                ->where('can.assign', true));
    }

    public function test_the_asset_page_for_an_odp_lists_splitters_with_holders(): void
    {
        $pon = $this->ponPorts($this->net['olt'], 1);
        [$splitter, $outputs] = $this->splitter($this->net['odp'], 4, $pon[0], 'Tray 1');
        [$holder] = $this->dropAll($outputs, 1);

        $this->get(route('admin.odn.assets.show', $this->net['odp']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('asset.asset_type', 'odp')
                ->where('ponPorts', [])
                ->has('splitters', 1)
                ->where('splitters.0.id', $splitter->public_id)
                ->where('splitters.0.label', 'Tray 1')
                ->where('splitters.0.outputs', 4)
                ->where('splitters.0.input.label', 'OLT-A1 · PON 1')
                ->has('splitters.0.ports', 4)
                ->where('splitters.0.ports.0.number', 1)
                ->where('splitters.0.ports.0.subscription.id', $holder->public_id)
                ->where('splitters.0.ports.0.subscription.code', $holder->code)
                ->where('splitters.0.ports.0.subscription.customer_name', $holder->customer->name)
                ->where('splitters.0.ports.1.subscription', null)
                ->where('splitters.0.ports.1.feeds_splitter_id', null));
    }

    public function test_the_asset_page_is_404_for_other_asset_types_and_other_companies(): void
    {
        $router = $this->makeAsset($this->company, 'router');
        $foreign = $this->topology($this->otherCompany(), 'X');

        $this->get(route('admin.odn.assets.show', $router))->assertNotFound();
        $this->get(route('admin.odn.assets.show', $foreign['odp']))->assertNotFound();
        $this->get(route('admin.odn.assets.show', $this->net['olt']))->assertOk();
        $this->get(route('admin.odn.assets.show', $this->net['odc']))->assertOk();
        $this->get(route('admin.odn.assets.show', $this->net['odp']))->assertOk();
        $this->assertSame(0, OdnPort::query()->count());
    }

    public function test_input_options_offer_free_pon_ports_first_then_odc_then_odp_outputs(): void
    {
        $pon = $this->ponPorts($this->net['olt'], 2);
        $this->splitter($this->net['odc'], 2, $pon[0]);
        $this->splitter($this->net['odp'], 2);

        $labels = array_column($this->inputPortOptions('A1'), 'label');

        $this->assertSame(['OLT-A1 · PON 2', 'ODC-A1 · 1:2 · keluaran 1', 'ODC-A1 · 1:2 · keluaran 2', 'ODP-A11 · 1:2 · keluaran 1', 'ODP-A11 · 1:2 · keluaran 2'], $labels);
    }

    public function test_a_splitter_label_replaces_the_ratio_in_port_labels(): void
    {
        $this->splitter($this->net['odc'], 2, null, 'Tray 7');

        $this->assertSame(['ODC-A1 · Tray 7 · keluaran 1', 'ODC-A1 · Tray 7 · keluaran 2'], array_column($this->inputPortOptions('ODC'), 'label'));
    }

    public function test_input_options_come_on_a_search_only_and_never_hold_another_companys_ports(): void
    {
        $this->splitter($this->net['odc'], 2);
        $other = $this->otherCompany();
        $foreign = $this->topology($other, 'X');
        $this->inCompany($other, fn () => $this->splitter($foreign['odc'], 4));

        $this->assertSame([], $this->inputPortOptions('A'), 'one character is not a search');
        $this->assertSame([], $this->inputPortOptions('nothing-like-this'));
        $this->assertSame(['ODC-A1 · 1:2 · keluaran 1', 'ODC-A1 · 1:2 · keluaran 2'], array_column($this->inputPortOptions('ODC'), 'label'), 'the ODC of the other company is not found');
        $this->get(route('admin.odn.assets.show', $this->net['odc']))->assertInertia(fn (Assert $page) => $page->missing('inputPortOptions'));
        $this->get(route('admin.odn.assets.show', [$this->net['odc'], 'inputPort_search' => 'ODC']), $this->partialReload('Admin/Odn/Asset', 'inputPortOptions'))
            ->assertOk()
            ->assertJsonCount(2, 'props.inputPortOptions');
    }
}
