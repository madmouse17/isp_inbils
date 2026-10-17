<?php

namespace Tests\Feature\Api\V1\Technician;

use App\Models\Core\Location;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\InventoryRequestItem;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Notifications\InventoryRequestReadyNotification;
use Modules\Inventory\Services\InventoryRequestService;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

class InventoryRequestsTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    public function test_technician_lists_only_own_inventory_requests_newest_first(): void
    {
        $technician = $this->technicianLogin();
        $other = $this->technicianLogin($technician->company);
        $older = $this->inventoryRequestFor($technician, 'approved_reserved');
        $newer = $this->inventoryRequestFor($technician, 'ready_for_pickup');
        $this->inventoryRequestFor($other, 'ready_for_pickup');

        $this->api('GET', 'technician/inventory-requests', token: $this->issueToken($technician, 'technician'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->public_id)
            ->assertJsonPath('data.0.status', 'ready_for_pickup')
            ->assertJsonPath('data.0.items.0.product_name', 'Drop Cable')
            ->assertJsonPath('data.0.work_order.id', WorkOrder::withoutCompany()->whereKey($newer->source_id)->value('public_id'))
            ->assertJsonPath('data.1.id', $older->public_id)
            ->assertJsonMissingPath('data.0.requested_by')
            ->assertJsonMissingPath('data.0.approved_by');
    }

    public function test_inventory_request_status_filter(): void
    {
        $technician = $this->technicianLogin();
        $this->inventoryRequestFor($technician, 'approved_reserved');
        $ready = $this->inventoryRequestFor($technician, 'ready_for_pickup');

        $this->api('GET', 'technician/inventory-requests?status=ready_for_pickup', token: $this->issueToken($technician, 'technician'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ready->public_id);
    }

    public function test_ready_for_pickup_sends_mail_and_database_notification(): void
    {
        Notification::fake();
        $technician = $this->technicianLogin();
        $warehouse = User::factory()->create(['company_id' => $technician->company_id]);
        $warehouse->givePermissionTo('inventory.request.prepare');
        $request = $this->inventoryRequestFor($technician, 'preparing');

        InventoryRequestService::markReady($request, $warehouse);

        Notification::assertSentTo($technician, InventoryRequestReadyNotification::class);
    }

    public function test_ready_for_pickup_creates_one_database_notification(): void
    {
        $technician = $this->technicianLogin();
        $warehouse = User::factory()->create(['company_id' => $technician->company_id]);
        $warehouse->givePermissionTo('inventory.request.prepare');
        $request = $this->inventoryRequestFor($technician, 'preparing');

        InventoryRequestService::markReady($request, $warehouse);

        $row = DB::table('notifications')->where('notifiable_id', $technician->id)->sole();
        $data = json_decode($row->data, true);
        $this->assertSame('inventory_request_ready', $data['type']);
        $this->assertSame($request->public_id, $data['request_id']);
    }

    private function inventoryRequestFor(User $technician, string $status): InventoryRequest
    {
        $company = $technician->company;
        CompanyService::useCompanyForTests((int) $company->id);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $unit = Unit::factory()->create(['company_id' => $company->id, 'symbol' => 'm'.Str::random(4)]);
        $product = Product::factory()->create([
            'company_id' => $company->id,
            'category_id' => Category::factory()->create(['company_id' => $company->id, 'unit_id' => $unit->id])->id,
            'unit_id' => $unit->id,
            'name' => 'Drop Cable',
        ]);
        $workOrder = WorkOrderFactory::new()->create(['company_id' => $company->id, 'assigned_to' => $technician->id]);
        $request = InventoryRequest::query()->create([
            'company_id' => $company->id,
            'code' => 'REQ-'.Str::upper(Str::random(6)),
            'requested_by' => $technician->id,
            'warehouse_location_id' => $location->id,
            'source_type' => 'work_order',
            'source_id' => $workOrder->id,
            'status' => $status,
        ]);
        InventoryRequestItem::query()->create([
            'company_id' => $company->id,
            'inventory_request_id' => $request->id,
            'product_id' => $product->id,
            'source_location_id' => $location->id,
            'quantity_requested' => 12,
            'quantity_approved' => 12,
            'quantity_issued' => $status === 'issued' ? 12 : 0,
        ]);

        return $request;
    }
}
