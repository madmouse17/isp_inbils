<?php

namespace Tests\Feature\NetworkAsset;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\NetworkAsset\Queries\OdnOccupancyQuery;
use Modules\NetworkAsset\Services\OdnService;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;
use Tests\Traits\BuildsOdnTopology;

/**
 * F4-U03: a drop port the registration form offers says which ODP it is on (its name, how many of its outputs are free of
 * how many it has) and where that ODP stands, so the page can list the nearest first.
 */
class OdpOptionsTest extends TestCase
{
    use BuildsIpamOdnFixtures;
    use BuildsOdnTopology;
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $net;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootIpamOdnWorld();
        $this->net = $this->topology($this->company);
    }

    public function test_an_option_names_its_odp_counts_its_free_outputs_and_says_where_it_stands(): void
    {
        $this->net['site']->update(['lat' => -6.2, 'lng' => 106.8]);
        [, $outputs] = $this->splitter($this->net['odp'], 8);
        OdnService::assignDrop($outputs[0], $this->makeSubscription($this->company));
        OdnService::assignDrop($outputs[1], $this->makeSubscription($this->company));
        $this->splitter($this->net['odc'], 4, $outputs[2]);

        $options = $this->offered();

        // Eight outputs: two held by drops and one feeding another splitter are not free, so five are offered.
        $this->assertSame(array_map(fn ($port) => $port->public_id, array_slice($outputs, 3)), array_column($options, 'value'));
        foreach ($options as $option) {
            $this->assertSame($this->net['odp']->name, $option['odp_name']);
            $this->assertSame([5, 8, -6.2, 106.8], [$option['free_ports'], $option['total_ports'], $option['lat'], $option['lng']]);
        }
    }

    public function test_an_odp_whose_location_has_no_coordinates_says_so(): void
    {
        $this->splitter($this->net['odp'], 4);

        $this->assertSame([null, null], [$this->offered()[0]['lat'], $this->offered()[0]['lng']]);
    }

    public function test_two_odps_are_told_apart(): void
    {
        $this->net['site']->update(['lat' => -6.2, 'lng' => 106.8]);
        $this->splitter($this->net['odp'], 8);
        $second = $this->odpAt($this->company, $this->makeLocation($this->company, 'SITE-A12', 'site', $this->net['rack']), 'ODP-A12');
        $this->splitter($second, 4);

        $byOdp = [];
        foreach ($this->offered() as $option) {
            $byOdp[explode(' · ', $option['label'])[0]][] = [$option['odp_name'], $option['free_ports'], $option['total_ports'], $option['lat']];
        }

        $this->assertSame(['ODP-A11', 'ODP-A12'], array_keys($byOdp));
        $this->assertSame([$this->net['odp']->name, 8, 8, -6.2], $byOdp['ODP-A11'][0]);
        $this->assertSame([$second->name, 4, 4, null], $byOdp['ODP-A12'][0]);
    }

    public function test_a_full_odp_offers_nothing(): void
    {
        [, $outputs] = $this->splitter($this->net['odp'], 2);
        foreach ($outputs as $output) {
            OdnService::assignDrop($output, $this->makeSubscription($this->company));
        }

        $this->assertSame([], $this->offered());
    }

    public function test_the_details_cost_the_same_however_many_ports_are_listed(): void
    {
        [, $outputs] = $this->splitter($this->net['odp'], 8);

        $many = $this->queriesFor(fn () => $this->assertCount(8, $this->offered()));
        foreach (array_slice($outputs, 0, 5) as $output) {
            OdnService::assignDrop($output, $this->makeSubscription($this->company));
        }
        $few = $this->queriesFor(fn () => $this->assertCount(3, $this->offered()));

        $this->assertSame($many, $few);
    }

    /** @return list<array<string, mixed>> */
    private function offered(): array
    {
        return (OdnOccupancyQuery::dropPortOptions(Request::create('/', 'GET', ['dropPort_search' => 'ODP']), $this->net['pop']->id))();
    }

    private function queriesFor(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
