<?php

namespace Tests\Feature\Ipam;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Ipam\Models\IpPrefix;
use Modules\Ipam\Queries\IpPrefixUtilisationQuery;
use Modules\Ipam\Services\IpAssignmentService;
use Modules\Ipam\Services\IpPrefixService;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;

class IpPrefixUtilisationTest extends TestCase
{
    use BuildsIpamOdnFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootIpamOdnWorld();
    }

    private function prefix(string $cidr, string $usage = 'customer_static'): IpPrefix
    {
        return IpPrefixService::create(['cidr' => $cidr, 'usage' => $usage]);
    }

    /** @return array<string, mixed> */
    private function row(IpPrefix $prefix): array
    {
        return IpPrefixUtilisationQuery::row($prefix);
    }

    public function test_a_slash_24_pool_with_three_assigned_and_one_reserved(): void
    {
        $pool = $this->prefix('10.20.0.0/24');
        foreach ([10, 11, 12] as $host) {
            IpAssignmentService::assignToSubscription($this->makeSubscription($this->company), $pool, "10.20.0.{$host}");
        }
        IpAssignmentService::reserve($pool, '10.20.0.1', 'gateway');

        $row = $this->row($pool);

        $this->assertSame('256', $row['size']);
        $this->assertSame('254', $row['usable']);
        $this->assertSame(3, $row['assigned']);
        $this->assertSame(1, $row['reserved']);
        $this->assertSame('250', $row['free']);
        $this->assertSame(1.57, $row['percent']);
    }

    public function test_usable_follows_the_network_and_broadcast_rule_for_small_blocks(): void
    {
        $this->assertSame('2', $this->row($this->prefix('10.20.1.0/30'))['usable']);
        $this->assertSame('2', $this->row($this->prefix('10.20.1.4/31'))['usable'], '/31 keeps both addresses');
        $this->assertSame('1', $this->row($this->prefix('10.20.1.6/32'))['usable']);
    }

    public function test_a_full_pool_is_one_hundred_percent_with_nothing_free(): void
    {
        $pool = $this->prefix('10.20.2.0/30');
        IpAssignmentService::reserve($pool, '10.20.2.1');
        IpAssignmentService::assignToSubscription($this->makeSubscription($this->company), $pool);

        $row = $this->row($pool);

        $this->assertSame('0', $row['free']);
        $this->assertSame(100.0, $row['percent']);
    }

    public function test_a_dynamic_pool_shows_its_size_only(): void
    {
        $row = $this->row($this->prefix('10.30.0.0/22', 'customer_dynamic'));

        $this->assertSame('1024', $row['size']);
        $this->assertNull($row['usable']);
        $this->assertNull($row['free']);
        $this->assertNull($row['percent']);
    }

    public function test_an_infrastructure_prefix_is_measured_like_a_pool(): void
    {
        $prefix = $this->prefix('10.40.0.0/29', 'infrastructure');
        IpAssignmentService::reserve($prefix, '10.40.0.1');

        $row = $this->row($prefix);

        $this->assertSame('6', $row['usable']);
        $this->assertSame('5', $row['free']);
        $this->assertSame(16.67, $row['percent']);
    }

    public function test_an_ipv6_prefix_reports_its_exact_size_and_no_usable_figures(): void
    {
        $row = $this->row($this->prefix('2001:db8::/48'));

        $this->assertSame('1208925819614629174706176', $row['size']);
        $this->assertNull($row['usable']);
        $this->assertNull($row['free']);
        $this->assertNull($row['percent']);
    }

    public function test_a_container_shows_how_much_of_it_its_children_cover(): void
    {
        $container = $this->prefix('10.50.0.0/16', 'container');
        $this->prefix('10.50.0.0/24');
        $this->prefix('10.50.1.0/25');

        $row = $this->row($container);

        $this->assertSame('65536', $row['size']);
        $this->assertNull($row['usable']);
        $this->assertSame(0.59, $row['percent']);
        $this->assertSame(0.0, $this->row($this->prefix('10.60.0.0/16', 'container'))['percent']);
    }

    public function test_a_container_counts_the_addresses_of_the_prefixes_nested_in_it(): void
    {
        $container = $this->prefix('10.70.0.0/16', 'container');
        $pool = $this->prefix('10.70.1.0/24');
        IpAssignmentService::reserve($pool, '10.70.1.9');
        IpAssignmentService::reserve($container, '10.70.200.1');

        $this->assertSame(2, $this->row($container)['reserved']);
        $this->assertSame(1, $this->row($pool)['reserved']);
    }

    public function test_only_this_companys_addresses_are_counted(): void
    {
        $pool = $this->prefix('10.80.0.0/24');
        IpAssignmentService::reserve($pool, '10.80.0.5');
        $other = $this->otherCompany();
        $this->inCompany($other, function () use ($other) {
            $theirs = IpPrefixService::create(['cidr' => '10.80.0.0/24', 'usage' => 'customer_static']);
            IpAssignmentService::reserve($theirs, '10.80.0.6');
            IpAssignmentService::reserve($theirs, '10.80.0.7');

            return $other;
        });

        $this->assertSame(1, $this->row($pool)['reserved']);
    }

    public function test_rows_come_back_family_then_first_address_with_depth(): void
    {
        $this->prefix('2001:db8::/32', 'container');
        $this->prefix('10.9.0.0/16', 'container');
        $this->prefix('10.9.4.0/24');
        $this->prefix('10.1.0.0/24');

        $rows = IpPrefixUtilisationQuery::rows();

        $this->assertSame(['10.1.0.0/24', '10.9.0.0/16', '10.9.4.0/24', '2001:db8::/32'], array_column($rows, 'cidr'));
        $this->assertSame([0, 0, 1, 0], array_column($rows, 'depth'));
        $this->assertSame([null, null, $rows[1]['id'], null], array_column($rows, 'parent_id'));
    }

    public function test_static_pools_offer_company_wide_pools_and_those_of_the_serving_pop(): void
    {
        $popA = $this->makeLocation($this->company, 'POP-A', 'pop');
        $popB = $this->makeLocation($this->company, 'POP-B', 'pop');
        IpPrefixService::create(['cidr' => '10.90.0.0/24', 'usage' => 'customer_static']);
        IpPrefixService::create(['cidr' => '10.90.1.0/24', 'usage' => 'customer_static', 'location_id' => $popA->id]);
        IpPrefixService::create(['cidr' => '10.90.2.0/24', 'usage' => 'customer_static', 'location_id' => $popB->id]);
        IpPrefixService::create(['cidr' => '10.90.3.0/24', 'usage' => 'customer_dynamic']);
        IpPrefixService::create(['cidr' => '2001:db8:90::/48', 'usage' => 'customer_static']);

        $forA = IpPrefixUtilisationQuery::staticPools($popA->id);
        $none = IpPrefixUtilisationQuery::staticPools(null);

        $this->assertSame(['10.90.0.0/24', '10.90.1.0/24', '2001:db8:90::/48'], array_column($forA, 'cidr'));
        $this->assertSame(['254', '254', null], array_column($forA, 'free'));
        $this->assertSame(['10.90.0.0/24', '2001:db8:90::/48'], array_column($none, 'cidr'));
    }
}
