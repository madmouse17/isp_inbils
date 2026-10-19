<?php

namespace Tests\Feature\SPK;

use App\Models\Core\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderItem;
use Modules\SPK\Services\SpkService;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/**
 * "Jumlah dicadangkan" on an SPK item is a plan, not a reservation: nothing reserves it, so nothing releases it. A line
 * that still carries one (old data) never stops a cancel, a rejection or an approval, and never moves the reservation
 * of another request.
 */
class SpkPlannedQuantityRemovalTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private User $admin;

    private User $creator;

    private Location $pop;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->admin = $this->createCompanyUser();
        $this->creator = User::factory()->create(['company_id' => $this->admin->company_id]);
        $this->pop = Location::factory()->create(['company_id' => $this->admin->company_id, 'type' => 'site']);
        $this->product = Product::factory()->create(['company_id' => $this->admin->company_id, 'type' => 'consumable']);
    }

    public function test_cancelling_or_rejecting_an_spk_with_a_legacy_planned_quantity_succeeds(): void
    {
        $this->stock(quantity: '5', reserved: '0');
        $cancelled = $this->workOrder('assigned', planned: '3');
        $rejected = $this->workOrder('waiting_review', planned: '3');

        $this->actingAs($this->admin);
        SpkService::cancel($cancelled, 'Pelanggan batal');
        SpkService::reject($rejected, 'Foto kurang jelas');

        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->assertSame('rejected', $rejected->fresh()->status);
        $this->assertSame(['3.00', '3.00'], WorkOrderItem::query()->orderBy('id')->pluck('quantity_reserved')->all(), 'the plan is left as it was');
        $this->assertSame(['5.00', '0.00'], [$this->stockRow()->quantity, $this->stockRow()->reserved_quantity]);
        $this->assertSame(0, DB::table('stock_movements')->where('movement_type', 'release')->count());
    }

    public function test_approval_leaves_another_requests_reservation_alone(): void
    {
        // An approved request reserved 5 at the POP; the SPK's own plan of 2 is not what holds them.
        $this->stock(quantity: '20', reserved: '5');
        $workOrder = $this->workOrder('waiting_review', planned: '2', used: '2');
        $workOrder->addMediaFromString('evidence')
            ->usingFileName('proof.jpg')
            ->withCustomProperties(['company_id' => $workOrder->company_id, 'type' => 'photo', 'uploaded_by' => $this->creator->id])
            ->toMediaCollection('evidence', 'public');

        $this->actingAs($this->admin);
        SpkService::approve($workOrder);

        $this->assertSame('completed', $workOrder->fresh()->status);
        $this->assertSame(['18.00', '5.00'], [$this->stockRow()->quantity, $this->stockRow()->reserved_quantity], '2 used, the 5 reserved by the request stay');
        $this->assertSame('2.00', WorkOrderItem::query()->sole()->quantity_reserved);
    }

    public function test_the_item_form_ignores_a_planned_quantity(): void
    {
        $workOrder = $this->workOrder('generated');

        $this->actingAs($this->admin)
            ->post(route('admin.spk.items.store', $workOrder), ['product_id' => $this->product->public_id, 'quantity_reserved' => 5, 'quantity_used' => 2])
            ->assertRedirect();

        $item = WorkOrderItem::query()->sole();
        $this->assertSame(['0.00', '2.00'], [$item->quantity_reserved, $item->quantity_used], 'a new line has no plan');

        $item->update(['quantity_reserved' => 3]);
        $this->post(route('admin.spk.items.store', $workOrder), ['product_id' => $this->product->public_id, 'quantity_reserved' => 9, 'quantity_used' => 4])
            ->assertRedirect();

        $item = $item->fresh();
        $this->assertSame(['3.00', '4.00'], [$item->quantity_reserved, $item->quantity_used], 'an edit leaves an old plan alone');
    }

    private function stock(string $quantity, string $reserved): void
    {
        Stock::create(['company_id' => $this->admin->company_id, 'product_id' => $this->product->id, 'location_id' => $this->pop->id, 'quantity' => $quantity, 'reserved_quantity' => $reserved]);
    }

    private function stockRow(): Stock
    {
        return Stock::query()->where('product_id', $this->product->id)->where('location_id', $this->pop->id)->sole();
    }

    private function workOrder(string $status, ?string $planned = null, string $used = '0'): WorkOrder
    {
        $workOrder = WorkOrderFactory::new()->create([
            'company_id' => $this->admin->company_id,
            'type' => 'maintenance',
            'status' => $status,
            'location_id' => $this->pop->id,
            'created_by' => $this->creator->id,
        ]);

        if ($planned !== null) {
            WorkOrderItem::create(['company_id' => $workOrder->company_id, 'work_order_id' => $workOrder->id, 'product_id' => $this->product->id, 'quantity_reserved' => $planned, 'quantity_used' => $used]);
        }

        return $workOrder;
    }
}
