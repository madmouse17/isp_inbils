<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\NetworkAsset\Models\NetworkAsset;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsOdnTopology;
use Tests\Traits\BuildsPanelFixtures;
use Tests\Traits\RunsConcurrentWorkers;

/**
 * Mounting devices in a rack (spec .scratch/hardware-panel-ui, design §3.2 and §4.7): the edges of the unit
 * arithmetic, the bounds of the request, and two writers that want the same unit. AssetPanelWriteRulesTest already pins
 * a placement inside the rack, the overlap message, the rack top, the target rack rules, unmounting and the shrink and
 * delete guards of a rack that holds devices.
 */
class RackPlacementTest extends TestCase
{
    use BuildsIpamOdnFixtures;
    use BuildsNetworkFixtures;
    use BuildsOdnTopology;
    use BuildsPanelFixtures;
    use RefreshDatabase;
    use RunsConcurrentWorkers;

    private const PLACE = <<<'PHP'
        $asset = Modules\NetworkAsset\Models\NetworkAsset::query()->findOrFail($args['asset_id']);
        Modules\NetworkAsset\Services\AssetPanelService::place($asset, $args['rack_id'], $args['unit'], $args['height']);

        return $asset->id;
    PHP;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->bootIpamOdnWorld(true);
    }

    private function place(NetworkAsset $asset, ?NetworkAsset $rack, ?int $unit = null, ?int $height = null): TestResponse
    {
        return $this->putJson(route('admin.network-assets.rack-placement.update', $asset), $rack === null
            ? ['rack_asset_id' => null]
            : ['rack_asset_id' => $rack->public_id, 'rack_unit' => $unit, 'height_u' => $height]);
    }

    /** @return array{0: ?int, 1: ?int, 2: ?int} rack id, unit and height of the stored row */
    private function mount(NetworkAsset $asset): array
    {
        $row = NetworkAsset::query()->findOrFail($asset->id);

        return [$row->rack_asset_id, $row->rack_unit, $row->height_u];
    }

    public function test_a_device_fits_exactly_under_the_rack_top_and_beside_its_neighbours(): void
    {
        $rack = $this->panelRack(10);
        [$top, $middle, $bottom] = [$this->panelRouter('mikrotik-hap-ac2'), $this->panelRouter('mikrotik-hap-ac2'), $this->panelRouter('mikrotik-hap-ac2')];

        $this->place($top, $rack, 9, 2)->assertSessionHas('success', 'Posisi rak disimpan.');
        $this->place($middle, $rack, 7, 2)->assertRedirect();
        $this->place($bottom, $rack, 1, 6)->assertRedirect();

        $this->assertSame([$rack->id, 9, 2], $this->mount($top));
        $this->assertSame([$rack->id, 7, 2], $this->mount($middle));
        $this->assertSame([$rack->id, 1, 6], $this->mount($bottom));
        $this->place($this->panelRouter('mikrotik-hap-ac2'), $rack, 10, 1)->assertStatus(422)->assertJsonPath('errors.rack_unit.0', fn (string $message): bool => str_contains($message, $top->code));
    }

    public function test_placing_with_the_same_values_again_changes_and_audits_nothing(): void
    {
        $rack = $this->panelRack(10);
        $router = $this->panelRouter('mikrotik-hap-ac2');

        $this->place($router, $rack, 3, 1)->assertRedirect();
        $stamp = NetworkAsset::query()->findOrFail($router->id)->updated_at;
        $this->travel(5)->minutes();
        $this->place($router, $rack, 3, 1)->assertRedirect();

        $this->assertEquals($stamp, NetworkAsset::query()->findOrFail($router->id)->updated_at);
        $this->assertSame(1, Activity::query()->where('description', 'rack_placed')->count());
    }

    public function test_taking_out_a_device_that_is_not_mounted_is_a_quiet_success(): void
    {
        $router = $this->panelRouter('mikrotik-hap-ac2');

        $this->place($router, null)->assertSessionHas('success', 'Perangkat dilepas dari rak.');

        $this->assertSame([null, null], array_slice($this->mount($router), 0, 2));
        $this->assertSame(0, Activity::query()->where('description', 'like', 'rack_%')->count());
    }

    public function test_a_device_moves_between_racks_and_frees_its_old_unit(): void
    {
        [$first, $second] = [$this->panelRack(10), $this->panelRack(10)];
        $router = $this->panelRouter('mikrotik-hap-ac2');
        $other = $this->panelRouter('mikrotik-hap-ac2');
        $this->place($router, $first, 3, 2)->assertRedirect();

        $this->place($router, $second, 1, 1)->assertRedirect();
        $this->place($other, $first, 3, 2)->assertRedirect();

        $this->assertSame([$second->id, 1, 1], $this->mount($router));
        $this->assertSame([$first->id, 3, 2], $this->mount($other));
    }

    public function test_a_rack_cannot_be_mounted_in_a_rack(): void
    {
        [$outer, $inner] = [$this->panelRack(42), $this->panelRack(10)];

        $this->place($inner, $outer, 1, 1)
            ->assertStatus(422)
            ->assertJsonPath('errors.rack_asset_id.0', 'Rak tidak dapat dipasang di dalam rak.');

        $this->assertNull($this->mount($inner)[0]);
    }

    public function test_rack_size_and_placement_numbers_are_bounded(): void
    {
        $rack = $this->panelRack(10);
        $router = $this->panelRouter('mikrotik-hap-ac2');
        $resize = fn (int $units): TestResponse => $this->putJson(route('admin.network-assets.panel.update', $rack), ['height_u' => $units]);

        $resize(0)->assertStatus(422)->assertJsonValidationErrors('height_u');
        $resize(53)->assertStatus(422)->assertJsonValidationErrors('height_u');
        $this->putJson(route('admin.network-assets.panel.update', $rack), [])->assertStatus(422);
        $this->putJson(route('admin.network-assets.panel.update', $rack), ['template_key' => 'generic-switch-8'])->assertStatus(422);
        $this->assertSame(10, NetworkAsset::query()->findOrFail($rack->id)->height_u);

        $resize(52)->assertRedirect();
        $this->assertSame(52, NetworkAsset::query()->findOrFail($rack->id)->height_u);

        $this->place($router, $rack, 53, 1)->assertStatus(422)->assertJsonValidationErrors('rack_unit');
        $this->place($router, $rack, 1, 11)->assertStatus(422)->assertJsonValidationErrors('height_u');
        $this->place($router, $rack, 1, 0)->assertStatus(422)->assertJsonValidationErrors('height_u');
        $this->assertNull($this->mount($router)[0]);

        $this->place($router, $rack, 43, 10)->assertRedirect();
        $this->assertSame([$rack->id, 43, 10], $this->mount($router));
    }

    public function test_a_unit_held_by_a_deleted_device_is_refused_by_name_and_not_by_a_database_error(): void
    {
        $rack = $this->panelRack(10);
        $gone = $this->panelRouter('mikrotik-hap-ac2');
        $this->place($gone, $rack, 4, 1)->assertRedirect();
        $gone->delete();

        $this->place($this->panelRouter('mikrotik-hap-ac2'), $rack, 4, 1)
            ->assertStatus(422)
            ->assertJsonPath('errors.rack_unit.0', "U4 sudah terisi {$gone->code}.");
    }

    public function test_two_placements_racing_for_one_unit_leave_exactly_one(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $rack = $this->panelRack(10, $company);
        [$first, $second] = [$this->panelRouter('mikrotik-hap-ac2', $company), $this->panelRouter('mikrotik-hap-ac2', $company)];
        $this->commitFixture();

        try {
            $results = $this->race([
                ['company_id' => $company->id, 'asset_id' => $first->id, 'rack_id' => $rack->id, 'unit' => 4, 'height' => 2],
                ['company_id' => $company->id, 'asset_id' => $second->id, 'rack_id' => $rack->id, 'unit' => 5, 'height' => 2],
            ], self::PLACE);

            $winners = array_values(array_filter($results, fn (array $result): bool => $result['ok']));
            $losers = array_values(array_filter($results, fn (array $result): bool => ! $result['ok']));
            $this->assertCount(1, $winners, json_encode($results));
            $this->assertCount(1, $losers, json_encode($results));
            $this->assertSame(422, $losers[0]['status']);
            $this->assertArrayHasKey('rack_unit', $losers[0]['errors']);
            $this->assertSame(1, NetworkAsset::withoutCompany()->where('rack_asset_id', $rack->id)->count());
            $this->assertNotNull(NetworkAsset::withoutCompany()->findOrFail($winners[0]['result'])->rack_unit);
        } finally {
            $this->purgeCompany($company->id);
            $this->purgeCompany($this->company->id);
        }
    }
}
