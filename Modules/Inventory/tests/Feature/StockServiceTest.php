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
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderItem;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

        $unit = Unit::query()->create(['name' => 'Piece', 'symbol' => 'PCS']);
        $category = Category::query()->create(['name' => 'Routers', 'code' => 'RTR', 'unit_id' => $unit->id]);
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

    public function test_free_text_reference_type_rejects_without_writing(): void
    {
        try {
            StockService::receive($this->productId, $this->locationId, 10, refType: 'purchase_order', refId: 99);
            $this->fail('Free-text stock reference must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, Stock::query()->sum('quantity'));
    }

    public function test_work_order_item_reference_idempotency_reuses_existing_mutation(): void
    {
        $item = $this->workOrderItem();

        $first = StockService::receive($this->productId, $this->locationId, 10, refType: WorkOrderItem::class, refId: $item->id);
        $second = StockService::receive($this->productId, $this->locationId, 10, refType: WorkOrderItem::class, refId: $item->id);

        $stock = Stock::query()->firstOrFail();

        $this->assertTrue($first->is($second));
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertEquals(10.00, (float) $stock->quantity);
    }

    public function test_work_order_item_reference_is_scoped_to_product_and_location(): void
    {
        $otherProductId = $this->createProduct('RTR-002')->id;
        $otherLocationId = $this->createLocation('WH-B')->id;
        $item = $this->workOrderItem();

        $first = StockService::receive($this->productId, $this->locationId, 10, refType: WorkOrderItem::class, refId: $item->id);
        $sameReferenceDifferentProduct = StockService::receive($otherProductId, $this->locationId, 5, refType: WorkOrderItem::class, refId: $item->id);
        $sameReferenceDifferentLocation = StockService::receive($this->productId, $otherLocationId, 3, refType: WorkOrderItem::class, refId: $item->id);
        $duplicate = StockService::receive($this->productId, $this->locationId, 10, refType: WorkOrderItem::class, refId: $item->id);

        $this->assertFalse($first->is($sameReferenceDifferentProduct));
        $this->assertFalse($first->is($sameReferenceDifferentLocation));
        $this->assertTrue($first->is($duplicate));
        $this->assertSame(3, StockMovement::query()->count());
        $this->assertEquals(18.00, Stock::query()->sum('quantity'));
    }

    public function test_work_order_item_reference_must_belong_to_current_company(): void
    {
        $foreign = Company::factory()->create();
        $foreignItem = $this->workOrderItem($foreign->id);

        try {
            StockService::receive($this->productId, $this->locationId, 10, refType: WorkOrderItem::class, refId: $foreignItem->id);
            $this->fail('Cross-company stock reference must be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, StockMovement::query()->count());
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

    private function workOrderItem(?int $companyId = null): WorkOrderItem
    {
        $companyId ??= auth()->user()->company_id;
        $workOrder = WorkOrder::withoutCompany()->forceCreate([
            'company_id' => $companyId,
            'code' => 'STK-WO-'.fake()->unique()->numberBetween(1, 9999),
            'type' => 'maintenance',
            'title' => 'Stock reference',
            'status' => 'draft',
            'source' => 'manual',
            'priority' => 'medium',
            'created_by' => auth()->id(),
        ]);

        return WorkOrderItem::withoutCompany()->forceCreate([
            'company_id' => $companyId,
            'work_order_id' => $workOrder->id,
            'product_id' => $this->productId,
            'quantity_reserved' => 0,
            'quantity_used' => 0,
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
