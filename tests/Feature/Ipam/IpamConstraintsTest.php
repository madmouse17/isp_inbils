<?php

namespace Tests\Feature\Ipam;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Ipam\Models\IpAddress;
use Modules\Ipam\Models\IpPrefix;
use Modules\Ipam\Models\Vlan;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;

/** What MySQL itself refuses, whatever the services do. */
class IpamConstraintsTest extends TestCase
{
    use BuildsIpamOdnFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootIpamOdnWorld();
    }

    public function test_an_address_is_unique_per_company_but_not_across_companies(): void
    {
        IpAddress::factory()->address('10.1.0.1')->create();
        $other = $this->otherCompany();

        IpAddress::factory()->address('10.1.0.1')->create(['company_id' => $other->id]);

        $this->expectException(UniqueConstraintViolationException::class);
        IpAddress::factory()->address('10.1.0.1')->create();
    }

    public function test_an_address_has_one_row_whatever_its_spelling(): void
    {
        IpAddress::factory()->address('2001:db8::1')->create();

        $this->expectException(UniqueConstraintViolationException::class);
        IpAddress::factory()->address('2001:DB8:0:0:0:0:0:1')->create();
    }

    public function test_a_subscription_holds_one_address_per_family(): void
    {
        $subscription = $this->makeSubscription($this->company);
        IpAddress::factory()->address('10.1.0.2')->forSubscription($subscription->id)->create();
        IpAddress::factory()->address('2001:db8::2')->forSubscription($subscription->id)->create();

        $this->expectException(UniqueConstraintViolationException::class);
        IpAddress::factory()->address('10.1.0.3')->forSubscription($subscription->id)->create();
    }

    public function test_a_prefix_cidr_is_unique_per_company(): void
    {
        IpPrefix::factory()->cidr('10.2.0.0/24')->create();
        $other = $this->otherCompany();
        IpPrefix::factory()->cidr('10.2.0.0/24')->create(['company_id' => $other->id]);

        $this->expectException(UniqueConstraintViolationException::class);
        IpPrefix::factory()->cidr('10.2.0.0/24')->create();
    }

    public function test_a_vid_is_unique_per_pop(): void
    {
        $pop = $this->makeLocation($this->company, 'POP-A', 'pop');
        Vlan::factory()->create(['vid' => 10, 'location_id' => $pop->id]);

        $this->expectException(UniqueConstraintViolationException::class);
        Vlan::factory()->create(['vid' => 10, 'location_id' => $pop->id]);
    }

    public function test_a_vid_outside_the_vlan_range_is_refused_by_the_database(): void
    {
        $this->expectException(QueryException::class);
        Vlan::factory()->create(['vid' => 4095]);
    }

    public function test_an_unknown_ip_family_is_refused_by_the_database(): void
    {
        $this->expectException(QueryException::class);
        IpAddress::factory()->create(['family' => 5]);
    }

    public function test_an_address_cannot_have_two_holders_nor_a_reservation_a_holder(): void
    {
        $subscription = $this->makeSubscription($this->company);
        $asset = $this->makeAsset($this->company, 'router');

        try {
            IpAddress::factory()->address('10.3.0.1')->forSubscription($subscription->id)->create(['network_asset_id' => $asset->id]);
            $this->fail('two holders on one row must be refused');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        try {
            IpAddress::factory()->address('10.3.0.2')->create(['subscription_id' => $subscription->id]);
            $this->fail('a reserved row with a holder must be refused');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(QueryException::class);
        IpAddress::factory()->address('10.3.0.3')->create(['status' => 'assigned']);
    }

    public function test_a_prefix_cannot_be_deleted_while_an_address_points_at_it(): void
    {
        $prefix = IpPrefix::factory()->cidr('10.4.0.0/24')->create();
        IpAddress::factory()->address('10.4.0.9')->create(['ip_prefix_id' => $prefix->id]);

        $this->expectException(QueryException::class);
        $prefix->delete();
    }
}
