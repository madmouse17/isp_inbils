<?php

namespace Tests\Feature\Inventory;

use App\Models\Core\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\InventoryRequestItem;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\Inventory\Models\MaterialReturnLine;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\MaterialReturnService;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderItem;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\RunsConcurrentWorkers;

/**
 * Receiving a return, or resolving its exception, reconciles the material of the SPK it came from. Both take that SPK's
 * row first (WorkOrder, then the return case, then stock) like SPK approval does, so a reconcile never interleaves with
 * an approval or with the material a technician adds to the same SPK.
 */
class MaterialReturnWorkOrderLockTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;
    use RunsConcurrentWorkers;

    private User $admin;

    private User $technician;

    private User $receiver;

    private User $resolver;

    private Location $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
        $company = (int) $this->admin->company_id;
        $this->technician = User::factory()->create(['company_id' => $company]);
        $this->receiver = User::factory()->create(['company_id' => $company]);
        $this->receiver->givePermissionTo('inventory.return.receive');
        $this->resolver = User::factory()->create(['company_id' => $company]);
        $this->resolver->givePermissionTo('inventory.return.resolve');
        $this->warehouse = Location::factory()->create(['company_id' => $company, 'type' => 'site']);
        $this->actingAs($this->receiver);
    }

    public function test_receiving_and_resolving_a_return_wait_for_the_work_order_lock(): void
    {
        $workOrder = $this->workOrder();
        // The case names its SPK itself here, and only through the request it came from there.
        [$awaitingReceipt, $received] = $this->returnCase('awaiting_receipt', $workOrder, linkOnCase: true);
        [$exceptionOpen] = $this->returnCase('exception_open', $workOrder, linkOnCase: false);
        $this->commitFixture();

        try {
            $receiving = fn () => MaterialReturnService::receiveAccept($awaitingReceipt, $this->receiver, [
                'lines' => [['id' => $received->id, 'accepted_good_return_qty' => 3, 'damaged_return_qty' => 0, 'lost_qty' => 0, 'other_discrepancy_qty' => 0]],
            ]);
            $resolving = fn () => MaterialReturnService::resolveException($exceptionOpen, $this->resolver, 'Selisih dihapus buku');

            $this->assertTrue($this->waitsForRowLock('work_orders', $workOrder->id, $receiving), 'receiving waits for the SPK row');
            $this->assertTrue($this->waitsForRowLock('work_orders', $workOrder->id, $resolving), 'resolving waits for the SPK row');

            $this->assertSame('awaiting_receipt', MaterialReturnCase::withoutCompany()->findOrFail($awaitingReceipt->id)->status);
            $this->assertSame('exception_open', MaterialReturnCase::withoutCompany()->findOrFail($exceptionOpen->id)->status);
            $this->assertSame(0, StockMovement::withoutCompany()->where('company_id', $this->admin->company_id)->count(), 'nothing was received into stock');
        } finally {
            $this->purgeCompany((int) $this->admin->company_id);
        }
    }

    public function test_a_return_that_belongs_to_no_spk_is_received_without_one(): void
    {
        [$case, $line] = $this->returnCase('awaiting_receipt', null, linkOnCase: false);

        $received = MaterialReturnService::receiveAccept($case, $this->receiver, [
            'lines' => [['id' => $line->id, 'accepted_good_return_qty' => 3, 'damaged_return_qty' => 0, 'lost_qty' => 0, 'other_discrepancy_qty' => 0]],
        ]);

        $this->assertSame('reconciled', $received->status);
        $this->assertSame(1, StockMovement::withoutCompany()->where('movement_type', 'return')->count());
    }

    private function workOrder(): WorkOrder
    {
        return WorkOrderFactory::new()->create([
            'company_id' => $this->admin->company_id,
            'status' => 'in_progress',
            'assigned_to' => $this->technician->id,
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * A return of 5 handed over: 2 used and 3 given back (awaiting receipt), or 2 used, 2 given back and 1 lost (exception).
     * The SPK already lists the 2 used, so reconciling it only updates its item: without the SPK lock nothing in the old
     * code reads or writes the SPK row itself, and the test fails for the right reason.
     *
     * @return array{0: MaterialReturnCase, 1: MaterialReturnLine}
     */
    private function returnCase(string $status, ?WorkOrder $workOrder, bool $linkOnCase): array
    {
        $company = (int) $this->admin->company_id;
        $product = Product::factory()->create(['company_id' => $company]);
        $request = InventoryRequest::query()->create([
            'company_id' => $company,
            'code' => 'REQ-'.fake()->unique()->numerify('######'),
            'requested_by' => $this->technician->id,
            'approved_by' => $this->admin->id,
            'issued_by' => $this->admin->id,
            'warehouse_location_id' => $this->warehouse->id,
            'source_type' => $workOrder === null ? null : 'work_order',
            'source_id' => $workOrder?->id,
            'status' => 'issued',
            'reason' => 'Material pasang baru',
        ]);
        $item = InventoryRequestItem::query()->create([
            'company_id' => $company,
            'inventory_request_id' => $request->id,
            'product_id' => $product->id,
            'source_location_id' => $this->warehouse->id,
            'quantity_requested' => 5,
            'quantity_approved' => 5,
            'quantity_issued' => 5,
        ]);
        $exception = $status === 'exception_open';
        $case = MaterialReturnCase::query()->create([
            'company_id' => $company,
            'inventory_request_id' => $request->id,
            'work_order_id' => $linkOnCase ? $workOrder?->id : null,
            'warehouse_location_id' => $this->warehouse->id,
            'technician_id' => $this->technician->id,
            'status' => $status,
            'declaration_version' => 2,
            'received_by' => $exception ? $this->receiver->id : null,
        ]);
        $line = MaterialReturnLine::query()->create([
            'company_id' => $company,
            'material_return_case_id' => $case->id,
            'inventory_request_item_id' => $item->id,
            'product_id' => $product->id,
            'issued_qty' => 5,
            'actual_used_qty' => 2,
            'declared_good_return_qty' => $exception ? 2 : 3,
            'declared_discrepancy_qty' => $exception ? 1 : 0,
            'accepted_good_return_qty' => $exception ? 2 : 0,
            'lost_qty' => $exception ? 1 : 0,
            'other_discrepancy_qty' => 0,
            'discrepancy_code' => $exception ? 'lost' : null,
            'discrepancy_reason' => $exception ? 'Hilang di lapangan' : null,
        ]);

        if ($workOrder !== null) {
            WorkOrderItem::query()->create(['company_id' => $company, 'work_order_id' => $workOrder->id, 'product_id' => $product->id, 'quantity_reserved' => 0, 'quantity_used' => 2]);
        }

        return [$case, $line];
    }
}
