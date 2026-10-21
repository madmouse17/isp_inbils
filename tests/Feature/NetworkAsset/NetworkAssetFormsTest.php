<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Inventory\Database\Factories\ProductFactory;
use Modules\Inventory\Models\Product;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\NetworkAsset\Models\NetworkAsset;
use Tests\TestCase;
use Tests\Traits\AsksInertiaForProps;

/**
 * The asset forms: product and location are searched on the server, dates are plain days, and every
 * message the user reads is Indonesian.
 */
class NetworkAssetFormsTest extends TestCase
{
    use AsksInertiaForProps;
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        app()['cache']->forget('spatie.permission.cache');

        $permissions = ['network_asset.view', 'network_asset.create', 'network_asset.update', 'network_asset.remove'];

        $this->company = Company::factory()->create(['is_active' => true]);
        $admin = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantPermissions($admin, [...$permissions, 'app.web']);
        $this->actingAs($admin);
    }

    private function product(string $sku, string $name, bool $active = true, ?Company $company = null): Product
    {
        return ProductFactory::new()->create(['company_id' => ($company ?? $this->company)->id, 'sku' => $sku, 'name' => $name, 'is_active' => $active]);
    }

    private function location(string $code, string $name, string $path, bool $active = true, ?Company $company = null): Location
    {
        return Location::factory()->create(['company_id' => ($company ?? $this->company)->id, 'code' => $code, 'name' => $name, 'path' => $path, 'is_active' => $active]);
    }

    /** @return array<string, mixed> */
    private function validPayload(array $overrides = []): array
    {
        return $overrides + ['name' => 'Router inti', 'asset_type' => 'router'];
    }

    public function test_the_create_page_sends_product_and_location_options_on_a_search_only(): void
    {
        $this->get(route('admin.network-assets.create'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/NetworkAssets/Create', false)
            ->missing('productOptions')
            ->missing('locationOptions'));

        $this->get(route('admin.network-assets.create'), $this->partialReload('Admin/NetworkAssets/Create', 'productOptions,locationOptions'))
            ->assertOk()
            ->assertJsonPath('props.productOptions', [])
            ->assertJsonPath('props.locationOptions', []);
    }

    public function test_the_product_search_finds_active_products_of_this_company_by_sku_or_name(): void
    {
        $onu = $this->product('PRD-ONU-1', 'ONU Huawei');
        $this->product('PRD-ONU-2', 'ONU lama', active: false);
        $this->product('PRD-ONU-3', 'ONU asing', company: Company::factory()->create(['is_active' => true]));
        $this->product('PRD-ROUTER', 'Router rumah');

        $search = fn (string $term) => $this->get(route('admin.network-assets.create', ['product_search' => $term]), $this->partialReload('Admin/NetworkAssets/Create', 'productOptions'))->assertOk();

        $search('ONU')->assertJsonCount(1, 'props.productOptions')->assertJsonPath('props.productOptions.0', [
            'value' => $onu->public_id,
            'label' => 'PRD-ONU-1 - ONU Huawei',
            'description' => $onu->category->name,
        ]);
        $search('PRD-ROUTER')->assertJsonCount(1, 'props.productOptions');
        $search('O')->assertJsonPath('props.productOptions', []);
    }

    public function test_the_location_search_finds_active_locations_by_code_name_or_path(): void
    {
        $pop = $this->location('POP-DAGO', 'POP Dago', 'AREA-BDG > POP-DAGO');
        $this->location('POP-OLD', 'POP Dago lama', 'AREA-BDG > POP-OLD', active: false);
        $this->location('POP-ASING', 'POP Dago asing', 'POP-ASING', company: Company::factory()->create(['is_active' => true]));

        foreach (['POP-DAGO', 'Dago', 'AREA-BDG'] as $term) {
            $this->get(route('admin.network-assets.create', ['location_search' => $term]), $this->partialReload('Admin/NetworkAssets/Create', 'locationOptions'))
                ->assertOk()
                ->assertJsonCount(1, 'props.locationOptions')
                ->assertJsonPath('props.locationOptions.0', ['value' => $pop->public_id, 'label' => 'POP-DAGO - POP Dago', 'description' => 'AREA-BDG > POP-DAGO']);
        }
    }

    public function test_the_edit_page_keeps_the_current_product_and_location_and_shows_dates_as_plain_days(): void
    {
        $product = $this->product('PRD-ONU-1', 'ONU Huawei');
        $location = $this->location('POP-DAGO', 'POP Dago', 'AREA-BDG > POP-DAGO');
        $asset = NetworkAssetFactory::new()->create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'purchase_date' => '2026-10-14',
            'warranty_expiry' => '2027-10-14',
        ]);

        $this->get(route('admin.network-assets.edit', $asset))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('asset.data.purchase_date', '2026-10-14')
            ->where('asset.data.warranty_expiry', '2027-10-14')
            ->where('asset.data.product.sku', 'PRD-ONU-1')
            ->where('asset.data.location.path', 'AREA-BDG > POP-DAGO')
            ->missing('productOptions'));

        $this->get(route('admin.network-assets.edit', $asset), $this->partialReload('Admin/NetworkAssets/Edit', 'productOptions,locationOptions'))
            ->assertOk()
            ->assertJsonPath('props.productOptions.0.value', $product->public_id)
            ->assertJsonPath('props.locationOptions.0.value', $location->public_id);
    }

    public function test_the_show_page_sends_location_options_for_the_install_dialog_on_a_search_only(): void
    {
        $location = $this->location('POP-DAGO', 'POP Dago', 'AREA-BDG > POP-DAGO');
        $asset = NetworkAssetFactory::new()->create(['company_id' => $this->company->id]);

        $this->get(route('admin.network-assets.show', $asset))->assertInertia(fn (Assert $page) => $page->missing('locationOptions'));
        $this->get(route('admin.network-assets.show', [$asset, 'location_search' => 'Dago']), $this->partialReload('Admin/NetworkAssets/Show', 'locationOptions'))
            ->assertJsonPath('props.locationOptions.0.value', $location->public_id);
    }

    public function test_a_date_that_is_not_a_plain_day_is_refused_with_an_indonesian_message(): void
    {
        foreach (['14/10/2026', '2026-10-14 10:00', '2026-13-40', 'besok'] as $bad) {
            $this->postJson(route('admin.network-assets.store'), $this->validPayload(['purchase_date' => $bad, 'warranty_expiry' => $bad]))
                ->assertStatus(422)
                ->assertJsonValidationErrors([
                    'purchase_date' => 'Tanggal pembelian tidak valid.',
                    'warranty_expiry' => 'Tanggal garansi berakhir tidak valid.',
                ]);
        }

        $this->assertSame(0, NetworkAsset::query()->count());
    }

    public function test_plain_days_are_stored_and_the_flash_is_indonesian(): void
    {
        $this->post(route('admin.network-assets.store'), $this->validPayload(['purchase_date' => '2026-10-14', 'warranty_expiry' => '2027-10-14']))
            ->assertRedirect(route('admin.network-assets.index'))
            ->assertSessionHas('success', 'Aset jaringan dibuat.');

        $asset = NetworkAsset::query()->firstOrFail();
        $this->assertSame('2026-10-14', $asset->purchase_date->toDateString());

        $this->put(route('admin.network-assets.update', $asset), $this->validPayload(['purchase_date' => '2026-10-15']))
            ->assertRedirect(route('admin.network-assets.index'))
            ->assertSessionHas('success', 'Aset jaringan diperbarui.');
        $this->assertSame('2026-10-15', $asset->fresh()->purchase_date->toDateString());
    }

    public function test_a_missing_field_is_named_in_indonesian(): void
    {
        $this->postJson(route('admin.network-assets.store'), ['asset_type' => 'router'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name' => 'nama wajib diisi.']);

        $asset = NetworkAssetFactory::new()->create(['company_id' => $this->company->id, 'status' => 'installed']);
        $this->postJson(route('admin.network-assets.remove', $asset), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason' => 'alasan wajib diisi.']);
    }
}
