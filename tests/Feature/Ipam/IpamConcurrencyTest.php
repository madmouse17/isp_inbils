<?php

namespace Tests\Feature\Ipam;

use App\Models\Core\Company;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Ipam\Models\IpAddress;
use Modules\Ipam\Services\IpAssignmentService;
use Modules\Ipam\Services\IpPrefixService;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;
use Tests\Traits\RunsConcurrentWorkers;

/**
 * Independent processes race for the same address; the unique index and the pool lock must leave exactly
 * one winner. The fixture is committed for the workers and purged afterwards.
 */
class IpamConcurrencyTest extends TestCase
{
    use BuildsIpamOdnFixtures;
    use RefreshDatabase;
    use RunsConcurrentWorkers;

    private const ASSIGN = <<<'PHP'
        $subscription = App\Models\Core\ServiceSubscription::query()->findOrFail($args['subscription_id']);
        $pool = Modules\Ipam\Models\IpPrefix::query()->findOrFail($args['pool_id']);

        return Modules\Ipam\Services\IpAssignmentService::assignToSubscription($subscription, $pool, $args['address'] ?? null)->address;
    PHP;

    public function test_two_automatic_assignments_racing_for_the_last_free_address_have_exactly_one_winner(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $holder = $this->makeSubscription($company);
        $first = $this->makeSubscription($company);
        $second = $this->makeSubscription($company);
        $pool = CompanyService::runAs($company->id, function () use ($holder) {
            $pool = IpPrefixService::create(['cidr' => '10.100.0.0/30', 'usage' => 'customer_static']);
            IpAssignmentService::assignToSubscription($holder, $pool, '10.100.0.1');

            return $pool;
        });
        $this->commitFixture();

        try {
            $results = $this->race([
                ['company_id' => $company->id, 'subscription_id' => $first->id, 'pool_id' => $pool->id],
                ['company_id' => $company->id, 'subscription_id' => $second->id, 'pool_id' => $pool->id],
            ], self::ASSIGN);

            $winners = array_values(array_filter($results, fn (array $result): bool => $result['ok']));
            $losers = array_values(array_filter($results, fn (array $result): bool => ! $result['ok']));

            $this->assertCount(1, $winners, json_encode($results));
            $this->assertCount(1, $losers, json_encode($results));
            $this->assertSame('10.100.0.2', $winners[0]['result']);
            $this->assertSame(422, $losers[0]['status']);
            $this->assertArrayHasKey('ip_prefix_id', $losers[0]['errors']);

            $this->assertSame(2, IpAddress::withoutCompany()->where('company_id', $company->id)->count(), 'the holder and one winner: one new row');
            $this->assertSame(1, IpAddress::withoutCompany()->where('address', '10.100.0.2')->count());
        } finally {
            $this->purgeCompany($company->id);
        }
    }

    public function test_two_typed_assignments_of_one_address_have_exactly_one_winner_and_the_loser_is_told_who_won(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $first = $this->makeSubscription($company);
        $second = $this->makeSubscription($company);
        $pool = CompanyService::runAs($company->id, fn () => IpPrefixService::create(['cidr' => '10.101.0.0/24', 'usage' => 'customer_static']));
        $this->commitFixture();

        try {
            $results = $this->race([
                ['company_id' => $company->id, 'subscription_id' => $first->id, 'pool_id' => $pool->id, 'address' => '10.101.0.50'],
                ['company_id' => $company->id, 'subscription_id' => $second->id, 'pool_id' => $pool->id, 'address' => '10.101.0.50'],
            ], self::ASSIGN);

            $winnerIndex = $results[0]['ok'] ? 0 : 1;
            $loser = $results[1 - $winnerIndex];
            $winnerSubscription = [$first, $second][$winnerIndex];

            $this->assertTrue($results[$winnerIndex]['ok'], json_encode($results));
            $this->assertFalse($loser['ok'], json_encode($results));
            $this->assertSame(422, $loser['status']);
            $this->assertStringContainsString($winnerSubscription->code, $loser['errors']['address'][0]);
            $this->assertSame(1, IpAddress::withoutCompany()->where('address', '10.101.0.50')->count());
            $this->assertSame($winnerSubscription->id, IpAddress::withoutCompany()->where('address', '10.101.0.50')->value('subscription_id'));
        } finally {
            $this->purgeCompany($company->id);
        }
    }

    public function test_an_automatic_assignment_and_an_asset_claim_never_share_the_lowest_address(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $subscription = $this->makeSubscription($company);
        $asset = $this->makeAsset($company, 'router', null, ['ip_address' => '10.102.0.1']);
        $pool = CompanyService::runAs($company->id, fn () => IpPrefixService::create(['cidr' => '10.102.0.0/24', 'usage' => 'customer_static']));
        $this->commitFixture();

        try {
            $results = $this->race([
                ['company_id' => $company->id, 'subscription_id' => $subscription->id, 'pool_id' => $pool->id],
                ['company_id' => $company->id, 'asset_id' => $asset->id],
            ], <<<'PHP'
                if (isset($args['asset_id'])) {
                    Modules\Ipam\Services\IpAssignmentService::syncAssetAddresses(
                        Modules\NetworkAsset\Models\NetworkAsset::query()->findOrFail($args['asset_id'])
                    );

                    return 'asset';
                }

                $subscription = App\Models\Core\ServiceSubscription::query()->findOrFail($args['subscription_id']);
                $pool = Modules\Ipam\Models\IpPrefix::query()->findOrFail($args['pool_id']);

                return Modules\Ipam\Services\IpAssignmentService::assignToSubscription($subscription, $pool)->address;
            PHP);

            $this->assertTrue($results[0]['ok'], json_encode($results));
            $this->assertSame(1, IpAddress::withoutCompany()->where('address', '10.102.0.1')->count(), 'one holder per address');

            if ($results[1]['ok']) {
                $this->assertSame('10.102.0.2', $results[0]['result'], 'the asset got .1, the subscription moved on to the next address');
                $this->assertSame($asset->id, IpAddress::withoutCompany()->where('address', '10.102.0.1')->value('network_asset_id'));
            } else {
                $this->assertSame(422, $results[1]['status']);
                $this->assertSame('10.102.0.1', $results[0]['result']);
                $this->assertSame($subscription->id, IpAddress::withoutCompany()->where('address', '10.102.0.1')->value('subscription_id'));
            }
        } finally {
            $this->purgeCompany($company->id);
        }
    }
}
