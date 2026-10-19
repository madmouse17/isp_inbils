<?php

namespace Tests\Feature\NetworkAsset;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Modules\NetworkAsset\Http\Resources\AssetPanelResource;
use Modules\NetworkAsset\Models\AssetPort;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Models\OdnSplitter;
use Modules\NetworkAsset\Models\PortTemplate;
use Modules\NetworkAsset\Queries\AssetPanelQuery;
use Modules\NetworkAsset\Support\PanelLayout;
use Modules\NetworkAsset\Support\PortTemplateCatalogue;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;
use Tests\Traits\BuildsOdnTopology;

/**
 * What applying a model does to the ports of a device (spec .scratch/hardware-panel-ui, design §3 and §4.7), seen
 * from the catalogue outward: every shipped row is valid and applicable, PON ports of an OLT stay in the ODN
 * register, the error keys of the apply route, and the schema rolls back and forward. The cases that
 * AssetPanelWriteRulesTest pins (names, idempotence, kept links, the 256 cap) are not repeated here.
 */
class AssetPanelTemplateTest extends TestCase
{
    use BuildsIpamOdnFixtures;
    use BuildsOdnTopology;
    use RefreshDatabase;

    private const MIGRATION_TEST = 'test_the_panel_migrations_roll_back_and_apply_again';

    /** The migrations are DDL: MySQL commits a transaction at the first one, so that test runs without one. */
    protected function connectionsToTransact(): array
    {
        return $this->name() === self::MIGRATION_TEST ? [] : [null];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        if ($this->name() !== self::MIGRATION_TEST) {
            $this->bootIpamOdnWorld(true);
        }
    }

    /** @param array<string, mixed> $body */
    private function apply(NetworkAsset $asset, array $body): TestResponse
    {
        return $this->putJson(route('admin.network-assets.panel.update', $asset), $body);
    }

    /** @return list<array{string, int, string, string}> group, number, kind and name of every port row, in insert order */
    private function ports(NetworkAsset $asset): array
    {
        return AssetPort::query()->where('network_asset_id', $asset->id)->orderBy('id')->get()
            ->map(fn (AssetPort $port): array => [$port->group_key, $port->number, $port->kind, $port->name])->all();
    }

    public function test_every_catalogue_template_passes_the_layout_rules_and_is_stored_in_canonical_form(): void
    {
        $templates = PortTemplate::query()->orderBy('key')->get();

        $this->assertCount(14, $templates);
        $this->assertEqualsCanonicalizing(PortTemplateCatalogue::keys(), $templates->pluck('key')->all());

        foreach ($templates as $template) {
            $canonical = PanelLayout::validate($template->layout, $template->asset_type, catalogue: true);

            $this->assertEquals($canonical, $template->layout, "{$template->key} is not stored in canonical form");
            $this->assertSame([], PanelLayout::problems($canonical, $template->asset_type, catalogue: true), $template->key);
            $this->assertLessThanOrEqual(PanelLayout::MAX_PORTS, PanelLayout::portCount($canonical), $template->key);
            $this->assertContains($template->asset_type, PanelLayout::ASSET_TYPES, $template->key);
            $this->assertTrue($template->height_u === null || ($template->height_u >= 1 && $template->height_u <= 52), "{$template->key} height_u");
            $this->assertNotSame('', trim($template->vendor.$template->model), $template->key);
        }
    }

    public function test_every_catalogue_template_applies_to_an_asset_of_its_type_with_exactly_the_ports_it_draws(): void
    {
        foreach (PortTemplate::query()->orderBy('key')->get() as $index => $template) {
            $asset = $this->makeAsset($this->company, $template->asset_type, null, ['code' => 'TPL-'.$index]);

            $this->apply($asset, ['template_key' => $template->key])->assertRedirect();

            $asset->refresh();
            $rows = PanelLayout::assetPortRows($template->layout);
            $this->assertSame(array_map(fn (array $row): array => [$row['group_key'], $row['number'], $row['kind'], $row['name']], $rows), $this->ports($asset), $template->key);
            $this->assertSame(count($rows), count(array_unique(array_column($rows, 'name'))), "{$template->key} names two ports alike");
            $this->assertEquals($template->layout, $asset->port_layout, $template->key);
            $this->assertSame([$template->key, $template->height_u], [$asset->template_key, $asset->height_u], $template->key);
            $this->assertSame(count(PanelLayout::ponSlots($template->layout)), OdnPort::query()->where('network_asset_id', $asset->id)->where('kind', 'pon')->count(), "{$template->key} PON register rows");
            $this->assertSame(array_column(PanelLayout::splitterBlocks($template->layout), 'count'), OdnSplitter::query()->where('network_asset_id', $asset->id)->pluck('outputs')->all(), "{$template->key} splitters");
        }
    }

