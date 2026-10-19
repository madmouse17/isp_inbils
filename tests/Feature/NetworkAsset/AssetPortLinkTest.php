<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\NetworkAsset\Models\AssetPort;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\OdnPort;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsOdnTopology;
use Tests\Traits\BuildsPanelFixtures;
use Tests\Traits\RunsConcurrentWorkers;

/**
 * Patching ports together (spec .scratch/hardware-panel-ui, design §4.6 and §4.7): the flash copy of the three port
 * routes, what counts as a valid target, and the two writers that want one port at once. AssetPanelWriteRulesTest
 * already pins the symmetric write, the audit, self and same-asset links, the other company and the 404 and 403 cases.
 */
class AssetPortLinkTest extends TestCase
{
    use BuildsIpamOdnFixtures;
    use BuildsNetworkFixtures;
    use BuildsOdnTopology;
    use BuildsPanelFixtures;
    use RefreshDatabase;
    use RunsConcurrentWorkers;

    private const LINK = <<<'PHP'
        $port = Modules\NetworkAsset\Models\AssetPort::query()->findOrFail($args['port_id']);
        Modules\NetworkAsset\Services\AssetPanelService::link($port, $args['target_id']);

        return $port->id;
    PHP;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->bootIpamOdnWorld(true);
    }

    private function link(AssetPort $from, AssetPort $to): TestResponse
    {
        return $this->postJson(route('admin.network-assets.ports.link.store', [$from->asset, $from]), ['target_port_id' => $to->public_id]);
    }

    private function ether(NetworkAsset $asset, int $number): AssetPort
    {
        return $this->panelPort($asset, 'ether', $number);
    }

    public function test_linking_unlinking_and_labelling_say_so_in_the_flash(): void
    {
        $a = $this->panelSwitch();
        $b = $this->panelSwitch();

        $this->link($this->ether($a, 1), $this->ether($b, 1))->assertSessionHas('success', 'Port dihubungkan.');
        $this->deleteJson(route('admin.network-assets.ports.link.destroy', [$a, $this->ether($a, 1)]))->assertSessionHas('success', 'Sambungan port dilepas.');
        $this->patchJson(route('admin.network-assets.ports.update', [$a, $this->ether($a, 1)]), ['admin_down' => false])->assertSessionHas('success', 'Port diperbarui.');
    }

    public function test_the_target_must_be_an_asset_port_of_this_company(): void
    {
        $a = $this->panelSwitch();
        $olt = $this->panelOlt(16, 0);
        $pon = OdnPort::query()->where('network_asset_id', $olt->id)->where('kind', 'pon')->firstOrFail();
        $source = $this->ether($a, 1);
        $link = fn (mixed $target): TestResponse => $this->postJson(route('admin.network-assets.ports.link.store', [$a, $source]), ['target_port_id' => $target]);

        $link($pon->public_id)->assertStatus(422)->assertJsonValidationErrors('target_port_id');
        $link('not-an-id')->assertStatus(422)->assertJsonValidationErrors('target_port_id');
        $link(null)->assertStatus(422)->assertJsonValidationErrors('target_port_id');
        $this->postJson(route('admin.network-assets.ports.link.store', [$a, $source]), [])->assertStatus(422)->assertJsonValidationErrors('target_port_id');

        $this->assertSame(0, AssetPort::query()->whereNotNull('linked_port_id')->count());
    }

    public function test_the_ports_of_a_soft_deleted_asset_are_no_link_target(): void
    {
        $a = $this->panelSwitch();
        $gone = $this->panelSwitch();
        $stray = $this->ether($gone, 1);
        $gone->delete();

        $this->link($this->ether($a, 1), $stray)->assertStatus(422)->assertJsonValidationErrors('target_port_id');

        $this->assertNull($stray->fresh()->linked_port_id);
        $this->assertNull($this->ether($a, 1)->linked_port_id);
    }

    public function test_an_asset_that_has_port_rows_cannot_be_deleted_even_when_every_port_is_free(): void
    {
        $a = $this->panelSwitch();

        $this->deleteJson(route('admin.network-assets.destroy', $a))->assertStatus(422)->assertJsonValidationErrors('lifecycle');

        $this->assertNotNull($a->fresh());
    }

    public function test_label_and_note_can_be_cleared_and_are_bounded(): void
    {
        $a = $this->panelSwitch();
        $port = $this->ether($a, 2);
        $url = route('admin.network-assets.ports.update', [$a, $port]);
        $this->patchJson($url, ['label' => 'ke ODC', 'note' => 'VLAN 100', 'admin_down' => true])->assertRedirect();

        $this->patchJson($url, ['label' => null, 'note' => null, 'admin_down' => false])->assertRedirect();

        $port->refresh();
        $this->assertSame([null, null, false], [$port->label, $port->note, $port->admin_down]);
        $this->patchJson($url, ['note' => str_repeat('n', 256), 'admin_down' => false])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->patchJson($url, ['note' => str_repeat('n', 255), 'label' => str_repeat('l', 64), 'admin_down' => false])->assertRedirect();
    }

    public function test_a_link_stays_symmetric_when_either_side_is_relabelled_or_taken_down(): void
    {
        $a = $this->panelSwitch();
        $b = $this->panelSwitch();
        $this->link($this->ether($a, 1), $this->ether($b, 1))->assertRedirect();

        $this->patchJson(route('admin.network-assets.ports.update', [$b, $this->ether($b, 1)]), ['label' => 'ke A', 'admin_down' => true])->assertRedirect();

        $this->assertSame($this->ether($b, 1)->id, $this->ether($a, 1)->linked_port_id);
        $this->assertSame($this->ether($a, 1)->id, $this->ether($b, 1)->linked_port_id);
        $this->assertSame(1, Activity::query()->where('description', 'port_linked')->count());
    }

    public function test_two_links_racing_for_one_port_leave_exactly_one(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        [$a, $b, $c] = [$this->panelSwitch(company: $company), $this->panelSwitch(company: $company), $this->panelSwitch(company: $company)];
        $contested = $this->panelPort($b, 'ether', 1);
        $first = $this->panelPort($a, 'ether', 1);
        $second = $this->panelPort($c, 'ether', 1);
        $this->commitFixture();

        try {
            $results = $this->race([
                ['company_id' => $company->id, 'port_id' => $first->id, 'target_id' => $contested->id],
                ['company_id' => $company->id, 'port_id' => $second->id, 'target_id' => $contested->id],
            ], self::LINK);

            $winners = array_values(array_filter($results, fn (array $result): bool => $result['ok']));
            $losers = array_values(array_filter($results, fn (array $result): bool => ! $result['ok']));
            $this->assertCount(1, $winners, json_encode($results));
            $this->assertCount(1, $losers, json_encode($results));
            $this->assertSame(422, $losers[0]['status']);
            $this->assertArrayHasKey('target_port_id', $losers[0]['errors']);

            $this->assertSame(2, AssetPort::withoutCompany()->where('company_id', $company->id)->whereNotNull('linked_port_id')->count(), 'one pair, both rows');
            $this->assertContains($this->panelPort($b, 'ether', 1)->linked_port_id, [$first->id, $second->id]);
            $this->assertSame($contested->id, AssetPort::withoutCompany()->findOrFail($winners[0]['result'])->linked_port_id);
        } finally {
            $this->purgeCompany($company->id);
            $this->purgeCompany($this->company->id);
        }
    }

    public function test_two_links_between_the_same_two_ports_in_opposite_directions_leave_one_pair_and_no_deadlock(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $a = $this->panelPort($this->panelSwitch(company: $company), 'ether', 1);
        $b = $this->panelPort($this->panelSwitch(company: $company), 'ether', 1);
        $this->commitFixture();

        try {
            $results = $this->race([
                ['company_id' => $company->id, 'port_id' => $a->id, 'target_id' => $b->id],
                ['company_id' => $company->id, 'port_id' => $b->id, 'target_id' => $a->id],
            ], self::LINK);

            $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['ok']), json_encode($results));
            $this->assertSame(2, AssetPort::withoutCompany()->where('company_id', $company->id)->whereNotNull('linked_port_id')->count());
            $this->assertSame($b->id, AssetPort::withoutCompany()->findOrFail($a->id)->linked_port_id);
            $this->assertSame($a->id, AssetPort::withoutCompany()->findOrFail($b->id)->linked_port_id);
        } finally {
            $this->purgeCompany($company->id);
            $this->purgeCompany($this->company->id);
        }
    }
}
