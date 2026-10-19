<?php

namespace Tests\Feature\Inventory;

use App\Models\Core\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\InventoryRequestItem;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Services\InventoryRequestService;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/**
 * One lock order for stock everywhere: Product, then Location, then Stock (StockService::assertProductLocation, then
 * lockedStock). A path that locks the stock row first deadlocks with every other path, so approval no longer does.
 */
class InventoryLockOrderTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    public function test_request_approval_locks_product_and_location_before_any_stock_row(): void
    {
        $approver = $this->createCompanyUser();
        $requester = User::factory()->create(['company_id' => $approver->company_id]);
        $product = Product::factory()->create(['company_id' => $approver->company_id]);
        $warehouse = Location::factory()->create(['company_id' => $approver->company_id, 'type' => 'site']);
        Stock::query()->create(['company_id' => $approver->company_id, 'product_id' => $product->id, 'location_id' => $warehouse->id, 'quantity' => 10, 'reserved_quantity' => 0]);
        $request = InventoryRequest::query()->create([
            'company_id' => $approver->company_id,
            'code' => 'REQ-LOCK-0001',
            'requested_by' => $requester->id,
            'warehouse_location_id' => $warehouse->id,
            'status' => 'submitted',
            'reason' => 'Urutan kunci',
        ]);
        InventoryRequestItem::query()->create(['company_id' => $approver->company_id, 'inventory_request_id' => $request->id, 'product_id' => $product->id, 'source_location_id' => $warehouse->id, 'quantity_requested' => 4]);
        $this->actingAs($approver);

        DB::enableQueryLog();
        InventoryRequestService::approve($request, $approver);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $firstLock = function (string $table) use ($log): ?int {
            foreach ($log as $index => $query) {
                if (str_contains($query['query'], 'from `'.$table.'`') && str_ends_with(trim($query['query']), 'for update')) {
                    return $index;
                }
            }

            return null;
        };

        $productLock = $firstLock('products');
        $locationLock = $firstLock('locations');
        $stockLock = $firstLock('stocks');

        $this->assertNotNull($productLock, 'approval locks the product');
        $this->assertNotNull($locationLock, 'approval locks the location');
        $this->assertNotNull($stockLock, 'approval locks the stock row');
        $this->assertLessThan($stockLock, $productLock, 'the product is locked before the stock row');
        $this->assertLessThan($stockLock, $locationLock, 'the location is locked before the stock row');
        $this->assertLessThan($locationLock, $productLock, 'the product is locked before the location');
        $this->assertSame('4.00', Stock::withoutCompany()->where('location_id', $warehouse->id)->sole()->reserved_quantity);
    }
}
