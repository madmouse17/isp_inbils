<?php

namespace Tests\Feature\Ipam;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ipam\Models\IpAddress;
use Modules\Ipam\Models\IpPrefix;
use Modules\Ipam\Services\IpAssignmentService;
use Modules\Ipam\Services\IpPrefixService;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;
use Tests\Traits\WithoutAppOnlyRedirect;

/** The assign dialog suggests what an automatic assignment would take, and the pool's mask and gateway. */
class IpamSuggestionPropsTest extends TestCase
{
    use BuildsIpamOdnFixtures;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->bootIpamOdnWorld(true);
    }

    private function pool(string $cidr): IpPrefix
    {
        return IpPrefixService::create(['cidr' => $cidr, 'usage' => 'customer_static']);
    }

    public function test_peek_returns_the_lowest_free_address_and_allocates_nothing(): void
    {
        $pool = $this->pool('10.50.0.0/24');
        IpAssignmentService::assignToSubscription($this->makeSubscription($this->company), $pool, '10.50.0.1');
        IpAssignmentService::assignToSubscription($this->makeSubscription($this->company), $pool, '10.50.0.3');
        IpAssignmentService::reserve($pool, '10.50.0.2');
        $rows = IpAddress::query()->count();

        $this->assertSame('10.50.0.4', IpAssignmentService::peekLowestFree($pool));
        $this->assertSame('10.50.0.4', IpAssignmentService::peekLowestFree($pool));
        $this->assertSame($rows, IpAddress::query()->count());

        // The hint is what the real assignment then takes.
        $this->assertSame('10.50.0.4', IpAssignmentService::assignToSubscription($this->makeSubscription($this->company), $pool)->address);
    }

    public function test_peek_is_null_for_a_full_pool_ipv6_and_a_pool_bigger_than_a_slash_22(): void
    {
        $full = $this->pool('10.51.0.0/30');
        IpAssignmentService::assignToSubscription($this->makeSubscription($this->company), $full, '10.51.0.1');
        IpAssignmentService::assignToSubscription($this->makeSubscription($this->company), $full, '10.51.0.2');

        $this->assertNull(IpAssignmentService::peekLowestFree($full));
        $this->assertNull(IpAssignmentService::peekLowestFree($this->pool('2001:db8:51::/48')));
        $this->assertNull(IpAssignmentService::peekLowestFree($this->pool('10.64.0.0/20')));
        $this->assertSame('10.65.0.1', IpAssignmentService::peekLowestFree($this->pool('10.65.0.0/22')));
    }

    public function test_the_subscription_page_sends_each_pool_with_suggestion_mask_and_gateway(): void
    {
        $withGateway = $this->pool('10.52.0.0/24');
        IpAssignmentService::reserve($withGateway, '10.52.0.1', 'Gateway');
        $plain = $this->pool('10.53.0.0/29');
        $subscription = $this->makeSubscription($this->company);

        $this->get(route('admin.subscriptions.show', $subscription))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('staticPools', 2)
                ->where('staticPools.0.id', $withGateway->public_id)
                ->where('staticPools.0.prefix_length', 24)
                ->where('staticPools.0.suggested_address', '10.52.0.2')
                ->where('staticPools.0.gateway', '10.52.0.1')
                ->where('staticPools.1.id', $plain->public_id)
                ->where('staticPools.1.prefix_length', 29)
                ->where('staticPools.1.suggested_address', '10.53.0.1')
                ->where('staticPools.1.gateway', null));
    }
}
