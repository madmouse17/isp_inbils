<?php

namespace Tests\Feature\Inventory;

use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Services\StockService;
use Tests\TestCase;
use Tests\Traits\WithoutAppOnlyRedirect;

/** F8-D10: the movement page names who booked a movement, the balance it left and, for a goods receipt, where it came from. */
class StockMovementListTest extends TestCase
{
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private Company $company;

    private Product $product;

    private Location $location;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->company = Company::factory()->create();
        $this->manager = User::factory()->create(['company_id' => $this->company->id, 'name' => 'Maya Gudang']);
        $this->grantRole($this->manager, 'manager');
        $this->actingAs($this->manager);

        $unit = Unit::query()->create(['name' => 'Piece', 'symbol' => 'PCS']);
        $category = Category::query()->create(['name' => 'CPE', 'code' => 'CPE', 'unit_id' => $unit->id]);
        $this->product = Product::query()->create([
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku' => 'CPE-001',
            'name' => 'ONT',
            'type' => 'consumable',
            'track_stock' => true,
            'min_stock' => 0,
            'is_active' => true,
        ]);
        $this->location = Location::query()->create(['name' => 'WH-A', 'code' => 'WH-A', 'type' => 'site', 'is_active' => true]);
    }

    public function test_a_movement_names_its_actor_the_balance_and_the_receipt_details(): void
    {
        StockService::receive($this->product->id, $this->location->id, 10, 'faktur masuk', supplierName: 'PT Kabel Jaya', purchaseReference: 'INV-7', unitCost: 2500);
        StockService::issue($this->product->id, $this->location->id, 4, 'dipakai');

        $this->get(route('admin.stock-movements.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('movements.data', 2)
                ->where('movements.data', function ($rows): bool {
                    $byType = collect($rows)->keyBy('movement_type');

                    $this->assertSame('10.00', $byType['receive']['balance_after']);
                    $this->assertSame(['id' => $this->manager->public_id, 'name' => 'Maya Gudang'], $byType['receive']['actor']);
                    $this->assertSame('PT Kabel Jaya', $byType['receive']['supplier_name']);
                    $this->assertSame('INV-7', $byType['receive']['purchase_reference']);
                    $this->assertSame('2500.00', $byType['receive']['unit_cost']);
                    $this->assertSame('6.00', $byType['issue']['balance_after']);
                    $this->assertNull($byType['issue']['supplier_name']);
                    $this->assertNull($byType['issue']['unit_cost']);

                    return true;
                }));
    }

    public function test_the_actors_cost_one_query_however_many_movements_there_are(): void
    {
        $this->bookMovements(3);
        $this->queriesOfThePage(); // the first request of a test also loads the permission cache
        $few = $this->queriesOfThePage();

        $this->bookMovements(9);
        $many = $this->queriesOfThePage();

        $this->assertSame($few, $many, 'a page of 12 movements by 12 people reads as many tables as a page of 3');
    }

    private function bookMovements(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $actor = User::factory()->create(['company_id' => $this->company->id]);

            StockMovement::query()->create([
                'product_id' => $this->product->id,
                'to_location_id' => $this->location->id,
                'movement_type' => 'receive',
                'quantity' => 1,
                'balance_after' => 1,
                'reserved_after' => 0,
                'created_by' => $actor->id,
            ]);
        }
    }

    private function queriesOfThePage(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('admin.stock-movements.index', ['per_page' => 25]))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
