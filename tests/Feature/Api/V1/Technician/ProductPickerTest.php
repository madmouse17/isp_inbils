<?php

namespace Tests\Feature\Api\V1\Technician;

use App\Models\Core\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Models\Unit;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

/**
 * The two pickers behind "add material" and "new request": which products and which stock-holding places the
 * technician app may offer.
 */
class ProductPickerTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    public function test_products_are_the_active_non_asset_products_of_the_company_by_name(): void
    {
        $technician = $this->technicianLogin();
        $foreigner = $this->technicianLogin();
        $this->product($technician, 'Patch Cord', 'PC-001', 'pcs');
        $drop = $this->product($technician, 'Drop Cable', 'DC-001', 'mtr');
        $this->product($technician, 'Old Cable', 'DC-OLD', 'mtr', ['is_active' => false]);
        $this->product($technician, 'ONT', 'ONT-1', 'pcs', ['type' => 'asset']);
        $this->product($technician, 'Gone Cable', 'DC-GONE', 'mtr')->delete();
        $this->product($foreigner, 'Foreign Cable', 'FC-001', 'mtr');
        $this->product($technician, 'Cable Tie', 'CT-001', 'pcs');

        $response = $this->api('GET', 'technician/products', token: $this->issueToken($technician, 'technician'))->assertOk();

        $this->assertSame(['Cable Tie', 'Drop Cable', 'Patch Cord'], array_column($response->json('data'), 'name'));
        $this->assertSame(['id', 'sku', 'name', 'unit', 'category', 'available'], array_keys($response->json('data.1')));
        $this->assertSame(['id' => $drop->category->public_id, 'name' => $drop->category->name], $response->json('data.1.category'), 'the picker groups the list by category');
        $this->assertNull($response->json('data.1.available'), 'a picker that names no warehouse says nothing about stock');
        $this->assertSame(['DC-001', 'mtr', $drop->public_id], [$response->json('data.1.sku'), $response->json('data.1.unit'), $response->json('data.1.id')]);
        $this->assertSame('pcs', $response->json('data.0.unit'));
        $response->assertJsonPath('meta.total', 3)->assertJsonPath('meta.per_page', 20)->assertJsonPath('meta.current_page', 1);
    }

    public function test_search_matches_name_or_sku_and_treats_wildcards_literally(): void
    {
        $technician = $this->technicianLogin();
        $token = $this->issueToken($technician, 'technician');
        $this->product($technician, 'Drop Cable 1 Core', 'FIB-100', 'mtr');
        $this->product($technician, 'Patch Cord', 'CORD-7', 'pcs');
        $this->product($technician, '100% Copper', 'CU-100', 'mtr');

        $names = fn (array $query): array => array_column($this->api('GET', 'technician/products?'.http_build_query($query), token: $token)->assertOk()->json('data'), 'name');

        $this->assertSame(['Drop Cable 1 Core'], $names(['search' => 'drop cab']));
        $this->assertSame(['Patch Cord'], $names(['search' => 'cord-7']));
        $this->assertSame(['100% Copper'], $names(['search' => '100%']));
        $this->assertSame([], $names(['search' => '%%']));
        $this->assertSame([], $names(['search' => '_']));
        $this->assertSame(['100% Copper', 'Drop Cable 1 Core', 'Patch Cord'], $names(['search' => '']));
    }

    public function test_products_are_paged_like_the_other_lists(): void
    {
        $technician = $this->technicianLogin();
        $token = $this->issueToken($technician, 'technician');
        foreach (range(1, 7) as $n) {
            $this->product($technician, sprintf('Item %02d', $n), "SKU-{$n}", 'pcs');
        }

        $page = $this->api('GET', 'technician/products?per_page=3&page=3', token: $token)->assertOk();

        $this->assertSame(['Item 07'], array_column($page->json('data'), 'name'));
        $page->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.total', 7)->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'per_page', 'total']]);
        $this->api('GET', 'technician/products?per_page=51', token: $token)->assertStatus(422)->assertJsonValidationErrors(['per_page'], 'errors');
        $this->api('GET', 'technician/products?page=0', token: $token)->assertStatus(422)->assertJsonValidationErrors(['page'], 'errors');
    }

    public function test_products_are_not_offered_without_the_inventory_permission(): void
    {
        $technician = $this->technicianLogin();
        $token = $this->issueToken($technician, 'technician');
        $this->companyRoles((int) $technician->company_id)['technician']->revokePermissionTo('inventory.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->api('GET', 'technician/products', token: $token)->assertForbidden()->assertJsonPath('code', 'forbidden');
    }

    public function test_available_per_warehouse(): void
    {
        $technician = $this->technicianLogin();
        $token = $this->issueToken($technician, 'technician');
        $cable = $this->product($technician, 'Drop Cable', 'DC-001', 'mtr');
        $tie = $this->product($technician, 'Cable Tie', 'CT-001', 'pcs');
        $this->product($technician, 'Patch Cord', 'PC-001', 'pcs');
        $alpha = $this->location($technician, ['name' => 'Gudang Alpha']);
        $zulu = $this->location($technician, ['name' => 'Gudang Zulu']);
        $this->stock($cable, $alpha, '40', '12.5');
        $this->stock($cable, $zulu, '5');
        $this->stock($tie, $alpha, '3', '3');
        $this->stock($tie, $zulu, '100');

        $availableAt = fn (Location $warehouse): array => array_column(
            $this->api('GET', 'technician/products?warehouse_id='.$warehouse->public_id, token: $token)->assertOk()->json('data'),
            'available',
            'name',
        );

        // Quantity minus reserved, trimmed; a product with no stock row there has none.
        $this->assertSame(['Cable Tie' => '0', 'Drop Cable' => '27.5', 'Patch Cord' => '0'], $availableAt($alpha));
        $this->assertSame(['Cable Tie' => '100', 'Drop Cable' => '5', 'Patch Cord' => '0'], $availableAt($zulu));
        $this->assertSame([null, null, null], array_column($this->api('GET', 'technician/products', token: $token)->assertOk()->json('data'), 'available'));
    }

    public function test_unknown_warehouse_is_422(): void
    {
        $technician = $this->technicianLogin();
        $foreigner = $this->technicianLogin();
        $token = $this->issueToken($technician, 'technician');
        $cable = $this->product($technician, 'Drop Cable', 'DC-001', 'mtr');
        $this->stock($cable, $this->location($technician, ['name' => 'Gudang Alpha']));
        $closed = $this->location($technician, ['name' => 'Closed Depot', 'is_active' => false]);
        $this->stock($cable, $closed);
        $foreign = $this->location($foreigner, ['name' => 'Foreign Depot']);
        $this->stock($this->product($foreigner, 'Foreign Cable', 'FC-001', 'mtr'), $foreign);
        $empty = $this->location($technician, ['name' => 'Empty Rack']);

        // Only a place the warehouses endpoint offers: unknown, another company's, closed, holding no stock, an integer id, a list.
        foreach ([strtolower((string) Str::ulid()), $foreign->public_id, $closed->public_id, $empty->public_id, '5', ['x']] as $warehouse) {
            $this->api('GET', 'technician/products?'.http_build_query(['warehouse_id' => $warehouse]), token: $token)
                ->assertStatus(422)
                ->assertJsonValidationErrors(['warehouse_id'], 'errors');
        }
    }

    public function test_warehouses_are_the_active_places_of_the_company_that_hold_stock(): void
    {
        $technician = $this->technicianLogin();
        $foreigner = $this->technicianLogin();
        $cable = $this->product($technician, 'Drop Cable', 'DC-001', 'mtr');
        $zulu = $this->location($technician, ['name' => 'Gudang Zulu', 'code' => 'GD-Z']);
        $alpha = $this->location($technician, ['name' => 'Gudang Alpha', 'code' => 'GD-A']);
        $this->stock($cable, $zulu);
        $this->stock($cable, $alpha);
        $this->stock($this->product($technician, 'Patch Cord', 'PC-001', 'pcs'), $alpha, 0);
        $this->location($technician, ['name' => 'Empty Rack']);
        $this->stock($cable, $this->location($technician, ['name' => 'Closed Depot', 'is_active' => false]));
        $this->stock($this->product($foreigner, 'Foreign Cable', 'FC-001', 'mtr'), $this->location($foreigner, ['name' => 'Foreign Depot']));

        $response = $this->api('GET', 'technician/warehouses', token: $this->issueToken($technician, 'technician'))->assertOk();

        $this->assertSame([
            ['id' => $alpha->public_id, 'name' => 'Gudang Alpha', 'code' => 'GD-A', 'last_used' => false, 'available_products' => 1],
            ['id' => $zulu->public_id, 'name' => 'Gudang Zulu', 'code' => 'GD-Z', 'last_used' => false, 'available_products' => 1],
        ], $response->json('data'));
        $this->assertSame(['data'], array_keys($response->json()));
    }

    public function test_warehouses_are_not_offered_to_someone_who_may_not_ask_for_stock(): void
    {
        $technician = $this->technicianLogin();
        $token = $this->issueToken($technician, 'technician');
        $this->companyRoles((int) $technician->company_id)['technician']->revokePermissionTo('inventory.request.create');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->api('GET', 'technician/warehouses', token: $token)->assertForbidden()->assertJsonPath('code', 'forbidden');
    }

    /** @param  array<string, mixed>  $overrides */
    private function product(User $owner, string $name, string $sku, string $unit, array $overrides = []): Product
    {
        $unitRow = Unit::withoutCompany()->where('company_id', $owner->company_id)->where('symbol', $unit)->first()
            ?? Unit::factory()->create(['company_id' => $owner->company_id, 'symbol' => $unit, 'name' => $unit.' '.Str::random(4)]);

        return Product::factory()->create([
            'company_id' => $owner->company_id,
            'category_id' => Category::factory()->create(['company_id' => $owner->company_id, 'unit_id' => $unitRow->id])->id,
            'unit_id' => $unitRow->id,
            'name' => $name,
            'sku' => $sku,
            ...$overrides,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function location(User $owner, array $overrides = []): Location
    {
        return Location::factory()->create(['company_id' => $owner->company_id, 'type' => 'site', ...$overrides]);
    }

    private function stock(Product $product, Location $location, string $quantity = '10', string $reserved = '0'): Stock
    {
        return Stock::query()->create([
            'company_id' => $product->company_id, 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => $quantity, 'reserved_quantity' => $reserved,
        ]);
    }
}
