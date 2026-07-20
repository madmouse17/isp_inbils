<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Inventory\Exceptions\InsufficientStockException;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Services\StockService;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use DatabaseTransactions;

    private int $productId;

    private int $locationId;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        $this->actingAs(User::factory()->create(['company_id' => $company->id]));

        $category = Category::query()->create(['name' => 'Routers', 'code' => 'RTR']);
        $unit = Unit::query()->create(['name' => 'Piece', 'symbol' => 'PCS']);
        $this->productId = Product::query()->create([
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku' => 'RTR-001',
            'name' => 'Router',
            'type' => 'consumable',
            'track_stock' => true,
            'min_stock' => 0,
            'is_active' => true,
        ])->id;
        $this->locationId = $this->createLocation('WH-A')->id;
    }

    public function test_issue_uses_available_stock_and_keeps_quantity_non_negative(): void
    {
        StockService::receive($this->productId, $this->locationId, 10);
        StockService::reserve($this->productId, $this->locationId, 4);

        $this->expectException(InsufficientStockException::class);

        StockService::issue($this->productId, $this->locationId, 7);
    }

    public function test_adjustment_cannot_drop_below_reserved_quantity(): void
    {
        StockService::receive($this->productId, $this->locationId, 10);
        StockService::reserve($this->productId, $this->locationId, 4);

        $this->expectException(InsufficientStockException::class);

        StockService::adjust($this->productId, $this->locationId, 3, 'cycle count');
    }

    public function test_release_cannot_make_reserved_quantity_negative(): void
    {
        StockService::receive($this->productId, $this->locationId, 10);
        StockService::reserve($this->productId, $this->locationId, 4);

        $this->expectException(InsufficientStockException::class);

        StockService::release($this->productId, $this->locationId, 5);
    }

    public function test_reference_idempotency_reuses_existing_mutation(): void
    {
        $first = StockService::receive($this->productId, $this->locationId, 10, refType: 'purchase_order', refId: 99);
        $second = StockService::receive($this->productId, $this->locationId, 10, refType: 'purchase_order', refId: 99);

        $stock = Stock::query()->firstOrFail();

        $this->assertTrue($first->is($second));
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertEquals(10.00, (float) $stock->quantity);
    }

    public function test_reference_idempotency_is_scoped_to_product_and_location(): void
    {
        $otherProductId = $this->createProduct('RTR-002')->id;
        $otherLocationId = $this->createLocation('WH-B')->id;

        $first = StockService::receive($this->productId, $this->locationId, 10, refType: 'purchase_order', refId: 99);
        $sameReferenceDifferentProduct = StockService::receive($otherProductId, $this->locationId, 5, refType: 'purchase_order', refId: 99);
        $sameReferenceDifferentLocation = StockService::receive($this->productId, $otherLocationId, 3, refType: 'purchase_order', refId: 99);
        $duplicate = StockService::receive($this->productId, $this->locationId, 10, refType: 'purchase_order', refId: 99);

        $this->assertFalse($first->is($sameReferenceDifferentProduct));
        $this->assertFalse($first->is($sameReferenceDifferentLocation));
        $this->assertTrue($first->is($duplicate));
        $this->assertSame(3, StockMovement::query()->count());
        $this->assertEquals(18.00, Stock::query()->sum('quantity'));
    }

    public function test_transfer_locks_rows_in_stable_order_and_preserves_total(): void
    {
        $toLocationId = $this->createLocation('WH-B')->id;

        StockService::receive($this->productId, $this->locationId, 10);
        StockService::transfer($this->productId, $this->locationId, $toLocationId, 6, 'truck');

        $fromStock = Stock::query()->where('location_id', $this->locationId)->firstOrFail();
        $toStock = Stock::query()->where('location_id', $toLocationId)->firstOrFail();

        $this->assertEquals(4.00, (float) $fromStock->quantity);
        $this->assertEquals(6.00, (float) $toStock->quantity);
        $this->assertEquals(10.00, Stock::query()->sum('quantity'));
    }

    private function createLocation(string $code): Location
    {
        return Location::query()->create([
            'code' => $code,
            'name' => $code,
            'type' => 'warehouse',
            'path' => $code,
            'is_active' => true,
        ]);
    }

    private function createProduct(string $sku): Product
    {
        return Product::query()->create([
            'category_id' => Category::query()->firstOrFail()->id,
            'unit_id' => Unit::query()->firstOrFail()->id,
            'sku' => $sku,
            'name' => $sku,
            'type' => 'consumable',
            'track_stock' => true,
            'min_stock' => 0,
            'is_active' => true,
        ]);
    }
}
