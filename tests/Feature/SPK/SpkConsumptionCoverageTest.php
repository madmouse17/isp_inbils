<?php

namespace Tests\Feature\SPK;

use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\User;
use Illuminate\Database\DeadlockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\InventoryRequestItem;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderItem;
use Modules\SPK\Services\SpkService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Tests\Traits\RunsConcurrentWorkers;

/**
 * Approving an SPK takes out of stock only what its handed-over requests did not. The coverage is read under lock, so
 * an issue that commits during the approval is never missed, and what a covered product used beyond its handed-over
 * quantity leaves the warehouse the request left, not the SPK's POP.
 */
class SpkConsumptionCoverageTest extends TestCase
{
    use RefreshDatabase;
    use RunsConcurrentWorkers;

    private Company $company;

    private User $creator;

    private Location $warehouse;

    private Location $pop;

    private Product $product;

    private WorkOrder $workOrder;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Permission::firstOrCreate(['name' => 'spk.approve', 'guard_name' => 'web']);

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->creator = User::factory()->create(['company_id' => $this->company->id]);
        $approver = User::factory()->create(['company_id' => $this->company->id]);
        $approver->givePermissionTo('spk.approve');
        $this->warehouse = $this->location('WH');
        $this->pop = $this->location('POP');
        $this->product = $this->product();
        $this->workOrder = WorkOrderFactory::new()->create([
            'company_id' => $this->company->id,
            'type' => 'maintenance',
            'status' => 'waiting_review',
            'location_id' => $this->pop->id,
            'created_by' => $this->creator->id,
        ]);
        $this->workOrder->addMediaFromString('evidence')
            ->usingFileName('proof.jpg')
            ->withCustomProperties(['company_id' => $this->company->id, 'type' => 'photo', 'uploaded_by' => $this->creator->id])
            ->toMediaCollection('evidence', 'public');

        $this->actingAs($approver);
    }

    public function test_approval_reads_issued_requests_under_lock(): void
    {
        $request = $this->handedOver($this->product, '10', left: '10');
        $this->used($this->product, '4');
        $this->commitFixture();

        try {
            $waited = $this->waitsForRowLock('inventory_requests', $request->id, function (): void {
                try {
                    SpkService::approve($this->workOrder);
                } catch (DeadlockException $e) {
                    // approve() nests the transaction of CompleteSpkAction; Laravel wraps a lock wait raised in a nested one.
                    throw $e->getPrevious() ?? $e;
                }
            });

            $this->assertTrue($waited, 'the coverage of an SPK must be a locking read of its issued requests');
        } finally {
            $this->purgeCompany($this->company->id);
        }
    }

    public function test_usage_above_the_handed_over_quantity_is_taken_from_the_request_warehouse(): void
    {
        $this->handedOver($this->product, '10', left: '5');
        $this->used($this->product, '12');

        SpkService::approve($this->workOrder);

        $this->assertSame('completed', $this->workOrder->fresh()->status, 'an empty POP does not stop the approval');
        $this->assertSame('3.00', $this->quantity($this->product, $this->warehouse), 'the 2 beyond the handed-over 10 left the warehouse');
        $issues = DB::table('stock_movements')->where('movement_type', 'issue')->where('reference_type', WorkOrderItem::class)->get();
        $this->assertCount(1, $issues);
        $this->assertSame($this->warehouse->id, (int) $issues[0]->from_location_id);
        $this->assertEquals(-2, (float) $issues[0]->quantity);
        $this->assertNull(Stock::query()->where('location_id', $this->pop->id)->first(), 'nothing was looked up or created at the POP');
    }

    public function test_handed_over_material_is_never_issued_again_at_approval(): void
    {
        $this->handedOver($this->product, '10', left: '10');
        $this->used($this->product, '10');

        SpkService::approve($this->workOrder);

        $this->assertSame('completed', $this->workOrder->fresh()->status);
        $this->assertSame('10.00', $this->quantity($this->product, $this->warehouse));
        $this->assertSame(0, DB::table('stock_movements')->where('movement_type', 'issue')->where('reference_type', WorkOrderItem::class)->count());
    }

    public function test_material_without_a_handed_over_request_is_still_issued_at_the_spk_location(): void
    {
        $other = $this->product();
        $this->handedOver($this->product, '10', left: '10');
        $this->used($this->product, '10');
        $this->stock($other, $this->pop, '4');
        $this->used($other, '3');

        SpkService::approve($this->workOrder);

        $this->assertSame('1.00', $this->quantity($other, $this->pop));
        $this->assertSame('10.00', $this->quantity($this->product, $this->warehouse));
        $this->assertNull(Stock::query()->where('product_id', $other->id)->where('location_id', $this->warehouse->id)->first());
    }

    public function test_the_rest_of_a_product_handed_over_from_two_warehouses_comes_from_the_last_one(): void
    {
        $second = $this->location('WH2');
        $this->handedOver($this->product, '4', left: '6');
        $this->handedOver($this->product, '6', left: '2', from: $second);
        $this->used($this->product, '12');

        SpkService::approve($this->workOrder);

        $this->assertSame('6.00', $this->quantity($this->product, $this->warehouse), 'the first warehouse is left alone');
        $this->assertSame('0.00', $this->quantity($this->product, $second), 'the 2 beyond the 4 + 6 handed over left the last warehouse');
        $this->assertNull(Stock::query()->where('location_id', $this->pop->id)->first());
    }

    private function location(string $code): Location
    {
        return Location::factory()->create(['company_id' => $this->company->id, 'code' => $code.'-'.fake()->unique()->numerify('####'), 'type' => 'site']);
    }

    private function product(): Product
    {
        return Product::factory()->create(['company_id' => $this->company->id, 'type' => 'consumable']);
    }

    private function stock(Product $product, Location $location, string $quantity): void
    {
        Stock::create(['company_id' => $this->company->id, 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => $quantity, 'reserved_quantity' => 0]);
    }

    private function quantity(Product $product, Location $location): string
    {
        return (string) Stock::query()->where('product_id', $product->id)->where('location_id', $location->id)->value('quantity');
    }

    private function used(Product $product, string $quantity): void
    {
        WorkOrderItem::create(['company_id' => $this->company->id, 'work_order_id' => $this->workOrder->id, 'product_id' => $product->id, 'quantity_used' => $quantity]);
    }

    /** An issued request for the SPK: $quantity of $product left $from, which keeps $left of it. */
    private function handedOver(Product $product, string $quantity, string $left, ?Location $from = null): InventoryRequest
    {
        $from ??= $this->warehouse;
        $request = InventoryRequest::create([
            'company_id' => $this->company->id,
            'code' => 'REQ-'.fake()->unique()->numerify('######'),
            'requested_by' => $this->creator->id,
            'warehouse_location_id' => $from->id,
            'reason' => 'Material untuk SPK',
            'source_type' => 'work_order',
            'source_id' => $this->workOrder->id,
            'status' => 'issued',
        ]);
        InventoryRequestItem::create([
            'company_id' => $this->company->id,
            'inventory_request_id' => $request->id,
            'product_id' => $product->id,
            'source_location_id' => $from->id,
            'quantity_requested' => $quantity,
            'quantity_approved' => $quantity,
            'quantity_issued' => $quantity,
        ]);
        $this->stock($product, $from, $left);

        return $request;
    }
}
