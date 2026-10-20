<?php

namespace Tests\Feature\Inventory;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrderItem;
use Modules\SPK\Services\SpkService;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MaterialReturnWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = [
            'inventory.request.view.own', 'inventory.request.create', 'inventory.request.cancel.own',
            'inventory.request.view.all', 'inventory.request.approve', 'inventory.request.reject',
            'inventory.request.prepare', 'inventory.request.issue',
            'inventory.return.view.own', 'inventory.return.view.all',
            'inventory.return.declare', 'inventory.return.receive', 'inventory.return.resolve',
            'spk.view', 'spk.start', 'spk.submit', 'spk.approve', 'spk.reject', 'spk.cancel',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
    }

    private function createCompanyAndLocation(): array
    {
        $company = Company::factory()->create();
        $location = Location::factory()->create(['company_id' => $company->id]);

        return [$company, $location];
    }

    private function createProduct(Company $company, array $attrs = []): Product
    {
        $category = Category::factory()->create(['company_id' => $company->id]);
        $unit = Unit::factory()->create(['company_id' => $company->id]);

        return Product::factory()->create(array_merge([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'type' => 'consumable',
            'is_active' => true,
        ], $attrs));
    }

    private function setupIssuedRequestWithCase(Company $company, Location $location, Product $product, User $technician, User $warehouseStaff, float $qty = 10): array
    {
        CompanyService::useCompanyForTests($company->id);
        $this->actingAs($warehouseStaff);

        Stock::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $qty,
            'reserved_quantity' => 0,
        ]);

        $ir = InventoryRequest::create([
            'company_id' => $company->id,
            'code' => 'REQ-TEST-001',
            'requested_by' => $technician->id,
            'warehouse_location_id' => $location->id,
            'reason' => 'Pengujian material return',
            'status' => 'draft',
        ]);

        $item = InventoryRequestItem::create([
            'company_id' => $company->id,
            'inventory_request_id' => $ir->id,
            'product_id' => $product->id,
            'quantity_requested' => $qty,
            'source_location_id' => $location->id,
        ]);

        // Separation of duties: the approver (controller) is never the preparer/issuer (operator).
        $approver = User::factory()->create(['company_id' => $company->id]);
        $approver->givePermissionTo(['inventory.request.view.all', 'inventory.request.approve']);

        InventoryRequestService::submit($ir, $technician);
        InventoryRequestService::approve($ir, $approver, [
            'items' => [
                ['id' => $item->id, 'quantity_approved' => $qty, 'source_location_id' => $location->id],
            ],
        ]);
        InventoryRequestService::prepare($ir, $warehouseStaff);
        InventoryRequestService::markReady($ir, $warehouseStaff);
        InventoryRequestService::issue($ir, $warehouseStaff);

        $case = MaterialReturnCase::where('inventory_request_id', $ir->id)->firstOrFail();

        return [$ir->fresh(), $case->fresh()];
    }

    public function test_issue_creates_material_return_case_awaiting_declaration(): void
    {
        [$company, $location] = $this->createCompanyAndLocation();
        $product = $this->createProduct($company);
        $technician = User::factory()->create(['company_id' => $company->id]);
        $technician->givePermissionTo(['inventory.request.view.own', 'inventory.request.create']);
        $warehouseStaff = User::factory()->create(['company_id' => $company->id]);
        $warehouseStaff->givePermissionTo([
            'inventory.request.view.all', 'inventory.request.approve', 'inventory.request.prepare', 'inventory.request.issue',
        ]);

        [$ir, $case] = $this->setupIssuedRequestWithCase($company, $location, $product, $technician, $warehouseStaff, 10);

        $this->assertEquals('awaiting_declaration', $case->status);
        $this->assertCount(1, $case->lines);
        $this->assertEquals(10, (float) $case->lines->first()->issued_qty);
        $this->assertEquals(0, (float) $case->lines->first()->actual_used_qty);
    }

    public function test_technician_declaration_enforces_invariant_and_zero_stock_delta(): void
    {
        [$company, $location] = $this->createCompanyAndLocation();
        $product = $this->createProduct($company);
        $technician = User::factory()->create(['company_id' => $company->id]);
        $technician->givePermissionTo(['inventory.request.view.own', 'inventory.request.create', 'inventory.return.declare']);
        $warehouseStaff = User::factory()->create(['company_id' => $company->id]);
        $warehouseStaff->givePermissionTo(['inventory.request.view.all', 'inventory.request.approve', 'inventory.request.prepare', 'inventory.request.issue']);

        [$ir, $case] = $this->setupIssuedRequestWithCase($company, $location, $product, $technician, $warehouseStaff, 10);
        $line = $case->lines->first();

        // Check stock right after issue: 0 available
        $stock = Stock::where('product_id', $product->id)->where('location_id', $location->id)->first();
        $this->assertEquals(0, (float) $stock->quantity);

        // Invalid equation sum fails
        $this->expectException(HttpException::class);
        MaterialReturnService::declareUsage($case, $technician, [
            'lines' => [
                [
                    'id' => $line->id,
                    'actual_used_qty' => 5,
                    'declared_good_return_qty' => 4, // 5 + 4 = 9 != 10
                    'declared_discrepancy_qty' => 0,
                ],
            ],
        ]);
    }

    public function test_declaration_succeeds_with_invariant_and_zero_delta_on_stock(): void
    {
        [$company, $location] = $this->createCompanyAndLocation();
        $product = $this->createProduct($company);
        $technician = User::factory()->create(['company_id' => $company->id]);
        $technician->givePermissionTo(['inventory.request.view.own', 'inventory.request.create', 'inventory.return.declare']);
        $warehouseStaff = User::factory()->create(['company_id' => $company->id]);
        $warehouseStaff->givePermissionTo(['inventory.request.view.all', 'inventory.request.approve', 'inventory.request.prepare', 'inventory.request.issue']);

        [$ir, $case] = $this->setupIssuedRequestWithCase($company, $location, $product, $technician, $warehouseStaff, 10);
        $line = $case->lines->first();

        // Correct declaration: 6 used + 3 good return + 1 damaged discrepancy = 10 issued
        $updated = MaterialReturnService::declareUsage($case, $technician, [
            'idempotency_key' => 'DECL-KEY-1',
            'lines' => [
                [
                    'id' => $line->id,
                    'actual_used_qty' => 6,
                    'declared_good_return_qty' => 3,
                    'declared_discrepancy_qty' => 1,
                    'discrepancy_code' => 'damaged',
                    'discrepancy_reason' => 'Kabel sobek di lapangan',
                ],
            ],
        ]);

        $this->assertEquals('awaiting_receipt', $updated->status);
        // Stock MUST remain unchanged (delta 0 upon declaration)
        $stock = Stock::where('product_id', $product->id)->where('location_id', $location->id)->first();
        $this->assertEquals(0, (float) $stock->quantity);

        // Idempotency: same key + same payload re-returns same case
        $recalled = MaterialReturnService::declareUsage($case, $technician, [
            'idempotency_key' => 'DECL-KEY-1',
            'lines' => [
                [
                    'id' => $line->id,
                    'actual_used_qty' => 6,
                    'declared_good_return_qty' => 3,
                    'declared_discrepancy_qty' => 1,
                    'discrepancy_code' => 'damaged',
                    'discrepancy_reason' => 'Kabel sobek di lapangan',
                ],
            ],
        ]);
        $this->assertEquals($updated->id, $recalled->id);

        // Conflict: same key + different payload aborts 409 without mutation
        try {
            MaterialReturnService::declareUsage($case, $technician, [
                'idempotency_key' => 'DECL-KEY-1',
                'lines' => [
                    [
                        'id' => $line->id,
                        'actual_used_qty' => 5,
                        'declared_good_return_qty' => 5,
                        'declared_discrepancy_qty' => 0,
                    ],
                ],
            ]);
            $this->fail('Same idempotency key with conflicting payload must throw 409 Conflict.');
        } catch (HttpException $e) {
            $this->assertEquals(409, $e->getStatusCode());
        }
    }

    public function test_warehouse_physical_reject_is_atomic_and_produces_zero_stock_delta(): void
    {
        [$company, $location] = $this->createCompanyAndLocation();
        $product = $this->createProduct($company);
        $technician = User::factory()->create(['company_id' => $company->id]);
        $technician->givePermissionTo(['inventory.request.view.own', 'inventory.request.create', 'inventory.return.declare']);
        $warehouseStaff = User::factory()->create(['company_id' => $company->id]);
        $warehouseStaff->givePermissionTo([
            'inventory.request.view.all', 'inventory.request.approve', 'inventory.request.prepare', 'inventory.request.issue',
            'inventory.return.receive',
        ]);

        [$ir, $case] = $this->setupIssuedRequestWithCase($company, $location, $product, $technician, $warehouseStaff, 10);
        $line = $case->lines->first();

        MaterialReturnService::declareUsage($case, $technician, [
            'lines' => [
                [
                    'id' => $line->id,
                    'actual_used_qty' => 10,
                    'declared_good_return_qty' => 0,
                    'declared_discrepancy_qty' => 0,
                ],
            ],
        ]);

        // Warehouse rejects handover
        $rejected = MaterialReturnService::receiveReject($case, $warehouseStaff, 'Barang fisik belum dibawa ke meja gudang');
        $this->assertEquals('correction_required', $rejected->status);
        $this->assertEquals('Barang fisik belum dibawa ke meja gudang', $rejected->rejection_reason);

        // Stock remains delta 0
        $stock = Stock::where('product_id', $product->id)->where('location_id', $location->id)->first();
        $this->assertEquals(0, (float) $stock->quantity);
    }

    public function test_warehouse_physical_reject_over_http_reads_the_posted_reason(): void
    {
        [$company, $location] = $this->createCompanyAndLocation();
        $product = $this->createProduct($company);
        $technician = User::factory()->create(['company_id' => $company->id]);
        $technician->givePermissionTo(['inventory.request.view.own', 'inventory.request.create', 'inventory.return.declare']);
        $warehouseStaff = User::factory()->create(['company_id' => $company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $warehouseStaff->givePermissionTo([
            'inventory.request.view.all', 'inventory.request.approve', 'inventory.request.prepare', 'inventory.request.issue',
            'inventory.return.receive',
        ]);

        [$ir, $case] = $this->setupIssuedRequestWithCase($company, $location, $product, $technician, $warehouseStaff, 10);
        $line = $case->lines->first();
        MaterialReturnService::declareUsage($case, $technician, [
            'lines' => [['id' => $line->id, 'actual_used_qty' => 10, 'declared_good_return_qty' => 0, 'declared_discrepancy_qty' => 0]],
        ]);

        // The dialog posts `reason`; the controller used to read `rejection_reason` and crash on null.
        $this->actingAs($warehouseStaff)
            ->postJson(route('admin.inventory.returns.receive-reject', $case), ['reason' => 'Barang belum dibawa ke gudang'])
            ->assertOk()
            ->assertJsonPath('data.status', 'correction_required');

        $this->assertSame('Barang belum dibawa ke gudang', $case->fresh()->rejection_reason);
    }

    public function test_warehouse_physical_accept_increments_good_stock_only_and_discrepancies_delta_zero(): void
    {
        [$company, $location] = $this->createCompanyAndLocation();
        $product = $this->createProduct($company);
        $technician = User::factory()->create(['company_id' => $company->id]);
        $technician->givePermissionTo(['inventory.request.view.own', 'inventory.request.create', 'inventory.return.declare']);
        $warehouseStaff = User::factory()->create(['company_id' => $company->id]);
        $warehouseStaff->givePermissionTo([
            'inventory.request.view.all', 'inventory.request.approve', 'inventory.request.prepare', 'inventory.request.issue',
            'inventory.return.receive',
        ]);
        $manager = User::factory()->create(['company_id' => $company->id]);
        $manager->givePermissionTo(['inventory.return.resolve']);

        [$ir, $case] = $this->setupIssuedRequestWithCase($company, $location, $product, $technician, $warehouseStaff, 10);
        $line = $case->lines->first();

        MaterialReturnService::declareUsage($case, $technician, [
            'lines' => [
                [
                    'id' => $line->id,
                    'actual_used_qty' => 5,
                    'declared_good_return_qty' => 3,
                    'declared_discrepancy_qty' => 2,
                    'discrepancy_code' => 'damaged',
                    'discrepancy_reason' => 'Rusak di lapangan',
                ],
            ],
        ]);

        // Warehouse accepts physically: 5 used + 3 good return + 1 damaged + 1 lost = 10
        $received = MaterialReturnService::receiveAccept($case, $warehouseStaff, [
            'lines' => [
                [
                    'id' => $line->id,
                    'accepted_good_return_qty' => 3,
                    'damaged_return_qty' => 1,
                    'lost_qty' => 1,
                    'other_discrepancy_qty' => 0,
                    'discrepancy_code' => 'damaged',
                    'discrepancy_reason' => '1 rusak fisik, 1 hilang',
                ],
            ],
        ]);

        // Has discrepancies -> status becomes exception_open
        $this->assertEquals('exception_open', $received->status);

        // Good stock ONLY increased by accepted_good_return_qty (3.0)
        $stock = Stock::where('product_id', $product->id)->where('location_id', $location->id)->first();
        $this->assertEquals(3.0, (float) $stock->quantity);

        // Receiver cannot resolve exception (maker-checker)
        try {
            MaterialReturnService::resolveException($received, $warehouseStaff, 'Diselesaikan');
            $this->fail('Receiver should not be able to resolve exception.');
        } catch (HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }

        // Authoritative resolver resolves exception
        $resolved = MaterialReturnService::resolveException($received, $manager, 'Selisih 1 hilang disetujui untuk pembebanan');
        $this->assertEquals('resolved', $resolved->status);
        $this->assertEquals('Selisih 1 hilang disetujui untuk pembebanan', $resolved->resolution_note);

        // Stock still 3 (resolution does not inflate stock)
        $stock->refresh();
        $this->assertEquals(3.0, (float) $stock->quantity);
    }

    public function test_spk_submission_and_approval_gates_with_material_returns(): void
    {
        [$company, $location] = $this->createCompanyAndLocation();
        $product = $this->createProduct($company);
        $customer = Customer::factory()->create(['company_id' => $company->id]);
        $subscription = ServiceSubscription::factory()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'status' => 'pending',
        ]);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $technician = User::factory()->create(['company_id' => $company->id]);
        $technician->givePermissionTo(['spk.start', 'spk.submit', 'inventory.return.declare']);
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $technician->id,
            'status' => 'active',
        ]);
        $warehouseStaff = User::factory()->create(['company_id' => $company->id]);
        $warehouseStaff->givePermissionTo(['inventory.return.receive']);
        $approver = User::factory()->create(['company_id' => $company->id]);
        $approver->givePermissionTo(['spk.approve']);

        // Create SPK
        $spk = WorkOrderFactory::new()->create([
            'company_id' => $company->id,
            'type' => 'installation',
            'status' => 'assigned',
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'location_id' => $location->id,
            'assigned_to' => $technician->id,
            'created_by' => $creator->id,
        ]);

        WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $spk->id,
            'product_id' => $product->id,
            'quantity_reserved' => 0,
            'quantity_used' => 5,
        ]);

        // Start SPK
        $this->actingAs($technician);
        SpkService::start($spk);
        $spk->refresh();

        // Add media evidence
        $spk->addMediaFromString('test evidence')
            ->usingFileName('test.jpg')
            ->toMediaCollection('evidence', 'public');

        // Create IR tied to this SPK and issue it
        $ir = InventoryRequest::create([
            'company_id' => $company->id,
            'code' => 'REQ-SPK-001',
            'requested_by' => $technician->id,
            'warehouse_location_id' => $location->id,
            'reason' => 'Material untuk instalasi SPK',
            'source_type' => 'work_order',
            'source_id' => $spk->id,
            'status' => 'ready_for_pickup',
        ]);
        $irItem = InventoryRequestItem::create([
            'company_id' => $company->id,
            'inventory_request_id' => $ir->id,
            'product_id' => $product->id,
            'quantity_requested' => 5,
            'quantity_approved' => 5,
            'source_location_id' => $location->id,
        ]);

        Stock::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 5,
            'reserved_quantity' => 5,
        ]);

        InventoryRequestService::issue($ir, $warehouseStaff);

        $case = MaterialReturnCase::where('inventory_request_id', $ir->id)->firstOrFail();
        $this->assertEquals('awaiting_declaration', $case->status);

        // Technicians cannot submit SPK while declaration is pending
        try {
            SpkService::submitForReview($spk);
            $this->fail('SPK submission must be blocked when material declaration is pending.');
        } catch (HttpException $e) {
            $this->assertEquals(422, $e->getStatusCode());
        }

        // Technician declares usage
        $line = $case->lines->first();
        MaterialReturnService::declareUsage($case, $technician, [
            'lines' => [
                [
                    'id' => $line->id,
                    'actual_used_qty' => 5,
                    'declared_good_return_qty' => 0,
                    'declared_discrepancy_qty' => 0,
                ],
            ],
        ]);
        $case->refresh();
        $this->assertEquals('awaiting_receipt', $case->status);

        // Now SPK submission succeeds!
        SpkService::submitForReview($spk);
        $spk->refresh();
        $this->assertEquals('waiting_review', $spk->status);

        // Approver attempts to approve while return is not reconciled/resolved -> fails closed
        $this->actingAs($approver);
        try {
            SpkService::approve($spk);
            $this->fail('SPK approve must fail-closed while return case is awaiting receipt.');
        } catch (HttpException $e) {
            $this->assertEquals(422, $e->getStatusCode());
        }

        // Warehouse receives & accepts (reconciles)
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
        $case->refresh();
        $this->assertEquals('reconciled', $case->status);

        // SPK approve now succeeds!
        SpkService::approve($spk);
        $spk->refresh();
        $this->assertEquals('completed', $spk->status);

        // Stock consumption was skipped in CompleteSpkAction because IR already issued stock (no double issue!)
        // Stock remains 0 (not decremented into negative)
        $stock = Stock::where('product_id', $product->id)->where('location_id', $location->id)->first();
        $this->assertEquals(0, (float) $stock->quantity);
    }

    public function test_spk_mixed_lines_handles_ir_backed_and_legacy_lines_correctly(): void
    {
        [$company, $location] = $this->createCompanyAndLocation();
        $irProduct = $this->createProduct($company);
        $legacyProduct = $this->createProduct($company, [
            'name' => 'Legacy Cable Dropcore',
            'sku' => 'SKU-LEGACY-'.uniqid(),
            'type' => 'consumable',
            'is_active' => true,
        ]);

        $customer = Customer::factory()->create(['company_id' => $company->id]);
        $subscription = ServiceSubscription::factory()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'status' => 'pending',
        ]);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $technician = User::factory()->create(['company_id' => $company->id]);
        $technician->givePermissionTo(['spk.start', 'spk.submit', 'inventory.return.declare']);
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $technician->id,
            'status' => 'active',
        ]);
        $warehouseStaff = User::factory()->create(['company_id' => $company->id]);
        $warehouseStaff->givePermissionTo(['inventory.return.receive']);
        $approver = User::factory()->create(['company_id' => $company->id]);
        $approver->givePermissionTo(['spk.approve']);

        $spk = WorkOrderFactory::new()->create([
            'company_id' => $company->id,
            'type' => 'installation',
            'status' => 'assigned',
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'location_id' => $location->id,
            'assigned_to' => $technician->id,
            'created_by' => $creator->id,
        ]);

        // Line 1: IR backed product
        WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $spk->id,
            'product_id' => $irProduct->id,
            'quantity_reserved' => 0,
            'quantity_used' => 5,
        ]);

        // Line 2: product no request covers, taken from the SPK location
        WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $spk->id,
            'product_id' => $legacyProduct->id,
            'quantity_reserved' => 0,
            'quantity_used' => 4,
        ]);

        // Stock initial for that product: 10 total, nothing reserved
        Stock::create([
            'company_id' => $company->id,
            'product_id' => $legacyProduct->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        // Start SPK
        $this->actingAs($technician);
        SpkService::start($spk);
        $spk->refresh();

        $spk->addMediaFromString('test evidence')
            ->usingFileName('test.jpg')
            ->toMediaCollection('evidence', 'public');

        // IR backed product issued via Inventory Request
        $ir = InventoryRequest::create([
            'company_id' => $company->id,
            'code' => 'REQ-SPK-MIXED',
            'requested_by' => $technician->id,
            'warehouse_location_id' => $location->id,
            'reason' => 'Material IR untuk mixed SPK',
            'source_type' => 'work_order',
            'source_id' => $spk->id,
            'status' => 'ready_for_pickup',
        ]);
        InventoryRequestItem::create([
            'company_id' => $company->id,
            'inventory_request_id' => $ir->id,
            'product_id' => $irProduct->id,
            'quantity_requested' => 5,
            'quantity_approved' => 5,
            'source_location_id' => $location->id,
        ]);
        Stock::create([
            'company_id' => $company->id,
            'product_id' => $irProduct->id,
            'location_id' => $location->id,
            'quantity' => 5,
            'reserved_quantity' => 5,
        ]);
        InventoryRequestService::issue($ir, $warehouseStaff);

        $case = MaterialReturnCase::where('inventory_request_id', $ir->id)->firstOrFail();
        $line = $case->lines->first();
        MaterialReturnService::declareUsage($case, $technician, [
            'lines' => [
                [
                    'id' => $line->id,
                    'actual_used_qty' => 5,
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
        $case->refresh();
        $this->assertEquals('reconciled', $case->status);

        SpkService::submitForReview($spk);
        $spk->refresh();

        $this->actingAs($approver);
        SpkService::approve($spk);
        $spk->refresh();
        $this->assertEquals('completed', $spk->status);

        // Verify stock post-completion:
        // 1. IR backed product: remains 0 (skipped double issue)
        $irStock = Stock::where('product_id', $irProduct->id)->where('location_id', $location->id)->first();
        $this->assertEquals(0, (float) $irStock->quantity);
        $this->assertEquals(0, (float) $irStock->reserved_quantity);

        // 2. Uncovered product: 4 issued at the SPK location (10 - 4 = 6), nothing reserved or released
        $legacyStock = Stock::where('product_id', $legacyProduct->id)->where('location_id', $location->id)->first();
        $this->assertEquals(6, (float) $legacyStock->quantity);
        $this->assertEquals(0, (float) $legacyStock->reserved_quantity);
    }

    public function test_direct_service_layer_capability_enforcement(): void
    {
        [$company, $location] = $this->createCompanyAndLocation();
        $product = $this->createProduct($company);
        $unprivilegedUser = User::factory()->create(['company_id' => $company->id]);
        $technician = User::factory()->create(['company_id' => $company->id]);
        $technician->givePermissionTo(['inventory.return.declare']);
        $warehouseStaff = User::factory()->create(['company_id' => $company->id]);
        $warehouseStaff->givePermissionTo(['inventory.return.receive']);

        [$ir, $case] = $this->setupIssuedRequestWithCase($company, $location, $product, $technician, $warehouseStaff, 10);
        $line = $case->lines->first();

        // 1. declareUsage without capability throws 403
        try {
            MaterialReturnService::declareUsage($case, $unprivilegedUser, [
                'lines' => [
                    [
                        'id' => $line->id,
                        'actual_used_qty' => 10,
                        'declared_good_return_qty' => 0,
                        'declared_discrepancy_qty' => 0,
                    ],
                ],
            ]);
            $this->fail('Direct declareUsage call without capability must abort 403.');
        } catch (HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }

        // Legitimate declare
        MaterialReturnService::declareUsage($case, $technician, [
            'lines' => [
                [
                    'id' => $line->id,
                    'actual_used_qty' => 5,
                    'declared_good_return_qty' => 5,
                    'declared_discrepancy_qty' => 0,
                ],
            ],
        ]);
        $case->refresh();

        // 2. receiveAccept without capability throws 403
        try {
            MaterialReturnService::receiveAccept($case, $unprivilegedUser, [
                'lines' => [
                    [
                        'id' => $line->id,
                        'accepted_good_return_qty' => 5,
                        'damaged_return_qty' => 0,
                        'lost_qty' => 0,
                        'other_discrepancy_qty' => 0,
                    ],
                ],
            ]);
            $this->fail('Direct receiveAccept call without capability must abort 403.');
        } catch (HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }

        // 3. receiveReject without capability throws 403
        try {
            MaterialReturnService::receiveReject($case, $unprivilegedUser, 'Alasan tolak');
            $this->fail('Direct receiveReject call without capability must abort 403.');
        } catch (HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }

        // 4. resolveException without capability throws 403
        try {
            MaterialReturnService::resolveException($case, $unprivilegedUser, 'Catatan resolusi');
            $this->fail('Direct resolveException call without capability must abort 403.');
        } catch (HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }
    }

    public function test_spk_partial_ir_coverage_and_duplicate_product_lines_without_under_consumption_or_orphan_reservation(): void
    {
        [$company, $location] = $this->createCompanyAndLocation();
        $product = $this->createProduct($company);

        $customer = Customer::factory()->create(['company_id' => $company->id]);
        $subscription = ServiceSubscription::factory()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'status' => 'pending',
        ]);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $technician = User::factory()->create(['company_id' => $company->id]);
        $technician->givePermissionTo(['spk.start', 'spk.submit', 'inventory.return.declare']);
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $technician->id,
            'status' => 'active',
        ]);
        $warehouseStaff = User::factory()->create(['company_id' => $company->id]);
        $warehouseStaff->givePermissionTo(['inventory.return.receive']);
        $approver = User::factory()->create(['company_id' => $company->id]);
        $approver->givePermissionTo(['spk.approve']);

        $spk = WorkOrderFactory::new()->create([
            'company_id' => $company->id,
            'type' => 'installation',
            'status' => 'assigned',
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'location_id' => $location->id,
            'assigned_to' => $technician->id,
            'created_by' => $creator->id,
        ]);

        // 10 used
        $item1 = WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $spk->id,
            'product_id' => $product->id,
            'quantity_reserved' => 0,
            'quantity_used' => 10,
        ]);

        // Initial warehouse stock: 20 total, 4 reserved for the request below
        $stock = Stock::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 20,
            'reserved_quantity' => 4,
        ]);

        $this->actingAs($technician);
        SpkService::start($spk);
        $spk->refresh();

        $spk->addMediaFromString('test evidence')
            ->usingFileName('test.jpg')
            ->toMediaCollection('evidence', 'public');

        // IR only covers 4 units (partial coverage!)
        $ir = InventoryRequest::create([
            'company_id' => $company->id,
            'code' => 'REQ-PARTIAL-4',
            'requested_by' => $technician->id,
            'warehouse_location_id' => $location->id,
            'reason' => 'Partial IR coverage',
            'source_type' => 'work_order',
            'source_id' => $spk->id,
            'status' => 'ready_for_pickup',
        ]);
        InventoryRequestItem::create([
            'company_id' => $company->id,
            'inventory_request_id' => $ir->id,
            'product_id' => $product->id,
            'quantity_requested' => 4,
            'quantity_approved' => 4,
            'source_location_id' => $location->id,
        ]);

        // Issue 4 units via IR -> stock decrements from 20 to 16
        InventoryRequestService::issue($ir, $warehouseStaff);
        $stock->refresh();
        $this->assertEquals(16.00, (float) $stock->quantity);

        // Reconcile return for the 4 issued units: all 4 were used in the field
        $case = MaterialReturnCase::where('inventory_request_id', $ir->id)->firstOrFail();
        $line = $case->lines->first();
        MaterialReturnService::declareUsage($case, $technician, [
            'lines' => [
                [
                    'id' => $line->id,
                    'actual_used_qty' => 4,
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

        // Now submit and approve SPK
        SpkService::submitForReview($spk);
        $spk->refresh();

        $this->actingAs($approver);
        SpkService::approve($spk);
        $spk->refresh();
        $this->assertEquals('completed', $spk->status);

        // Verify:
        // Total needed was 10 units. IR covered 4.
        // Direct consumption issued the 6 units the request did not cover, from the request's warehouse.
        // Stock should be 16 - 6 = 10.00!
        // The request's reservation went with its issue: nothing is left reserved (reserved_quantity = 0.00)!
        $stock->refresh();
        $this->assertEquals(10.00, (float) $stock->quantity);
        $this->assertEquals(0.00, (float) $stock->reserved_quantity);

        $item1->refresh();
        $this->assertEquals(0.00, (float) $item1->quantity_reserved);
    }

    public function test_partial_return_reconciles_actual_used_qty_to_work_order_and_invoice_lines(): void
    {
        [$company, $location] = $this->createCompanyAndLocation();
        $company->update(['settings' => ['bill_installation_materials' => true]]);
        $product = $this->createProduct($company);
        $product->update(['sell_price' => 100000]);

        $customer = Customer::factory()->create(['company_id' => $company->id]);
        $subscription = ServiceSubscription::factory()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'status' => 'pending',
        ]);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $technician = User::factory()->create(['company_id' => $company->id]);
        $technician->givePermissionTo(['spk.start', 'spk.submit', 'inventory.return.declare']);
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $technician->id,
            'status' => 'active',
        ]);
        $warehouseStaff = User::factory()->create(['company_id' => $company->id]);
        $warehouseStaff->givePermissionTo(['inventory.return.receive']);
        $approver = User::factory()->create(['company_id' => $company->id]);
        $approver->givePermissionTo(['spk.approve']);

        $spk = WorkOrderFactory::new()->create([
            'company_id' => $company->id,
            'type' => 'installation',
            'status' => 'assigned',
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'location_id' => $location->id,
            'assigned_to' => $technician->id,
            'created_by' => $creator->id,
        ]);

        // WorkOrderItem initially had quantity_used = 10
        $item = WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $spk->id,
            'product_id' => $product->id,
            'quantity_reserved' => 0,
            'quantity_used' => 10,
        ]);

        Stock::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'reserved_quantity' => 10,
        ]);

        $this->actingAs($technician);
        SpkService::start($spk);
        $spk->refresh();

        $spk->addMediaFromString('test evidence')
            ->usingFileName('test.jpg')
            ->toMediaCollection('evidence', 'public');

        // IR issued for 10 units
        $ir = InventoryRequest::create([
            'company_id' => $company->id,
            'code' => 'REQ-PARTIAL-RETURN',
            'requested_by' => $technician->id,
            'warehouse_location_id' => $location->id,
            'reason' => 'Material for job',
            'source_type' => 'work_order',
            'source_id' => $spk->id,
            'status' => 'ready_for_pickup',
        ]);
        InventoryRequestItem::create([
            'company_id' => $company->id,
            'inventory_request_id' => $ir->id,
            'product_id' => $product->id,
            'quantity_requested' => 10,
            'quantity_approved' => 10,
            'source_location_id' => $location->id,
        ]);
        InventoryRequestService::issue($ir, $warehouseStaff);

        // Technician only used 6 units, and returns 4 units!
        $case = MaterialReturnCase::where('inventory_request_id', $ir->id)->firstOrFail();
        $line = $case->lines->first();
        MaterialReturnService::declareUsage($case, $technician, [
            'lines' => [
                [
                    'id' => $line->id,
                    'actual_used_qty' => 6,
                    'declared_good_return_qty' => 4,
                    'declared_discrepancy_qty' => 0,
                ],
            ],
        ]);
        // Warehouse accepts the 4 returned units back into stock
        MaterialReturnService::receiveAccept($case, $warehouseStaff, [
            'lines' => [
                [
                    'id' => $line->id,
                    'accepted_good_return_qty' => 4,
                    'damaged_return_qty' => 0,
                    'lost_qty' => 0,
                    'other_discrepancy_qty' => 0,
                ],
            ],
        ]);

        // Submit & approve SPK
        SpkService::submitForReview($spk);
        $spk->refresh();

        $this->actingAs($approver);
        SpkService::approve($spk);
        $spk->refresh();
        $this->assertEquals('completed', $spk->status);

        // Verify WorkOrderItem was reconciled from 10 down to 6!
        $item->refresh();
        $this->assertEquals(6.00, (float) $item->quantity_used);

        // Verify Auto-generated Invoice charges for 6 units (600,000), NOT 10 (1,000,000)!
        $invoice = Invoice::where('work_order_id', $spk->id)->first();
        $this->assertNotNull($invoice);
        $invoiceItem = $invoice->items()->where('product_id', $product->id)->first();
        $this->assertNotNull($invoiceItem);
        $this->assertEquals(6.00, (float) $invoiceItem->quantity);
        $this->assertEquals(600000.00, (float) $invoiceItem->line_total);
    }
}
