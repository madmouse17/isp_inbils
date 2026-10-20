<?php

namespace Tests\Feature\SPK;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Invoice;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\InventoryRequestItem;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Services\InventoryRequestService;
use Modules\Inventory\Services\MaterialReturnService;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrderItem;
use Modules\SPK\Services\SpkService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SpkCompletionOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'spk.approve', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'spk.cancel', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'inventory.request.issue', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'inventory.return.declare', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'inventory.return.receive', 'guard_name' => 'web']);
    }

    public function test_install_spk_completion_consumes_stock_installs_asset_activates_subscription_and_invoices(): void
    {
        [$company, $user, $location, $customer] = $this->scope();
        $product = $this->product($company, ['sell_price' => 100_000]);
        $subscription = ServiceSubscription::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'pending',
            'otc_installation_fee' => 50_000,
        ]);
        $asset = NetworkAssetFactory::new()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'status' => 'available',
        ]);
        Stock::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);
        $workOrder = WorkOrderFactory::new()->create([
            'company_id' => $company->id,
            'type' => 'installation',
            'status' => 'waiting_review',
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'location_id' => $location->id,
            'created_by' => $user->id,
        ]);
        WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $workOrder->id,
            'product_id' => $product->id,
            'network_asset_id' => $asset->id,
            'quantity_used' => 2,
        ]);
        $workOrder->addMediaFromString('test')
            ->usingFileName('test.jpg')
            ->withCustomProperties([
                'company_id' => $company->id,
                'type' => 'photo',
                'uploaded_by' => $user->id,
            ])
            ->toMediaCollection('evidence', 'public');

        // Self-approval guard (P0-B B2): approver must differ from creator/assignee.
        $approver = User::factory()->create(['company_id' => $company->id]);
        $approver->givePermissionTo('spk.approve');
        $this->actingAs($approver);

        SpkService::approve($workOrder);

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'completed']);
        $this->assertDatabaseHas('stocks', [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 8,
            'reserved_quantity' => 0,
        ]);
        $this->assertDatabaseHas('network_assets', [
            'id' => $asset->id,
            'status' => 'installed',
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
        ]);
        $this->assertDatabaseHas('service_subscriptions', [
            'id' => $subscription->id,
            'status' => 'active',
            'ont_asset_id' => $asset->id,
        ]);
        $this->assertDatabaseHas('invoices', [
            'work_order_id' => $workOrder->id,
            'subscription_id' => $subscription->id,
            'source' => 'spk',
        ]);
        // K7 and K17: an installation always leaves a draft; the 2 pieces of the product used are part of the fee.
        $invoice = Invoice::where('work_order_id', $workOrder->id)->firstOrFail();
        $this->assertSame('draft', $invoice->status);
        $this->assertSame(['Biaya pemasangan'], $invoice->items()->pluck('description')->all());
        $this->assertSame('50000.00', $invoice->subtotal);
    }

    public function test_approval_locks_the_customer_before_it_touches_the_subscription(): void
    {
        [$company, $user, $location, $customer] = $this->scope();
        $product = $this->product($company);
        $subscription = ServiceSubscription::factory()->create(['customer_id' => $customer->id, 'status' => 'pending']);
        $asset = NetworkAssetFactory::new()->create(['company_id' => $company->id, 'product_id' => $product->id, 'status' => 'available']);
        Stock::create(['company_id' => $company->id, 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 10, 'reserved_quantity' => 0]);
        $workOrder = WorkOrderFactory::new()->create([
            'company_id' => $company->id,
            'type' => 'installation',
            'status' => 'waiting_review',
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'location_id' => $location->id,
            'created_by' => $user->id,
        ]);
        WorkOrderItem::create(['company_id' => $company->id, 'work_order_id' => $workOrder->id, 'product_id' => $product->id, 'network_asset_id' => $asset->id, 'quantity_used' => 1]);
        $workOrder->addMediaFromString('test')
            ->usingFileName('test.jpg')
            ->withCustomProperties(['company_id' => $company->id, 'type' => 'photo', 'uploaded_by' => $user->id])
            ->toMediaCollection('evidence', 'public');
        $approver = User::factory()->create(['company_id' => $company->id]);
        $approver->givePermissionTo('spk.approve');
        $this->actingAs($approver);

        DB::enableQueryLog();
        SpkService::approve($workOrder);
        $statements = collect(DB::getQueryLog())->pluck('query');

        // The lane locks the customer before its subscription (SubscriptionService does, and a transition of the same
        // subscription runs concurrently with this approval). The install writes the subscription, and the activation
        // follows it, so the customer must be locked before the first lock or write of the subscription.
        $customerLock = $statements->search(fn (string $sql): bool => str_contains($sql, 'from `customers`') && str_ends_with($sql, 'for update'));
        $subscriptionLock = $statements->search(fn (string $sql): bool => str_starts_with($sql, 'update `service_subscriptions`')
            || (str_contains($sql, 'from `service_subscriptions`') && str_ends_with($sql, 'for update')));

        $this->assertNotFalse($customerLock, 'the approval never locked the customer');
        $this->assertNotFalse($subscriptionLock, 'the approval never wrote the subscription');
        $this->assertLessThan($subscriptionLock, $customerLock);
        $this->assertSame('active', $subscription->fresh()->status);
    }

    public function test_completion_with_partial_ir_coverage_issues_only_uncovered_quantity(): void
    {
        [$company, $user, $location, $customer] = $this->scope();
        $product = $this->product($company, ['type' => 'consumable']);

        // Warehouse stock starts at 10
        Stock::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        $workOrder = WorkOrderFactory::new()->create([
            'company_id' => $company->id,
            'type' => 'maintenance',
            'status' => 'waiting_review',
            'customer_id' => $customer->id,
            'location_id' => $location->id,
            'created_by' => $user->id,
        ]);

        WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $workOrder->id,
            'product_id' => $product->id,
            'quantity_reserved' => 0,
            'quantity_used' => 5,
        ]);

        $workOrder->addMediaFromString('evidence')
            ->usingFileName('test.jpg')
            ->withCustomProperties([
                'company_id' => $company->id,
                'type' => 'photo',
                'uploaded_by' => $user->id,
            ])
            ->toMediaCollection('evidence', 'public');

        // IR issued 2 units (so stock deducted by 2, leaving 8 in warehouse)
        $ir = InventoryRequest::create([
            'company_id' => $company->id,
            'code' => 'REQ-PARTIAL-COV',
            'requested_by' => $user->id,
            'warehouse_location_id' => $location->id,
            'reason' => 'Partial IR test',
            'source_type' => 'work_order',
            'source_id' => $workOrder->id,
            'status' => 'ready_for_pickup',
        ]);
        InventoryRequestItem::create([
            'company_id' => $company->id,
            'inventory_request_id' => $ir->id,
            'product_id' => $product->id,
            'quantity_requested' => 2,
            'quantity_approved' => 2,
            'source_location_id' => $location->id,
        ]);
        // Reserve 2 for the IR issue
        Stock::where('product_id', $product->id)->where('location_id', $location->id)->update(['reserved_quantity' => 2]);
        $warehouseStaff = User::factory()->create(['company_id' => $company->id]);
        $warehouseStaff->givePermissionTo(['inventory.request.issue', 'inventory.return.receive']);
        InventoryRequestService::issue($ir, $warehouseStaff);

        // Reconcile return for the 2 issued units (technician declares used, warehouse accepts)
        $user->givePermissionTo('inventory.return.declare');
        $case = MaterialReturnCase::where('inventory_request_id', $ir->id)->firstOrFail();
        $line = $case->lines->first();
        MaterialReturnService::declareUsage($case, $user, [
            'lines' => [
                [
                    'id' => $line->id,
                    'actual_used_qty' => 2,
                    'declared_good_return_qty' => 0,
                    'declared_discrepancy_qty' => 0,
                ],
            ],
        ]);
        MaterialReturnService::receiveAccept($case, $warehouseStaff, [
            'lines' => [
                [
                    'id' => $line->id,
                    'accepted_good_return_qty' => 0,
                    'damaged_return_qty' => 0,
                    'lost_qty' => 0,
                    'other_discrepancy_qty' => 0,
                ],
            ],
        ]);

        // Stock after IR issue: quantity = 8, reserved_quantity = 0
        $this->assertDatabaseHas('stocks', [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 8,
            'reserved_quantity' => 0,
        ]);

        // Complete SPK: should consume only the uncovered 3 units! (8 - 3 = 5)
        $approver = User::factory()->create(['company_id' => $company->id]);
        $approver->givePermissionTo('spk.approve');
        $this->actingAs($approver);

        SpkService::approve($workOrder);

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'completed']);
        $this->assertDatabaseHas('stocks', [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 5,
            'reserved_quantity' => 0,
        ]);
    }

    private function scope(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $location = Location::create([
            'company_id' => $company->id,
            'code' => 'LOC-SPK-'.fake()->unique()->numberBetween(1, 9999),
            'name' => 'SPK Test Location',
            'type' => 'site',
            'path' => 'SPK Test Location',
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);

        return [$company, $user, $location, $customer];
    }

    private function product(Company $company, array $extra = []): Product
    {
        $unit = Unit::create([
            'company_id' => $company->id,
            'name' => 'Piece',
            'symbol' => 'pcs',
        ]);
        $category = Category::create([
            'company_id' => $company->id,
            'unit_id' => $unit->id,
            'name' => 'SPK Test Category',
            'code' => 'SPK-CAT-'.fake()->unique()->numberBetween(1, 9999),
            'is_active' => true,
        ]);

        return Product::create(array_merge([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku' => 'SPK-PRD-'.fake()->unique()->numberBetween(1, 9999),
            'name' => 'SPK Test Product',
            'type' => 'asset',
            'track_stock' => true,
            'sell_price' => 100_000,
            'cost_price' => 50_000,
            'min_stock' => 0,
            'is_active' => true,
        ], $extra));
    }
}
