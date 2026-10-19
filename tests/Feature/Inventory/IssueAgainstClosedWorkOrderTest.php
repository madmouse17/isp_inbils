<?php

namespace Tests\Feature\Inventory;

use App\Models\Core\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\InventoryRequestItem;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\InventoryRequestService;
use Modules\Inventory\Support\InventoryRequestSources;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\RunsConcurrentWorkers;

/**
 * Material is handed over for an SPK that is still open. A request whose SPK was completed or cancelled in the meantime
 * is refused (422) and nothing leaves the warehouse: SPK approval already counted that SPK's material, and a
 * handover after it would take the same stock twice. The handover waits for the SPK row the approval holds.
 */
class IssueAgainstClosedWorkOrderTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;
    use RunsConcurrentWorkers;

    private const CLOSED_MESSAGE = 'SPK sumber sudah selesai atau dibatalkan; barang tidak bisa diserahterimakan.';

    private User $admin;

    private User $technician;

    private User $approver;

    private User $warehouseStaff;

    private Location $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
        $company = (int) $this->admin->company_id;
        $this->technician = User::factory()->create(['company_id' => $company]);
        $this->approver = User::factory()->create(['company_id' => $company]);
        $this->warehouseStaff = User::factory()->create(['company_id' => $company]);
        $this->warehouse = Location::factory()->create(['company_id' => $company, 'type' => 'site']);
    }

    public function test_a_request_for_a_completed_or_cancelled_spk_cannot_be_handed_over(): void
    {
        $this->actingAs($this->warehouseStaff);

        foreach (['completed', 'cancelled'] as $status) {
            [$request, $stock] = $this->readyRequest($this->workOrder($status));

            $this->assertRefused($request);
            $this->assertUntouched($request, $stock);
        }
    }

    public function test_a_request_for_a_deleted_spk_cannot_be_handed_over(): void
    {
        $workOrder = $this->workOrder('draft');
        [$request, $stock] = $this->readyRequest($workOrder);
        $workOrder->delete();
        $this->actingAs($this->warehouseStaff);

        $this->assertRefused($request);
        $this->assertUntouched($request, $stock);
    }

    public function test_a_request_for_an_open_spk_or_for_none_is_still_handed_over(): void
    {
        $this->actingAs($this->warehouseStaff);

        foreach ([$this->workOrder('assigned'), $this->workOrder('in_progress'), null] as $workOrder) {
            [$request, $stock] = $this->readyRequest($workOrder);

            $issued = InventoryRequestService::issue($request, $this->warehouseStaff);

            $this->assertSame('issued', $issued->status);
            $this->assertSame('5.00', $stock->fresh()->quantity);
            $this->assertSame('0.00', $stock->fresh()->reserved_quantity);
            $this->assertSame(1, MaterialReturnCase::withoutCompany()->where('inventory_request_id', $request->id)->count());
        }
    }

    public function test_a_request_already_handed_over_keeps_its_own_refusal_when_the_spk_has_closed_since(): void
    {
        $workOrder = $this->workOrder('in_progress');
        [$request] = $this->readyRequest($workOrder);
        $this->actingAs($this->warehouseStaff);
        InventoryRequestService::issue($request, $this->warehouseStaff);
        $workOrder->update(['status' => 'completed']);

        try {
            InventoryRequestService::issue($request, $this->warehouseStaff);
            $this->fail('A request that was handed over cannot be handed over again.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('Hanya permintaan berstatus siap diambil yang dapat diserahterimakan.', $e->getMessage());
        }
    }

    public function test_the_source_picker_and_the_web_form_do_not_offer_a_closed_spk(): void
    {
        $open = $this->workOrder('in_progress');
        $completed = $this->workOrder('completed');
        $cancelled = $this->workOrder('cancelled');
        $this->actingAs($this->admin);

        $this->assertSame([$open->id], InventoryRequestSources::workOrderBase($this->admin)->pluck('id')->all());

        $payload = fn (WorkOrder $workOrder): array => [
            'source_type' => 'work_order',
            'source_id' => $workOrder->public_id,
            'reason' => 'Material pasang baru',
            'items' => [['product_id' => Product::factory()->create(['company_id' => $this->admin->company_id])->public_id, 'quantity_requested' => 2]],
        ];

        foreach ([$completed, $cancelled] as $closed) {
            $this->postJson(route('admin.inventory.requests.store'), $payload($closed))
                ->assertStatus(422)
                ->assertJsonPath('errors.source_id.0', 'SPK yang dipilih tidak valid untuk perusahaan ini.');
        }
        $this->postJson(route('admin.inventory.requests.store'), $payload($open))->assertCreated();
    }

    public function test_handover_waits_for_the_work_order_lock(): void
    {
        $workOrder = $this->workOrder('in_progress');
        [$request, $stock] = $this->readyRequest($workOrder);
        $this->actingAs($this->warehouseStaff);
        $this->commitFixture();

        try {
            $waited = $this->waitsForRowLock('work_orders', $workOrder->id, fn () => InventoryRequestService::issue($request, $this->warehouseStaff));

            $this->assertTrue($waited, 'the handover has to wait for the SPK row that an approval holds');
            $this->assertUntouched($request, $stock);
        } finally {
            $this->purgeCompany((int) $this->admin->company_id);
        }
    }

    public function test_handover_locks_the_work_order_before_the_request_its_items_and_the_stock(): void
    {
        $workOrder = $this->workOrder('in_progress');
        [$request] = $this->readyRequest($workOrder);
        $this->actingAs($this->warehouseStaff);

        DB::enableQueryLog();
        InventoryRequestService::issue($request, $this->warehouseStaff);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // The lane's lock order: SPK, request, its items, then product, location and stock row.
        $firstLocks = [];
        foreach (['work_orders', 'inventory_requests', 'inventory_request_items', 'products', 'locations', 'stocks'] as $table) {
            foreach ($log as $index => $query) {
                if (str_contains($query['query'], 'from `'.$table.'`') && str_ends_with(trim($query['query']), 'for update')) {
                    $firstLocks[$table] = $index;

                    break;
                }
            }
        }

        $this->assertSame(['work_orders', 'inventory_requests', 'inventory_request_items', 'products', 'locations', 'stocks'], array_keys($firstLocks), 'every table of the order is locked');
        $sorted = $firstLocks;
        asort($sorted);
        $this->assertSame(array_keys($firstLocks), array_keys($sorted), 'and in this order: '.json_encode($firstLocks));
    }

    private function workOrder(string $status): WorkOrder
    {
        return WorkOrderFactory::new()->create([
            'company_id' => $this->admin->company_id,
            'status' => $status,
            'assigned_to' => $this->technician->id,
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * A request waiting to be handed over: 5 reserved from a stock of 10, so a handover leaves 5.
     *
     * @return array{0: InventoryRequest, 1: Stock}
     */
    private function readyRequest(?WorkOrder $workOrder): array
    {
        $company = (int) $this->admin->company_id;
        $product = Product::factory()->create(['company_id' => $company]);
        $stock = Stock::query()->create(['company_id' => $company, 'product_id' => $product->id, 'location_id' => $this->warehouse->id, 'quantity' => 10, 'reserved_quantity' => 5]);
        $request = InventoryRequest::query()->create([
            'company_id' => $company,
            'code' => 'REQ-'.fake()->unique()->numerify('######'),
            'requested_by' => $this->technician->id,
            'approved_by' => $this->approver->id,
            'warehouse_location_id' => $this->warehouse->id,
            'source_type' => $workOrder === null ? null : 'work_order',
            'source_id' => $workOrder?->id,
            'status' => 'ready_for_pickup',
            'reason' => 'Material pasang baru',
        ]);
        InventoryRequestItem::query()->create([
            'company_id' => $company,
            'inventory_request_id' => $request->id,
            'product_id' => $product->id,
            'source_location_id' => $this->warehouse->id,
            'quantity_requested' => 5,
            'quantity_approved' => 5,
        ]);

        return [$request, $stock];
    }

    private function assertRefused(InventoryRequest $request): void
    {
        try {
            InventoryRequestService::issue($request, $this->warehouseStaff);
            $this->fail('A request for a closed SPK cannot be handed over.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame(self::CLOSED_MESSAGE, $e->getMessage());
        }
    }

    private function assertUntouched(InventoryRequest $request, Stock $stock): void
    {
        $this->assertSame('ready_for_pickup', InventoryRequest::withoutCompany()->findOrFail($request->id)->status);
        $this->assertSame(['10.00', '5.00'], [$stock->fresh()->quantity, $stock->fresh()->reserved_quantity], 'the warehouse still holds the stock, still reserved');
        $this->assertSame(0, StockMovement::withoutCompany()->where('product_id', $stock->product_id)->count());
        $this->assertSame(0, MaterialReturnCase::withoutCompany()->where('inventory_request_id', $request->id)->count());
    }
}
