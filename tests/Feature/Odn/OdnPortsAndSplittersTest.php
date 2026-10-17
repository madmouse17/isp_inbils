<?php

namespace Tests\Feature\Odn;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Models\OdnSplitter;
use Modules\NetworkAsset\Services\OdnService;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;
use Tests\Traits\BuildsOdnTopology;

class OdnPortsAndSplittersTest extends TestCase
{
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

    /** @return list<int> port numbers of an asset's ports of one kind, ordered */
    private function numbers(int $assetId, string $kind = 'pon'): array
    {
        return OdnPort::query()->where('network_asset_id', $assetId)->where('kind', $kind)->orderBy('number')->pluck('number')->all();
    }

    private function addPonPorts(string $assetId, int $count): TestResponse
    {
        return $this->postJson(route('admin.odn.pon-ports.store', $assetId), ['count' => $count]);
    }

    /** @param array<string, mixed> $body */
    private function addSplitter(string $assetId, array $body): TestResponse
    {
        return $this->postJson(route('admin.odn.splitters.store', $assetId), $body);
    }

    public function test_pon_ports_are_numbered_after_the_highest_existing_one(): void
    {
        $olt = $this->net['olt'];

        $this->addPonPorts($olt->public_id, 4)->assertRedirect();
        $this->addPonPorts($olt->public_id, 4)->assertRedirect();

        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], $this->numbers($olt->id));
        $this->assertSame(8, OdnPort::query()->where('kind', 'pon')->whereNull('odn_splitter_id')->whereNull('subscription_id')->count());
        $this->assertSame($this->company->id, OdnPort::query()->firstOrFail()->company_id);
    }

    public function test_pon_port_numbering_continues_after_a_deleted_port_of_the_middle_not_the_top(): void
    {
        $olt = $this->net['olt'];
        $this->addPonPorts($olt->public_id, 3);
        OdnPort::query()->where('network_asset_id', $olt->id)->where('number', 2)->firstOrFail()->delete();

        $this->addPonPorts($olt->public_id, 1)->assertRedirect();

        $this->assertSame([1, 3, 4], $this->numbers($olt->id));
    }

    public function test_pon_ports_can_only_be_created_on_an_olt_and_within_1_to_64(): void
    {
        foreach (['odp', 'odc'] as $type) {
            $this->addPonPorts($this->net[$type]->public_id, 2)->assertStatus(422)->assertJsonValidationErrors('asset');
        }

        $router = $this->makeAsset($this->company, 'router');
        $this->addPonPorts($router->public_id, 2)->assertStatus(422)->assertJsonValidationErrors('asset');
        $this->addPonPorts($this->net['olt']->public_id, 0)->assertStatus(422)->assertJsonValidationErrors('count');
        $this->addPonPorts($this->net['olt']->public_id, 65)->assertStatus(422)->assertJsonValidationErrors('count');

        $this->assertDatabaseCount('odn_ports', 0);
    }

    public function test_a_splitter_creates_one_output_port_per_ratio_step(): void
    {
        $odp = $this->net['odp'];

        $this->addSplitter($odp->public_id, ['outputs' => 8, 'label' => 'Tray 1'])->assertRedirect();

        $splitter = OdnSplitter::query()->firstOrFail();
        $this->assertSame($odp->id, $splitter->network_asset_id);
        $this->assertSame(8, $splitter->outputs);
        $this->assertSame('Tray 1', $splitter->label);
        $this->assertNull($splitter->input_port_id);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], $this->numbers($odp->id, 'splitter_out'));
        $this->assertSame(8, OdnPort::query()->where('odn_splitter_id', $splitter->id)->where('network_asset_id', $odp->id)->count());
        $this->assertSame(0, OdnPort::query()->where('odn_splitter_id', $splitter->id)->whereNotNull('subscription_id')->count());
    }

    /** @return array<string, array{0: int}> */
    public static function ratios(): array
    {
        return ['1:2' => [2], '1:4' => [4], '1:16' => [16], '1:32' => [32], '1:64' => [64]];
    }

    #[DataProvider('ratios')]
    public function test_every_supported_ratio_creates_that_many_outputs(int $outputs): void
    {
        $this->addSplitter($this->net['odc']->public_id, ['outputs' => $outputs])->assertRedirect();

        $this->assertCount($outputs, $this->numbers($this->net['odc']->id, 'splitter_out'));
    }

    public function test_a_splitter_host_must_be_an_odc_or_odp_and_the_ratio_a_real_one(): void
    {
        $this->addSplitter($this->net['olt']->public_id, ['outputs' => 8])->assertStatus(422)->assertJsonValidationErrors('asset');
        $this->addSplitter($this->makeAsset($this->company, 'router')->public_id, ['outputs' => 8])->assertStatus(422)->assertJsonValidationErrors('asset');
        $this->addSplitter($this->net['odp']->public_id, ['outputs' => 3])->assertStatus(422)->assertJsonValidationErrors('outputs');
        $this->addSplitter($this->net['odp']->public_id, ['outputs' => 128])->assertStatus(422)->assertJsonValidationErrors('outputs');
        $this->addSplitter($this->net['odp']->public_id, [])->assertStatus(422)->assertJsonValidationErrors('outputs');

        $this->assertDatabaseCount('odn_splitters', 0);
        $this->assertDatabaseCount('odn_ports', 0);
    }

    public function test_a_splitter_can_be_connected_to_a_pon_port_when_it_is_created(): void
    {
        $pon = $this->ponPorts($this->net['olt'], 2);

        $this->addSplitter($this->net['odc']->public_id, ['outputs' => 4, 'input_port_id' => $pon[0]->public_id])->assertRedirect();

        $this->assertSame($pon[0]->id, OdnSplitter::query()->firstOrFail()->input_port_id);
    }

    public function test_a_splitter_cannot_be_connected_to_a_port_that_is_in_use(): void
    {
        $pon = $this->ponPorts($this->net['olt'], 2);
        [, $outputs] = $this->splitter($this->net['odc'], 4, $pon[0]);
        $subscription = $this->makeSubscription($this->company);
        $dropPort = $this->splitter($this->net['odp'], 8)[1][0];
        OdnService::assignDrop($dropPort, $subscription);

        $this->addSplitter($this->net['odc']->public_id, ['outputs' => 4, 'input_port_id' => $pon[0]->public_id])
            ->assertStatus(422)->assertJsonValidationErrors('input_port_id');
        $this->addSplitter($this->net['odc']->public_id, ['outputs' => 4, 'input_port_id' => $dropPort->public_id])
            ->assertStatus(422)->assertJsonValidationErrors('input_port_id');
        $this->addSplitter($this->net['odp']->public_id, ['outputs' => 8, 'input_port_id' => $outputs[0]->public_id])->assertRedirect();
        $this->addSplitter($this->net['odp']->public_id, ['outputs' => 8, 'input_port_id' => $outputs[0]->public_id])
            ->assertStatus(422)->assertJsonValidationErrors('input_port_id');

        $this->assertSame(3, OdnSplitter::query()->count(), 'the two refused splitters were not created');
    }

    public function test_a_splitter_cannot_be_connected_to_another_companys_port(): void
    {
        $other = $this->otherCompany();
        $foreign = $this->topology($other, 'X');
        $foreignPon = $this->inCompany($other, fn () => $this->ponPorts($foreign['olt'], 1)[0]);

        $this->addSplitter($this->net['odc']->public_id, ['outputs' => 4, 'input_port_id' => $foreignPon->public_id])
            ->assertStatus(422)->assertJsonValidationErrors('input_port_id');
    }

    public function test_a_splitter_cannot_be_connected_to_its_own_output_or_to_anything_downstream(): void
    {
        $pon = $this->ponPorts($this->net['olt'], 1);
        [$top, $topOutputs] = $this->splitter($this->net['odc'], 4, $pon[0]);
        [$middle, $middleOutputs] = $this->splitter($this->net['odp'], 4, $topOutputs[0]);
        [$bottom, $bottomOutputs] = $this->splitter($this->net['odp'], 2, $middleOutputs[0]);

        $this->putJson(route('admin.odn.splitters.update', $top), ['input_port_id' => $topOutputs[1]->public_id])
            ->assertStatus(422)->assertJsonValidationErrors('input_port_id');
        $this->putJson(route('admin.odn.splitters.update', $top), ['input_port_id' => $bottomOutputs[0]->public_id])
            ->assertStatus(422)->assertJsonValidationErrors('input_port_id');
        $this->putJson(route('admin.odn.splitters.update', $middle), ['input_port_id' => $bottomOutputs[1]->public_id])
            ->assertStatus(422)->assertJsonValidationErrors('input_port_id');

        $this->assertSame($pon[0]->id, $top->fresh()->input_port_id);
        $this->assertSame($topOutputs[0]->id, $middle->fresh()->input_port_id);
        $this->assertSame($middleOutputs[0]->id, $bottom->fresh()->input_port_id);
    }

    public function test_a_chain_of_more_than_eight_splitters_above_a_port_is_refused(): void
    {
        $pon = $this->ponPorts($this->net['olt'], 1);
        $input = $pon[0];
        $outputsByLevel = [];

        for ($level = 1; $level <= 9; $level++) {
            [, $outputs] = $this->splitter($this->net['odc'], 2, $input);
            $outputsByLevel[$level] = $outputs;
            $input = $outputs[0];
        }

        $this->addSplitter($this->net['odp']->public_id, ['outputs' => 4, 'input_port_id' => $outputsByLevel[8][1]->public_id])->assertRedirect();
        $this->addSplitter($this->net['odp']->public_id, ['outputs' => 4, 'input_port_id' => $outputsByLevel[9][1]->public_id])
            ->assertStatus(422)->assertJsonValidationErrors('input_port_id');
    }

    public function test_update_connects_moves_and_disconnects_the_input_and_renames_the_splitter(): void
    {
        $pon = $this->ponPorts($this->net['olt'], 2);
        [$splitter] = $this->splitter($this->net['odc'], 4);

        $this->putJson(route('admin.odn.splitters.update', $splitter), ['input_port_id' => $pon[0]->public_id, 'label' => 'Main'])->assertRedirect();
        $this->assertSame($pon[0]->id, $splitter->fresh()->input_port_id);
        $this->assertSame('Main', $splitter->fresh()->label);

        $this->putJson(route('admin.odn.splitters.update', $splitter), ['input_port_id' => $pon[1]->public_id])->assertRedirect();
        $this->assertSame($pon[1]->id, $splitter->fresh()->input_port_id);
        $this->assertSame('Main', $splitter->fresh()->label, 'a missing key leaves the field alone');

        $this->putJson(route('admin.odn.splitters.update', $splitter), ['label' => 'Spare'])->assertRedirect();
        $this->assertSame($pon[1]->id, $splitter->fresh()->input_port_id);

        $this->putJson(route('admin.odn.splitters.update', $splitter), ['input_port_id' => null])->assertRedirect();
        $this->assertNull($splitter->fresh()->input_port_id);
        $this->assertSame('Spare', $splitter->fresh()->label);

        $this->putJson(route('admin.odn.splitters.update', $splitter), ['input_port_id' => $pon[0]->public_id])->assertRedirect();
        $this->assertSame($pon[0]->id, $splitter->fresh()->input_port_id, 'a freed port can be used again');
    }

    public function test_a_free_splitter_is_deleted_with_its_outputs(): void
    {
        [$splitter] = $this->splitter($this->net['odp'], 8);

        $this->deleteJson(route('admin.odn.splitters.destroy', $splitter))->assertRedirect();

        $this->assertDatabaseMissing('odn_splitters', ['id' => $splitter->id]);
        $this->assertSame(0, OdnPort::query()->where('odn_splitter_id', $splitter->id)->count());
    }

    public function test_a_splitter_with_an_occupied_output_cannot_be_deleted(): void
    {
        $subscription = $this->makeSubscription($this->company);
        [$withDrop, $dropOutputs] = $this->splitter($this->net['odp'], 4);
        OdnService::assignDrop($dropOutputs[0], $subscription);
        [$withChild, $childOutputs] = $this->splitter($this->net['odc'], 4);
        $this->splitter($this->net['odp'], 4, $childOutputs[1]);

        $this->deleteJson(route('admin.odn.splitters.destroy', $withDrop))->assertStatus(422)->assertJsonValidationErrors('splitter');
        $this->deleteJson(route('admin.odn.splitters.destroy', $withChild))->assertStatus(422)->assertJsonValidationErrors('splitter');

        $this->assertDatabaseHas('odn_splitters', ['id' => $withDrop->id]);
        $this->assertDatabaseHas('odn_splitters', ['id' => $withChild->id]);
        $this->assertSame(4, OdnPort::query()->where('odn_splitter_id', $withDrop->id)->count());
    }

    public function test_a_pon_port_can_be_deleted_unless_a_splitter_hangs_on_it(): void
    {
        $pon = $this->ponPorts($this->net['olt'], 2);
        $this->splitter($this->net['odc'], 4, $pon[0]);

        $this->deleteJson(route('admin.odn.ports.destroy', $pon[0]))->assertStatus(422)->assertJsonValidationErrors('port');
        $this->deleteJson(route('admin.odn.ports.destroy', $pon[1]))->assertRedirect();

        $this->assertDatabaseHas('odn_ports', ['id' => $pon[0]->id]);
        $this->assertDatabaseMissing('odn_ports', ['id' => $pon[1]->id]);
    }

    public function test_a_splitter_output_port_cannot_be_deleted_on_its_own(): void
    {
        [, $outputs] = $this->splitter($this->net['odp'], 4);

        $this->deleteJson(route('admin.odn.ports.destroy', $outputs[0]))->assertStatus(422)->assertJsonValidationErrors('port');

        $this->assertDatabaseHas('odn_ports', ['id' => $outputs[0]->id]);
    }

    public function test_every_odn_register_route_is_404_for_another_companys_records(): void
    {
        $other = $this->otherCompany();
        $foreign = $this->topology($other, 'X');
        $foreignPon = $this->inCompany($other, fn () => $this->ponPorts($foreign['olt'], 2));
        [$foreignSplitter, $foreignOutputs] = $this->inCompany($other, fn () => $this->splitter($foreign['odp'], 4));
        $subscription = $this->makeSubscription($this->company);

        $this->get(route('admin.odn.assets.show', $foreign['olt']))->assertNotFound();
        $this->postJson(route('admin.odn.pon-ports.store', $foreign['olt']), ['count' => 1])->assertNotFound();
        $this->postJson(route('admin.odn.splitters.store', $foreign['odc']), ['outputs' => 4])->assertNotFound();
        $this->putJson(route('admin.odn.splitters.update', $foreignSplitter), ['label' => 'x'])->assertNotFound();
        $this->deleteJson(route('admin.odn.splitters.destroy', $foreignSplitter))->assertNotFound();
        $this->deleteJson(route('admin.odn.ports.destroy', $foreignPon[0]))->assertNotFound();
        $this->postJson(route('admin.odn.drops.store', $foreignOutputs[0]), ['subscription_id' => $subscription->public_id])->assertNotFound();
        $this->deleteJson(route('admin.odn.drops.destroy', $foreignOutputs[0]))->assertNotFound();

        $this->assertSame(2, OdnPort::withoutCompany()->where('network_asset_id', $foreign['olt']->id)->count());
        $this->assertSame(4, OdnPort::withoutCompany()->where('odn_splitter_id', $foreignSplitter->id)->count());
    }

    public function test_register_changes_are_audited_with_the_actor(): void
    {
        $pon = $this->ponPorts($this->net['olt'], 2);
        $this->addSplitter($this->net['odc']->public_id, ['outputs' => 4, 'input_port_id' => $pon[0]->public_id])->assertRedirect();
        $splitter = OdnSplitter::query()->firstOrFail();
        $this->deleteJson(route('admin.odn.splitters.destroy', $splitter))->assertRedirect();

        $events = Activity::query()->where('log_name', 'odn')->orderBy('id')->pluck('description')->all();

        $this->assertSame(['pon_ports_created', 'splitter_created', 'splitter_deleted'], $events);
        $this->assertSame($this->admin->id, Activity::query()->where('log_name', 'odn')->where('description', 'splitter_created')->value('causer_id'));
        $this->assertSame(
            ['created', 'deleted'],
            Activity::query()->where('log_name', 'odn_splitter')->where('subject_id', $splitter->id)->orderBy('id')->pluck('description')->all(),
        );
    }

    public function test_a_second_creation_after_a_refusal_still_numbers_from_the_real_highest(): void
    {
        $olt = $this->net['olt'];

        $this->addPonPorts($olt->public_id, 70)->assertStatus(422);
        $this->addPonPorts($olt->public_id, 2)->assertRedirect();

        $this->assertSame([1, 2], $this->numbers($olt->id));
    }
}