    public function test_existing_pon_ports_map_to_model_slots_by_number_without_duplicate_rows(): void
    {
        $olt = $this->makeAsset($this->company, 'olt', null, ['code' => 'OLT-1']);
        $odc = $this->makeAsset($this->company, 'odc', null, ['code' => 'ODC-1']);
        $registered = $this->ponPorts($olt, 5);
        $existing = collect($registered)->pluck('public_id', 'number');
        $this->splitter($odc, 8, $registered[1]);

        $this->apply($olt, ['template_key' => 'zte-c320-gtgh'])->assertRedirect();
        $this->apply($olt, ['template_key' => 'zte-c320-gtgh'])->assertRedirect();

        $register = OdnPort::query()->where('network_asset_id', $olt->id)->where('kind', 'pon')->orderBy('number')->pluck('public_id', 'number');
        $this->assertSame(range(1, 32), $register->keys()->all());
        $this->assertSame($existing->all(), $register->only(range(1, 5))->all(), 'the five registered ports keep their rows');
        $this->assertSame(0, AssetPort::query()->where('network_asset_id', $olt->id)->whereIn('group_key', ['pon1', 'pon2'])->count(), 'PON ports are never asset_ports rows');

        $panel = json_decode(json_encode((new AssetPanelResource(AssetPanelQuery::for($olt->refresh(), true)))->resolve()), true);
        $drawn = collect($panel['groups'])->where('role', 'pon')->flatMap(fn (array $group): array => array_column($group['ports'], 'id'))->all();
        $this->assertSame($register->values()->all(), $drawn, 'the panel draws each register row once, in slot order');
        $this->assertSame('gpon-olt_1/1/2', collect($panel['groups'])->firstWhere('key', 'pon1')['ports'][1]['name']);
    }

    public function test_an_olt_with_more_pon_ports_than_a_custom_layout_is_refused_under_layout(): void
    {
        $olt = $this->makeAsset($this->company, 'olt', null, ['code' => 'OLT-1']);
        $this->ponPorts($olt, 20);
        $layout = ['rows' => [[['group' => 'pon1', 'kind' => 'sfp', 'count' => 16, 'role' => 'pon', 'slot' => 1]]]];

        $this->apply($olt, ['layout' => $layout])
            ->assertStatus(422)
            ->assertJsonPath('errors.layout.0', 'OLT ini sudah punya 20 port PON terdaftar; model ini hanya 16.');

        $this->assertSame(20, OdnPort::query()->where('network_asset_id', $olt->id)->count());
        $this->assertNull($olt->fresh()->port_layout);
    }

    public function test_the_device_height_of_a_custom_layout_is_bounded_and_an_enclosure_has_no_catalogue_model(): void
    {
        $router = $this->makeAsset($this->company, 'router', null, ['code' => 'RTR-1']);
        $layout = ['rows' => [[['group' => 'ether', 'kind' => 'rj45', 'count' => 4]]]];

        $this->apply($router, ['layout' => $layout, 'height_u' => 0])->assertStatus(422)->assertJsonValidationErrors('height_u');
        $this->apply($router, ['layout' => $layout, 'height_u' => 53])->assertStatus(422)->assertJsonValidationErrors('height_u');
        $this->apply($this->makeAsset($this->company, 'odc', null, ['code' => 'ODC-1']), ['template_key' => 'generic-odp-8'])->assertStatus(422)->assertJsonValidationErrors('template_key');
        $this->apply($this->makeAsset($this->company, 'rack', null, ['code' => 'RACK-1']), ['template_key' => 'generic-switch-8'])->assertStatus(422);

        $this->assertDatabaseCount('asset_ports', 0);
        $this->assertNull($router->fresh()->port_layout);

        $this->apply($router, ['layout' => $layout, 'height_u' => 52])->assertRedirect();
        $this->assertSame(52, $router->fresh()->height_u);
    }

    public function test_applying_a_model_or_a_rack_size_says_so_in_the_flash(): void
    {
        $router = $this->makeAsset($this->company, 'router', null, ['code' => 'RTR-1']);
        $rack = $this->makeAsset($this->company, 'rack', null, ['code' => 'RACK-1']);

        $this->apply($router, ['template_key' => 'mikrotik-hap-ac2'])->assertSessionHas('success', 'Model perangkat diterapkan.');
        $this->apply($rack, ['height_u' => 42])->assertSessionHas('success', 'Ukuran rak disimpan.');
    }

    public function test_the_panel_migrations_roll_back_and_apply_again(): void
    {
        $paths = array_map(fn (string $name): string => "Modules/NetworkAsset/database/migrations/{$name}.php", [
            '2026_10_24_110000_create_port_templates_table',
            '2026_10_24_110100_add_panel_columns_to_network_assets_table',
            '2026_10_24_110200_create_asset_ports_table',
            '2026_10_24_110300_seed_port_templates',
        ]);
        $panelColumns = ['template_key', 'port_layout', 'rack_asset_id', 'rack_unit', 'height_u'];
        $schema = fn (): array => [
            Schema::hasTable('port_templates'),
            Schema::hasTable('asset_ports'),
            ...array_map(fn (string $column): bool => Schema::hasColumn('network_assets', $column), $panelColumns),
        ];

        $this->assertSame(array_fill(0, 7, true), $schema());

        try {
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => $paths, '--force' => true]));
            $this->assertSame(array_fill(0, 7, false), $schema(), 'the four migrations undo the whole panel schema');
        } finally {
            Artisan::call('migrate', ['--force' => true]);
        }

        $this->assertSame(array_fill(0, 7, true), $schema());
        $this->assertSame(14, DB::table('port_templates')->count());
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertStringContainsString('Nothing to migrate', Artisan::output());
    }
}
