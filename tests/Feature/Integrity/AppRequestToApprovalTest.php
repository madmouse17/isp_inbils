<?php

namespace Tests\Feature\Integrity;

use App\Models\Core\Location;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Services\StockService;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderItem;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

/**
 * The life of the material a technician asks for in the app for an SPK, over the real routes: the request carries the
 * SPK, the warehouse hands it over, the technician declares what was used and what goes back, and approving the SPK
 * takes nothing out of stock a second time. Use beyond the handed-over quantity leaves the warehouse the request left,
 * once. The expectation is the whole stock ledger of the product, so one movement too many fails it.
 */
class AppRequestToApprovalTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    /** The first four movements of both flows: the stock comes in, then 10 are reserved, released and issued to the technician. */
    private const HANDED_OVER = [
        'receive 100.00 at WH by -',
        'reserve 10.00 at WH by InventoryRequestItem',
        'release -10.00 at WH by InventoryRequestItem',
        'issue -10.00 at WH by InventoryRequestItem',
    ];

    private User $technician;

    private User $manager;

    private User $warehouseStaff;

    private Location $warehouse;

    private Location $pop;

    private Product $product;

    private WorkOrder $workOrder;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $company = $this->apiCompany();
        $this->technician = $this->technicianLogin($company);
        $this->token = $this->issueToken($this->technician, 'technician');
        $this->manager = $this->grantRole(User::factory()->create(['company_id' => $company->id]), 'manager');
        $this->warehouseStaff = $this->grantRole(User::factory()->create(['company_id' => $company->id]), 'staff');
        $dispatcher = User::factory()->create(['company_id' => $company->id]);

        CompanyService::useCompanyForTests((int) $company->id);
        $this->warehouse = Location::factory()->create(['company_id' => $company->id, 'code' => 'WH', 'type' => 'site']);
        $this->pop = Location::factory()->create(['company_id' => $company->id, 'code' => 'POP', 'type' => 'site']);
        $this->product = Product::factory()->create(['company_id' => $company->id, 'type' => 'consumable']);
        $this->workOrder = WorkOrderFactory::new()->create([
            'company_id' => $company->id,
            'type' => 'maintenance',
            'status' => 'in_progress',
            'assigned_to' => $this->technician->id,
            'created_by' => $dispatcher->id,
            'location_id' => $this->pop->id,
        ]);

        $this->actingAs($this->warehouseStaff);
        StockService::receive($this->product->id, $this->warehouse->id, 100);
    }

    public function test_material_requested_in_the_app_for_an_spk_is_deducted_once(): void
    {
        $case = $this->handOver($this->requestInApp(10));
        $this->assertLedger(self::HANDED_OVER, '90.00');

        // 8 were used and 2 go back to the shelf.
        $this->declareAndReceive($case, used: 8, goodReturn: 2);
        $this->assertSame('8.00', WorkOrderItem::query()->where('work_order_id', $this->workOrder->id)->sole()->quantity_used);

        $this->submitAndApprove();

        $this->assertSame('completed', $this->workOrder->fresh()->status);
        $ledger = [...self::HANDED_OVER, 'return 2.00 at WH by MaterialReturnLine'];
        $this->assertLedger($ledger, '92.00');

        $this->approveAgain($ledger, '92.00');
    }

    public function test_use_beyond_the_handed_over_quantity_leaves_the_request_warehouse_once(): void
    {
        $case = $this->handOver($this->requestInApp(10));

        // The technician used the 10 and 2 more of the same product: the app keeps that as one line of 12.
        $this->api('POST', "technician/work-orders/{$this->workOrder->public_id}/items", ['product_id' => $this->product->public_id, 'quantity' => 12], $this->token)
            ->assertCreated();
        $this->declareAndReceive($case, used: 10, goodReturn: 0);

        $this->submitAndApprove();

        $this->assertSame('completed', $this->workOrder->fresh()->status);
        $ledger = [...self::HANDED_OVER, 'issue -2.00 at WH by WorkOrderItem'];
        $this->assertLedger($ledger, '88.00');

        $this->approveAgain($ledger, '88.00');
    }

    /** The app asks for $quantity of the product from the warehouse, naming the SPK. */
    private function requestInApp(int $quantity): InventoryRequest
    {
        $response = $this->api('POST', 'technician/inventory-requests', [
            'warehouse_location_id' => $this->warehouse->public_id,
            'work_order_id' => $this->workOrder->public_id,
            'items' => [['product_id' => $this->product->public_id, 'quantity_requested' => $quantity]],
        ], $this->token)
            ->assertCreated()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.work_order.id', $this->workOrder->public_id);

        $request = InventoryRequest::withoutCompany()->where('public_id', $response->json('data.id'))->sole();
        $this->assertSame(['work_order', $this->workOrder->id], [$request->source_type, $request->source_id]);

        return $request;
    }

    /**
     * The manager approves, the warehouse prepares and hands over (four eyes: the approver, the requester and the
     * hand-over differ).
     *
     * @return array<string, mixed> the technician's custody case, as the app lists it for the SPK
     */
    private function handOver(InventoryRequest $request): array
    {
        $this->actingAs($this->manager)->postJson(route('admin.inventory.requests.approve', $request))->assertOk();

        foreach (['prepare', 'issue'] as $step) {
            $this->actingAs($this->warehouseStaff)->postJson(route("admin.inventory.requests.{$step}", $request))
                ->assertOk()
                ->assertJsonPath('data.status', ['prepare' => 'ready_for_pickup', 'issue' => 'issued'][$step]);
        }

        return $this->api('GET', 'technician/material-returns?work_order_id='.$this->workOrder->public_id, token: $this->token)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'awaiting_declaration')
            ->assertJsonPath('data.0.inventory_request.id', $request->public_id)
            ->json('data.0');
    }

    /** The technician declares the use, and the warehouse takes back what came home. */
    private function declareAndReceive(array $case, int $used, int $goodReturn): void
    {
        $line = ['id' => $case['lines'][0]['id'], 'actual_used_qty' => $used, 'declared_good_return_qty' => $goodReturn, 'declared_discrepancy_qty' => 0];

        $this->api('POST', "technician/material-returns/{$case['id']}/declare", ['lines' => [$line]], $this->token)
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_receipt');

        $this->actingAs($this->warehouseStaff)->postJson(route('admin.inventory.returns.receive-accept', $case['id']), [
            'lines' => [['id' => $line['id'], 'accepted_good_return_qty' => $goodReturn]],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'reconciled');
    }

    private function submitAndApprove(): void
    {
        $this->workOrder->addMediaFromString('evidence')
            ->usingFileName('proof.jpg')
            ->withCustomProperties(['company_id' => $this->workOrder->company_id, 'type' => 'photo', 'uploaded_by' => $this->technician->id])
            ->toMediaCollection('evidence');

        $this->api('POST', "technician/work-orders/{$this->workOrder->public_id}/submit", token: $this->token)
            ->assertOk()
            ->assertJsonPath('data.status', 'waiting_review');

        $this->actingAs($this->manager)->post(route('admin.spk.approve', $this->workOrder))->assertRedirect();
    }

    /** A second approval is refused and moves no stock. */
    private function approveAgain(array $ledger, string $quantity): void
    {
        $this->actingAs($this->manager)->postJson(route('admin.spk.approve', $this->workOrder))
            ->assertStatus(422)
            ->assertJsonPath('message', 'SPK harus berstatus menunggu review untuk bisa disetujui.');

        $this->assertLedger($ledger, $quantity);
    }

    /**
     * The product's movements, oldest first, are exactly $expected; they add up to the $quantity the warehouse holds,
     * every reservation was released, and the POP the SPK sits at was never touched.
     *
     * @param  list<string>  $expected
     */
    private function assertLedger(array $expected, string $quantity): void
    {
        $places = [$this->warehouse->id => 'WH', $this->pop->id => 'POP'];
        $movements = DB::table('stock_movements')->where('product_id', $this->product->id)->orderBy('id')->get();

        $this->assertSame($expected, $movements->map(fn (object $movement): string => sprintf(
            '%s %s at %s by %s',
            $movement->movement_type,
            $movement->quantity,
            $places[$movement->from_location_id ?? $movement->to_location_id],
            $movement->reference_type === null ? '-' : class_basename($movement->reference_type),
        ))->all());

        $stock = Stock::withoutCompany()->where('product_id', $this->product->id)->where('location_id', $this->warehouse->id)->sole();
        $this->assertSame([$quantity, '0.00'], [$stock->quantity, $stock->reserved_quantity]);
        $this->assertEqualsWithDelta((float) $quantity, (float) $movements->whereIn('movement_type', ['receive', 'return', 'issue'])->sum('quantity'), 0.001, 'receipts and returns less issues are what the warehouse holds');
        $this->assertEqualsWithDelta(0.0, (float) $movements->whereIn('movement_type', ['reserve', 'release'])->sum('quantity'), 0.001, 'every reservation was released');
        $this->assertSame($quantity, $movements->last()->balance_after);
        $this->assertNull(Stock::withoutCompany()->where('location_id', $this->pop->id)->first(), 'the POP of the SPK was never looked up or created');
    }
}
