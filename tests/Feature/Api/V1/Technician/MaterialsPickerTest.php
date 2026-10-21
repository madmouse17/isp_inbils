<?php

namespace Tests\Feature\Api\V1\Technician;

use App\Models\Core\Location;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\InventoryRequestItem;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Services\MaterialReturnService;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderItem;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

/**
 * What the technician app needs to ask for and give back material in one go (F4-U10, F12-U06, F4-U15): each product says
 * which category it sits in, each warehouse says how much it can fill, and each line of a return says what the SPK of the
 * case already records as used. All three come from eager loads and one grouped query, never a query per row.
 */
class MaterialsPickerTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private User $technician;

    private string $token;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->technician = $this->technicianLogin();
        $this->token = $this->issueToken($this->technician, 'technician');
        CompanyService::useCompanyForTests((int) $this->technician->company_id);
        $this->unit = Unit::factory()->create(['company_id' => $this->technician->company_id, 'symbol' => 'm'.Str::random(3)]);
    }

    public function test_a_product_names_its_category_for_the_picker_to_group_by(): void
    {
        $cables = $this->category('Kabel');
        $this->product('Kabel Drop', ['category_id' => $cables->id]);
        $this->product('Pita Ukur', ['category_id' => $this->category('Alat')->id]);

        $rows = collect($this->api('GET', 'technician/products', token: $this->token)->assertOk()->json('data'))->keyBy('name');

        $this->assertSame(['id' => $cables->public_id, 'name' => 'Kabel'], $rows['Kabel Drop']['category']);
        $this->assertSame('Alat', $rows['Pita Ukur']['category']['name']);
    }

    public function test_a_category_that_was_archived_still_names_the_products_it_held(): void
    {
        $old = $this->category('Kabel Lama');
        $this->product('Kabel Tua', ['category_id' => $old->id]);
        $old->delete();

        $row = $this->api('GET', 'technician/products?search=Kabel Tua', token: $this->token)->assertOk()->json('data.0');

        $this->assertSame('Kabel Lama', $row['category']['name']);
    }

    public function test_the_product_picker_costs_the_same_queries_for_one_product_or_many(): void
    {
        $this->product('Satu', ['category_id' => $this->category('Satu')->id]);
        $one = $this->queriesOf(fn () => $this->api('GET', 'technician/products', token: $this->token)->assertOk());

        foreach (range(1, 6) as $index) {
            $this->product("Barang {$index}", ['category_id' => $this->category("Kategori {$index}")->id]);
        }
        $many = $this->queriesOf(fn () => $this->api('GET', 'technician/products', token: $this->token)->assertOk());

        $this->assertSame($one, $many);
    }

    public function test_a_warehouse_says_how_many_products_are_free_to_ask_for(): void
    {
        $big = $this->warehouse('Gudang Besar');
        $small = $this->warehouse('Gudang Kecil');
        $empty = $this->warehouse('Gudang Kosong');
        $cable = $this->product('Kabel');
        $connector = $this->product('Konektor');
        $held = $this->product('Dicadangkan');
        $inactive = $this->product('Nonaktif', ['is_active' => false]);
        $asset = $this->product('Router', ['type' => 'asset']);
        $deleted = $this->product('Dihapus');
        $deleted->delete();

        $this->stock($big, $cable, 10);
        $this->stock($big, $connector, 5, reserved: 2);
        $this->stock($big, $held, 4, reserved: 4);
        $this->stock($big, $inactive, 9);
        $this->stock($big, $asset, 3);
        $this->stock($big, $deleted, 8);
        $this->stock($small, $cable, 1);
        $this->stock($empty, $cable, 0);

        $rows = collect($this->api('GET', 'technician/warehouses', token: $this->token)->assertOk()->json('data'))->keyBy('name');

        $this->assertSame(2, $rows['Gudang Besar']['available_products'], 'only products the picker lists and that are not all reserved');
        $this->assertSame(1, $rows['Gudang Kecil']['available_products']);
        $this->assertSame(0, $rows['Gudang Kosong']['available_products'], 'a place that holds only a zero row is still offered');
    }

    public function test_the_warehouse_list_costs_the_same_queries_for_one_place_or_many(): void
    {
        $product = $this->product('Kabel');
        $this->stock($this->warehouse('Gudang 0'), $product, 5);
        $one = $this->queriesOf(fn () => $this->api('GET', 'technician/warehouses', token: $this->token)->assertOk());

        foreach (range(1, 5) as $index) {
            $this->stock($this->warehouse("Gudang {$index}"), $product, $index);
        }
        $many = $this->queriesOf(fn () => $this->api('GET', 'technician/warehouses', token: $this->token)->assertOk());

        $this->assertSame($one, $many);
    }

    public function test_a_return_line_carries_what_the_spk_already_records_as_used(): void
    {
        $workOrder = $this->workOrder();
        [$cable, $connector, $tape] = [$this->product('Kabel'), $this->product('Konektor'), $this->product('Isolasi')];
        $this->recordUse($workOrder, $cable, used: 6.5);
        $this->recordUse($workOrder, $connector, used: 0, reserved: 3);
        $withSpk = $this->returnCase($workOrder, [$cable, $connector, $tape]);
        $withoutSpk = $this->returnCase(null, [$cable]);

        $detail = $this->api('GET', "technician/material-returns/{$withSpk->public_id}", token: $this->token)->assertOk()->json('data.lines');
        $recorded = collect($detail)->mapWithKeys(fn (array $line): array => [$line['product']['name'] => $line['recorded_used_qty']]);

        $this->assertEqualsWithDelta(6.5, $recorded['Kabel'], 0.001, 'what the SPK says was used');
        $this->assertEqualsWithDelta(0.0, $recorded['Konektor'], 0.001, 'planned, not used yet');
        $this->assertEqualsWithDelta(0.0, $recorded['Isolasi'], 0.001, 'not on the SPK at all');

        $plain = $this->api('GET', "technician/material-returns/{$withoutSpk->public_id}", token: $this->token)->assertOk()->json('data.lines.0');
        $this->assertNull($plain['recorded_used_qty'], 'a case without an SPK has no record to read');
    }

    public function test_the_return_list_costs_the_same_queries_for_one_case_or_many(): void
    {
        $workOrder = $this->workOrder();
        $product = $this->product('Kabel');
        $this->recordUse($workOrder, $product, used: 1);
        $this->returnCase($workOrder, [$product]);
        $one = $this->queriesOf(fn () => $this->api('GET', 'technician/material-returns', token: $this->token)->assertOk());

        foreach (range(1, 4) as $ignored) {
            $other = $this->workOrder();
            $this->recordUse($other, $product, used: 2);
            $this->returnCase($other, [$product]);
        }
        $many = $this->queriesOf(function () {
            $response = $this->api('GET', 'technician/material-returns', token: $this->token)->assertOk();
            $this->assertCount(5, $response->json('data'));
        });

        $this->assertSame($one, $many);
    }

    /**
     * The queries one call runs, counted on its second run: the first fills what every request shares (company,
     * permissions) and stamps the token as used. The clock stays still for both. Sanctum writes the token's
     * `last_used_at` whenever the second has changed since the token's last request, so a run that crossed a second
     * between the two calls counted one query more than a run that did not, and one endpoint "cost" 15 queries or 16
     * by luck (a loaded parallel run crosses seconds far more often than a quiet one).
     */
    private function queriesOf(callable $call): int
    {
        return $this->freezeTime(function () use ($call): int {
            $call();

            DB::flushQueryLog();
            DB::enableQueryLog();
            $call();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        });
    }

    private function category(string $name): Category
    {
        return Category::factory()->create(['company_id' => $this->technician->company_id, 'name' => $name, 'unit_id' => $this->unit->id]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function product(string $name, array $attributes = []): Product
    {
        return Product::factory()->create([
            'company_id' => $this->technician->company_id,
            'unit_id' => $this->unit->id,
            'category_id' => $this->category('Umum '.Str::random(4))->id,
            'name' => $name,
            'type' => 'consumable',
            'is_active' => true,
            ...$attributes,
        ]);
    }

    private function warehouse(string $name): Location
    {
        return Location::factory()->create(['company_id' => $this->technician->company_id, 'type' => 'site', 'name' => $name]);
    }

    private function stock(Location $warehouse, Product $product, float $quantity, float $reserved = 0): void
    {
        Stock::query()->create(['company_id' => $this->technician->company_id, 'product_id' => $product->id, 'location_id' => $warehouse->id, 'quantity' => $quantity, 'reserved_quantity' => $reserved]);
    }

    private function workOrder(): WorkOrder
    {
        return WorkOrderFactory::new()->create(['company_id' => $this->technician->company_id, 'assigned_to' => $this->technician->id, 'status' => 'in_progress']);
    }

    private function recordUse(WorkOrder $workOrder, Product $product, float $used, float $reserved = 0): void
    {
        WorkOrderItem::query()->create([
            'company_id' => $workOrder->company_id,
            'work_order_id' => $workOrder->id,
            'product_id' => $product->id,
            'quantity_reserved' => $reserved,
            'quantity_used' => $used,
        ]);
    }

    /**
     * A case of the technician, as the hand-over of a request for these products opens it.
     *
     * @param  list<Product>  $products
     */
    private function returnCase(?WorkOrder $workOrder, array $products): MaterialReturnCase
    {
        $warehouse = $this->warehouse('Gudang '.Str::random(4));
        $request = InventoryRequest::query()->create([
            'company_id' => $this->technician->company_id,
            'code' => 'REQ-'.Str::upper(Str::random(6)),
            'requested_by' => $this->technician->id,
            'warehouse_location_id' => $warehouse->id,
            'source_type' => $workOrder === null ? null : 'work_order',
            'source_id' => $workOrder?->id,
            'status' => 'issued',
        ]);
        foreach ($products as $product) {
            InventoryRequestItem::query()->create([
                'company_id' => $this->technician->company_id,
                'inventory_request_id' => $request->id,
                'product_id' => $product->id,
                'source_location_id' => $warehouse->id,
                'quantity_requested' => 10,
                'quantity_approved' => 10,
                'quantity_issued' => 10,
            ]);
        }

        return MaterialReturnService::createForIssuedRequest($request);
    }
}
