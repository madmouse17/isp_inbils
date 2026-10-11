<?php

namespace Tests\Feature\Odn;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\NetworkAsset\Database\Factories\OdnPortFactory;
use Modules\NetworkAsset\Database\Factories\OdnSplitterFactory;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Models\OdnSplitter;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;
use Tests\Traits\BuildsOdnTopology;

/** What MySQL itself refuses, whatever OdnService does. */
class OdnConstraintsTest extends TestCase
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

    private function pon(int $assetId, int $number): OdnPort
    {
        return OdnPortFactory::new()->create(['network_asset_id' => $assetId, 'kind' => 'pon', 'number' => $number]);
    }

    private function splitterRow(int $assetId, int $outputs = 4): OdnSplitter
    {
        return OdnSplitterFactory::new()->create(['network_asset_id' => $assetId, 'outputs' => $outputs]);
    }

    private function outputPort(int $assetId, int $splitterId, int $number, ?int $subscriptionId = null): OdnPort
    {
        return OdnPortFactory::new()->create([
            'network_asset_id' => $assetId,
            'odn_splitter_id' => $splitterId,
            'kind' => 'splitter_out',
            'number' => $number,
            'subscription_id' => $subscriptionId,
        ]);
    }

    public function test_a_pon_number_is_unique_per_olt(): void
    {
        $this->pon($this->net['olt']->id, 1);
        $secondOlt = $this->makeAsset($this->company, 'olt');
        $this->pon($secondOlt->id, 1);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->pon($this->net['olt']->id, 1);
    }

    public function test_an_output_number_is_unique_per_splitter_but_not_across_splitters(): void
    {
        $first = $this->splitterRow($this->net['odp']->id);
        $second = $this->splitterRow($this->net['odp']->id);
        $this->outputPort($this->net['odp']->id, $first->id, 1);
        $this->outputPort($this->net['odp']->id, $second->id, 1);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->outputPort($this->net['odp']->id, $first->id, 1);
    }

    public function test_the_generated_pon_number_follows_the_kind(): void
    {
        $pon = $this->pon($this->net['olt']->id, 7);
        $splitter = $this->splitterRow($this->net['odp']->id);
        $output = $this->outputPort($this->net['odp']->id, $splitter->id, 7);

        $this->assertSame(7, (int) DB::table('odn_ports')->where('id', $pon->id)->value('pon_number'));
        $this->assertNull(DB::table('odn_ports')->where('id', $output->id)->value('pon_number'));
    }

    public function test_a_subscription_holds_at_most_one_port(): void
    {
        $subscription = $this->makeSubscription($this->company);
        $splitter = $this->splitterRow($this->net['odp']->id);
        $this->outputPort($this->net['odp']->id, $splitter->id, 1, $subscription->id);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->outputPort($this->net['odp']->id, $splitter->id, 2, $subscription->id);
    }

    public function test_a_port_can_feed_only_one_splitter(): void
    {
        $pon = $this->pon($this->net['olt']->id, 1);
        $this->splitterRow($this->net['odc']->id)->update(['input_port_id' => $pon->id]);
        $other = $this->splitterRow($this->net['odc']->id);

        $this->expectException(UniqueConstraintViolationException::class);
        $other->update(['input_port_id' => $pon->id]);
    }

    public function test_a_pon_port_belongs_to_no_splitter_and_holds_no_customer_and_an_output_to_exactly_one_splitter(): void
    {
        $splitter = $this->splitterRow($this->net['odp']->id);
        $subscription = $this->makeSubscription($this->company);

        foreach ([
            ['kind' => 'pon', 'number' => 1, 'odn_splitter_id' => $splitter->id],
            ['kind' => 'pon', 'number' => 2, 'subscription_id' => $subscription->id],
            ['kind' => 'splitter_out', 'number' => 3, 'odn_splitter_id' => null],
        ] as $row) {
            try {
                OdnPortFactory::new()->create(['network_asset_id' => $this->net['olt']->id, ...$row]);
                $this->fail('the row must be refused: '.json_encode($row));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_splitter_ratio_outside_the_supported_set_is_refused(): void
    {
        $this->expectException(QueryException::class);
        $this->splitterRow($this->net['odp']->id, 3);
    }

    public function test_a_port_that_is_still_an_input_cannot_be_deleted(): void
    {
        $pon = $this->pon($this->net['olt']->id, 1);
        $this->splitterRow($this->net['odc']->id)->update(['input_port_id' => $pon->id]);

        $this->expectException(QueryException::class);
        $pon->delete();
    }

    public function test_an_asset_with_ports_cannot_be_hard_deleted(): void
    {
        $this->pon($this->net['olt']->id, 1);

        $this->expectException(QueryException::class);
        DB::table('network_assets')->where('id', $this->net['olt']->id)->delete();
    }
}
