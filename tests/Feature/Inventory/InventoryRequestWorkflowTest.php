<?php

namespace Tests\Feature\Inventory;

use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\InventoryRequestItem;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Notifications\InventoryRequestApprovedNotification;
use Modules\Inventory\Notifications\InventoryRequestReadyNotification;
use Modules\Inventory\Notifications\InventoryRequestRejectedNotification;
use Modules\Inventory\Notifications\InventoryRequestSubmittedNotification;
use Modules\Inventory\Services\InventoryRequestService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\WithoutAppOnlyRedirect;

class InventoryRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private Company $companyA;

    private Company $companyB;

    private Location $warehouseA;

    private Location $warehouseB;

    private Product $cableProduct;

    private User $technician;

    private User $staffWarehouse;

    private User $managerApprover;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->companyA = Company::factory()->create();
        $this->companyB = Company::factory()->create();

        $this->warehouseA = Location::factory()->create([
            'company_id' => $this->companyA->id,
            'name' => 'Gudang Utama A',
            'type' => 'site',
            'is_active' => true,
        ]);

        $this->warehouseB = Location::factory()->create([
            'company_id' => $this->companyB->id,
            'name' => 'Gudang Utama B',
            'type' => 'site',
            'is_active' => true,
        ]);

        $this->cableProduct = Product::factory()->create([
            'company_id' => $this->companyA->id,
            'name' => 'Kabel Dropcore 1 Core',
            'type' => 'consumable',
            'is_active' => true,
        ]);

        // Berikan stok awal 100 di Gudang A
        Stock::create([
            'company_id' => $this->companyA->id,
            'product_id' => $this->cableProduct->id,
            'location_id' => $this->warehouseA->id,
            'quantity' => 100,
            'reserved_quantity' => 0,
        ]);

        $this->technician = User::factory()->create([
            'company_id' => $this->companyA->id,
            'email' => 'tech@comp-a.local',
        ]);
        $this->technician->assignRole('technician');

        $this->staffWarehouse = User::factory()->create([
            'company_id' => $this->companyA->id,
            'email' => 'warehouse@comp-a.local',
        ]);
        $this->staffWarehouse->assignRole('staff');

        $this->managerApprover = User::factory()->create([
            'company_id' => $this->companyA->id,
            'email' => 'manager@comp-a.local',
        ]);
        $this->managerApprover->assignRole('manager');

        $this->adminUser = User::factory()->create([
            'company_id' => $this->companyA->id,
            'email' => 'admin@comp-a.local',
        ]);
        $this->adminUser->assignRole('admin');
    }

    public function test_create_page_lists_products_with_their_sku_as_code(): void
    {
        $response = $this->actingAs($this->technician)->get(route('admin.inventory.requests.create'))->assertOk();

        $product = collect($response->viewData('page')['props']['products'])->firstWhere('id', $this->cableProduct->id);
        $this->assertSame($this->cableProduct->sku, $product['code']);
    }

    public function test_technician_can_create_draft_and_submit_inventory_request(): void
    {
        Notification::fake();

        // 1. Buat Draft
        $response = $this->actingAs($this->technician)
            ->postJson(route('admin.inventory.requests.store'), [
                'warehouse_location_id' => $this->warehouseA->id,
                'reason' => 'Kebutuhan penarikan kabel pelanggan Baru Blok C',
                'submit' => false,
                'items' => [
                    [
                        'product_id' => $this->cableProduct->id,
                        'quantity_requested' => 15,
                        'note' => '15 meter',
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $requestId = $response->json('data.id');

        $this->assertDatabaseHas('inventory_requests', [
            'id' => $requestId,
            'company_id' => $this->companyA->id,
            'requested_by' => $this->technician->id,
            'status' => 'draft',
        ]);

        $req = InventoryRequest::find($requestId);
        $this->assertSame('REQ-'.now('Asia/Jakarta')->format('Ymd').'-0001', $req->code);

        // 2. Submit Draft
        $submitResponse = $this->actingAs($this->technician)
            ->postJson(route('admin.inventory.requests.submit', $req->id));

        $submitResponse->assertStatus(200);

        $this->assertDatabaseHas('inventory_requests', [
            'id' => $requestId,
            'status' => 'submitted',
        ]);

        Notification::assertSentTo([$this->managerApprover, $this->adminUser], InventoryRequestSubmittedNotification::class);
        Notification::assertNotSentTo($this->staffWarehouse, InventoryRequestSubmittedNotification::class);
    }

    public function test_request_codes_come_from_the_engine_and_restart_each_day(): void
    {
        $payload = [
            'warehouse_location_id' => $this->warehouseA->id,
            'reason' => 'Kebutuhan kode harian',
            'submit' => false,
            'items' => [['product_id' => $this->cableProduct->id, 'quantity_requested' => 1]],
        ];
        $codes = [];

        foreach (['2026-10-13 09:00:00', '2026-10-13 10:00:00', '2026-10-14 09:00:00'] as $moment) {
            $this->travelTo($moment);
            $id = $this->actingAs($this->technician)->postJson(route('admin.inventory.requests.store'), $payload)->assertStatus(201)->json('data.id');
            $codes[] = InventoryRequest::findOrFail($id)->code;
        }

        $this->assertSame(['REQ-20261013-0001', 'REQ-20261013-0002', 'REQ-20261014-0001'], $codes);
    }

    public function test_maker_checker_rule_requester_cannot_approve_or_reject_own_request(): void
    {
        // Admin membuat request
        $req = InventoryRequest::create([
            'company_id' => $this->companyA->id,
            'code' => 'REQ-TEST-001',
            'requested_by' => $this->adminUser->id,
            'warehouse_location_id' => $this->warehouseA->id,
            'status' => 'submitted',
            'reason' => 'Perbaikan link backbone',
            'submitted_at' => now(),
        ]);

        $req->items()->create([
            'company_id' => $this->companyA->id,
            'product_id' => $this->cableProduct->id,
            'quantity_requested' => 10,
        ]);

        // Admin mencoba approve request miliknya sendiri -> harus 403 Forbidden
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.inventory.requests.approve', $req->id), []);

        $response->assertStatus(403);

        // Admin mencoba reject request miliknya sendiri -> harus 403 Forbidden
        $rejectResponse = $this->actingAs($this->adminUser)
            ->postJson(route('admin.inventory.requests.reject', $req->id), [
                'rejection_reason' => 'Batal perlu',
            ]);

        $rejectResponse->assertStatus(403);
    }

    public function test_end_to_end_approval_reservation_pickup_and_issue_flow(): void
    {
        Notification::fake();

        // 1. Teknisi membuat dan submit request 20 meter kabel
        $createRes = $this->actingAs($this->technician)
            ->postJson(route('admin.inventory.requests.store'), [
                'warehouse_location_id' => $this->warehouseA->id,
                'reason' => 'Instalasi baru',
                'submit' => true,
                'items' => [
                    [
                        'product_id' => $this->cableProduct->id,
                        'quantity_requested' => 20,
                    ],
                ],
            ]);

        $createRes->assertStatus(201);
        $requestId = $createRes->json('data.id');
        $req = InventoryRequest::find($requestId);

        // 2. Manager (controller) Menyetujui (Approve) -> Stok harus tereservasi
        $approveRes = $this->actingAs($this->managerApprover)
            ->postJson(route('admin.inventory.requests.approve', $req->id), [
                'approval_note' => 'Disetujui penuh',
            ]);

        $approveRes->assertStatus(200);

        // Cek reservasi stok
        $stock = Stock::where('product_id', $this->cableProduct->id)
            ->where('location_id', $this->warehouseA->id)
            ->first();

        $this->assertEquals(100, (float) $stock->quantity);
        $this->assertEquals(20, (float) $stock->reserved_quantity);

        Notification::assertSentTo($this->technician, InventoryRequestApprovedNotification::class);

        // 3. Staff Gudang Menyiapkan Barang (Preparing)
        $prepareRes = $this->actingAs($this->staffWarehouse)
            ->postJson(route('admin.inventory.requests.prepare', $req->id));

        $prepareRes->assertStatus(200);
        $this->assertEquals('preparing', $req->fresh()->status);

        // 4. Staff Gudang Menandai Siap Diambil (Ready for Pickup)
        $readyRes = $this->actingAs($this->staffWarehouse)
            ->postJson(route('admin.inventory.requests.ready', $req->id));

        $readyRes->assertStatus(200);
        $this->assertEquals('ready_for_pickup', $req->fresh()->status);

        Notification::assertSentTo($this->technician, InventoryRequestReadyNotification::class);

        // 5. Staff Gudang Menyerahkan Barang (Issue)
        // Pemohon (teknisi) tidak boleh issue barangnya sendiri
        $illegalIssueRes = $this->actingAs($this->technician)
            ->postJson(route('admin.inventory.requests.issue', $req->id));
        $illegalIssueRes->assertStatus(403);

        $issueRes = $this->actingAs($this->staffWarehouse)
            ->postJson(route('admin.inventory.requests.issue', $req->id));

        $issueRes->assertStatus(200);

        // Stok harus berkurang dan reservasi dilepaskan (quantity = 80, reserved = 0)
        $stock->refresh();
        $this->assertEquals(80, (float) $stock->quantity);
        $this->assertEquals(0, (float) $stock->reserved_quantity);

        $req->refresh();
        $this->assertEquals('issued', $req->status);
        $this->assertEquals(20, (float) $req->items->first()->quantity_issued);
    }

    public function test_reject_flow_and_notification(): void
    {
        Notification::fake();

        $createRes = $this->actingAs($this->technician)
            ->postJson(route('admin.inventory.requests.store'), [
                'warehouse_location_id' => $this->warehouseA->id,
                'reason' => 'Minta switch tambahan',
                'submit' => true,
                'items' => [
                    [
                        'product_id' => $this->cableProduct->id,
                        'quantity_requested' => 10,
                    ],
                ],
            ]);

        $requestId = $createRes->json('data.id');
        $req = InventoryRequest::find($requestId);

        $rejectRes = $this->actingAs($this->managerApprover)
            ->postJson(route('admin.inventory.requests.reject', $req->id), [
                'rejection_reason' => 'Alokasi switch minggu ini habis.',
            ]);

        $rejectRes->assertStatus(200);

        $req->refresh();
        $this->assertEquals('rejected', $req->status);
        $this->assertEquals('Alokasi switch minggu ini habis.', $req->rejection_reason);

        // Pastikan tidak ada stok yang tereservasi
        $stock = Stock::where('product_id', $this->cableProduct->id)->first();
        $this->assertEquals(0, (float) $stock->reserved_quantity);

        Notification::assertSentTo($this->technician, InventoryRequestRejectedNotification::class);
    }

    public function test_tenant_boundary_isolation_prevents_cross_company_access_or_actions(): void
    {
        $technicianB = User::factory()->create([
            'company_id' => $this->companyB->id,
            'email' => 'tech-b@comp-b.local',
        ]);
        $technicianB->assignRole('technician');

        $reqA = InventoryRequest::create([
            'company_id' => $this->companyA->id,
            'code' => 'REQ-A-001',
            'requested_by' => $this->technician->id,
            'warehouse_location_id' => $this->warehouseA->id,
            'status' => 'submitted',
            'reason' => 'Perusahaan A',
            'submitted_at' => now(),
        ]);

        // Teknisi B tidak boleh melihat request milik Perusahaan A (404/403)
        $viewRes = $this->actingAs($technicianB)
            ->getJson(route('admin.inventory.requests.show', $reqA->id));

        $this->assertTrue(in_array($viewRes->status(), [403, 404], true));

        // Teknisi B tidak boleh meng-cancel request Perusahaan A
        $cancelRes = $this->actingAs($technicianB)
            ->postJson(route('admin.inventory.requests.cancel', $reqA->id));

        $this->assertTrue(in_array($cancelRes->status(), [403, 404], true));
    }

    public function test_multi_item_create_submit_approve_prepare_ready_and_issue_flow(): void
    {
        Notification::fake();

        // Buat produk kedua
        $connectorProduct = Product::factory()->create([
            'company_id' => $this->companyA->id,
            'name' => 'Fast Connector SC/UPC',
            'type' => 'consumable',
            'is_active' => true,
        ]);

        Stock::create([
            'company_id' => $this->companyA->id,
            'product_id' => $connectorProduct->id,
            'location_id' => $this->warehouseA->id,
            'quantity' => 50,
            'reserved_quantity' => 0,
        ]);

        // 1. Teknisi membuat multi-item draft
        $response = $this->actingAs($this->technician)
            ->postJson(route('admin.inventory.requests.store'), [
                'warehouse_location_id' => $this->warehouseA->id,
                'reason' => 'Pemasangan massal ODP Baru',
                'submit' => false,
                'items' => [
                    [
                        'product_id' => $this->cableProduct->id,
                        'quantity_requested' => 25,
                        'note' => '25 meter dropcore',
                    ],
                    [
                        'product_id' => $connectorProduct->id,
                        'quantity_requested' => 10,
                        'note' => '10 pcs connector',
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $requestId = $response->json('data.id');
        $this->assertNotNull($requestId);

        $req = InventoryRequest::with('items')->findOrFail($requestId);
        $this->assertEquals('draft', $req->status);
        $this->assertCount(2, $req->items);

        // 2. Submit Draft
        $submitRes = $this->actingAs($this->technician)
            ->postJson(route('admin.inventory.requests.submit', $req->id));
        $submitRes->assertStatus(200);
        $this->assertEquals('submitted', $req->fresh()->status);

        Notification::assertSentTo([$this->managerApprover, $this->adminUser], InventoryRequestSubmittedNotification::class);
        Notification::assertNotSentTo($this->staffWarehouse, InventoryRequestSubmittedNotification::class);

        // 3. Manager Approve -> Keduanya tereservasi atomik
        $approveRes = $this->actingAs($this->managerApprover)
            ->postJson(route('admin.inventory.requests.approve', $req->id), [
                'approval_note' => 'Disetujui lengkap',
            ]);
        $approveRes->assertStatus(200);

        $cableStock = Stock::where('product_id', $this->cableProduct->id)->where('location_id', $this->warehouseA->id)->firstOrFail();
        $connectorStock = Stock::where('product_id', $connectorProduct->id)->where('location_id', $this->warehouseA->id)->firstOrFail();

        $this->assertEquals(100, (float) $cableStock->quantity);
        $this->assertEquals(25, (float) $cableStock->reserved_quantity);
        $this->assertEquals(50, (float) $connectorStock->quantity);
        $this->assertEquals(10, (float) $connectorStock->reserved_quantity);

        Notification::assertSentTo($this->technician, InventoryRequestApprovedNotification::class);

        // 4. Prepare
        $prepareRes = $this->actingAs($this->staffWarehouse)
            ->postJson(route('admin.inventory.requests.prepare', $req->id));
        $prepareRes->assertStatus(200);
        $this->assertEquals('preparing', $req->fresh()->status);

        // 5. Ready for pickup
        $readyRes = $this->actingAs($this->staffWarehouse)
            ->postJson(route('admin.inventory.requests.ready', $req->id));
        $readyRes->assertStatus(200);
        $this->assertEquals('ready_for_pickup', $req->fresh()->status);

        Notification::assertSentTo($this->technician, InventoryRequestReadyNotification::class);

        // 6. Issue / Serah Terima Handover
        $issueRes = $this->actingAs($this->staffWarehouse)
            ->postJson(route('admin.inventory.requests.issue', $req->id));
        $issueRes->assertStatus(200);

        $cableStock->refresh();
        $connectorStock->refresh();
        $this->assertEquals(75, (float) $cableStock->quantity);
        $this->assertEquals(0, (float) $cableStock->reserved_quantity);
        $this->assertEquals(40, (float) $connectorStock->quantity);
        $this->assertEquals(0, (float) $connectorStock->reserved_quantity);

        $req->refresh();
        $this->assertEquals('issued', $req->status);
        $this->assertEquals(25, (float) $req->items()->where('product_id', $this->cableProduct->id)->value('quantity_issued'));
        $this->assertEquals(10, (float) $req->items()->where('product_id', $connectorProduct->id)->value('quantity_issued'));

        // Pastikan Stock Movement tercatat dengan referensi InventoryRequestItem
        $cableItem = $req->items()->where('product_id', $this->cableProduct->id)->firstOrFail();
        $connectorItem = $req->items()->where('product_id', $connectorProduct->id)->firstOrFail();

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->cableProduct->id,
            'movement_type' => 'issue',
            'reference_type' => InventoryRequestItem::class,
            'reference_id' => $cableItem->id,
            'quantity' => -25,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $connectorProduct->id,
            'movement_type' => 'issue',
            'reference_type' => InventoryRequestItem::class,
            'reference_id' => $connectorItem->id,
            'quantity' => -10,
        ]);

        // Pastikan MaterialReturnCase terbentuk dengan 2 lines
        $case = MaterialReturnCase::where('inventory_request_id', $req->id)->first();
        $this->assertNotNull($case);
        $this->assertEquals('awaiting_declaration', $case->status);
        $this->assertCount(2, $case->lines);
    }

    public function test_cancel_flow_by_technician_and_warehouse(): void
    {
        // 1. Teknisi membatalkan draft miliknya sendiri
        $draftReq = InventoryRequest::create([
            'company_id' => $this->companyA->id,
            'code' => 'REQ-DRAFT-CANCEL',
            'requested_by' => $this->technician->id,
            'warehouse_location_id' => $this->warehouseA->id,
            'status' => 'draft',
            'reason' => 'Draft salah buat',
        ]);

        $cancelDraftRes = $this->actingAs($this->technician)
            ->postJson(route('admin.inventory.requests.cancel', $draftReq->id), [
                'cancellation_reason' => 'Salah buat permohonan',
            ]);
        $cancelDraftRes->assertStatus(200);
        $this->assertEquals('cancelled', $draftReq->fresh()->status);
        $this->assertEquals('Salah buat permohonan', $draftReq->fresh()->cancellation_reason);

        // 2. Teknisi membatalkan request submitted miliknya
        $subReq = InventoryRequest::create([
            'company_id' => $this->companyA->id,
            'code' => 'REQ-SUB-CANCEL',
            'requested_by' => $this->technician->id,
            'warehouse_location_id' => $this->warehouseA->id,
            'status' => 'submitted',
            'reason' => 'Perlu dibatalkan karena revisi SPK',
            'submitted_at' => now(),
        ]);

        $cancelSubRes = $this->actingAs($this->technician)
            ->postJson(route('admin.inventory.requests.cancel', $subReq->id), [
                'cancellation_reason' => 'SPK ditunda pelanggan',
            ]);
        $cancelSubRes->assertStatus(200);
        $this->assertEquals('cancelled', $subReq->fresh()->status);

        // 3. Staff operator tidak berwenang membatalkan; manager (controller) membatalkan request submitted
        $subReq2 = InventoryRequest::create([
            'company_id' => $this->companyA->id,
            'code' => 'REQ-SUB-CANCEL-2',
            'requested_by' => $this->technician->id,
            'warehouse_location_id' => $this->warehouseA->id,
            'status' => 'submitted',
            'reason' => 'Permintaan duplikat',
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->staffWarehouse)
            ->postJson(route('admin.inventory.requests.cancel', $subReq2->id), [
                'cancellation_reason' => 'Duplikat dengan tiket lain',
            ])
            ->assertStatus(403);
        $this->assertEquals('submitted', $subReq2->fresh()->status);

        $cancelManagerRes = $this->actingAs($this->managerApprover)
            ->postJson(route('admin.inventory.requests.cancel', $subReq2->id), [
                'cancellation_reason' => 'Duplikat dengan tiket lain',
            ]);
        $cancelManagerRes->assertStatus(200);
        $this->assertEquals('cancelled', $subReq2->fresh()->status);

        // 4. Request yang sudah approved_reserved tidak dapat di-cancel lewat cancel endpoint biasa
        $approvedReq = InventoryRequest::create([
            'company_id' => $this->companyA->id,
            'code' => 'REQ-APP-CANCEL',
            'requested_by' => $this->technician->id,
            'warehouse_location_id' => $this->warehouseA->id,
            'status' => 'approved_reserved',
            'reason' => 'Sudah disetujui',
        ]);

        $cancelApprovedRes = $this->actingAs($this->managerApprover)
            ->postJson(route('admin.inventory.requests.cancel', $approvedReq->id));
        $cancelApprovedRes->assertStatus(403);
    }

    public function test_insufficient_stock_rolls_back_entire_approval_atomically(): void
    {
        $connectorProduct = Product::factory()->create([
            'company_id' => $this->companyA->id,
            'name' => 'Konektor Tes Rollback',
            'type' => 'consumable',
            'is_active' => true,
        ]);

        // Cable punya stok 100, tapi Connector hanya punya stok 3
        Stock::create([
            'company_id' => $this->companyA->id,
            'product_id' => $connectorProduct->id,
            'location_id' => $this->warehouseA->id,
            'quantity' => 3,
            'reserved_quantity' => 0,
        ]);

        $req = InventoryRequest::create([
            'company_id' => $this->companyA->id,
            'code' => 'REQ-INSUF-001',
            'requested_by' => $this->technician->id,
            'warehouse_location_id' => $this->warehouseA->id,
            'status' => 'submitted',
            'reason' => 'Tes atomicity rollback',
            'submitted_at' => now(),
        ]);

        $req->items()->create([
            'company_id' => $this->companyA->id,
            'product_id' => $this->cableProduct->id,
            'source_location_id' => $this->warehouseA->id,
            'quantity_requested' => 10,
        ]);

        $req->items()->create([
            'company_id' => $this->companyA->id,
            'product_id' => $connectorProduct->id,
            'source_location_id' => $this->warehouseA->id,
            'quantity_requested' => 10, // Melebihi stok 3
        ]);

        // Coba approve -> harus gagal 422
        $approveRes = $this->actingAs($this->managerApprover)
            ->postJson(route('admin.inventory.requests.approve', $req->id));

        $approveRes->assertStatus(422);

        // Status request harus tetap submitted
        $this->assertEquals('submitted', $req->fresh()->status);

        // Stok kabel TIDAK BOLEH tereservasi (rollback atomik berhasil)
        $cableStock = Stock::where('product_id', $this->cableProduct->id)->where('location_id', $this->warehouseA->id)->first();
        $this->assertEquals(0, (float) $cableStock->reserved_quantity);

        // Stok konektor tetap 0 reservasi
        $connectorStock = Stock::where('product_id', $connectorProduct->id)->where('location_id', $this->warehouseA->id)->first();
        $this->assertEquals(0, (float) $connectorStock->reserved_quantity);
    }

    public function test_duplicate_idempotency_and_invalid_state_transitions(): void
    {
        $req = InventoryRequest::create([
            'company_id' => $this->companyA->id,
            'code' => 'REQ-STATE-001',
            'requested_by' => $this->technician->id,
            'warehouse_location_id' => $this->warehouseA->id,
            'status' => 'submitted',
            'reason' => 'Tes transisi status ilegal',
            'submitted_at' => now(),
        ]);

        $req->items()->create([
            'company_id' => $this->companyA->id,
            'product_id' => $this->cableProduct->id,
            'source_location_id' => $this->warehouseA->id,
            'quantity_requested' => 5,
        ]);

        // Coba langsung prepare sebelum approve -> 403 / 422
        $res = $this->actingAs($this->staffWarehouse)->postJson(route('admin.inventory.requests.prepare', $req->id));
        $this->assertTrue(in_array($res->status(), [403, 422], true));

        // Coba langsung issue sebelum approve & prepare -> 403 / 422
        $res = $this->actingAs($this->staffWarehouse)->postJson(route('admin.inventory.requests.issue', $req->id));
        $this->assertTrue(in_array($res->status(), [403, 422], true));

        // Approve pertama kali -> sukses
        $approveRes = $this->actingAs($this->managerApprover)->postJson(route('admin.inventory.requests.approve', $req->id));
        $approveRes->assertStatus(200);

        // Duplicate approve kedua kali -> ditolak 403 / 422
        $dupApproveRes = $this->actingAs($this->managerApprover)->postJson(route('admin.inventory.requests.approve', $req->id));
        $this->assertTrue(in_array($dupApproveRes->status(), [403, 422], true));

        // Coba issue saat masih approved_reserved (belum ready) -> ditolak
        $res = $this->actingAs($this->staffWarehouse)->postJson(route('admin.inventory.requests.issue', $req->id));
        $this->assertTrue(in_array($res->status(), [403, 422], true));

        // Transisi normal: prepare -> ready -> issue
        $this->actingAs($this->staffWarehouse)->postJson(route('admin.inventory.requests.prepare', $req->id))->assertStatus(200);
        $this->actingAs($this->staffWarehouse)->postJson(route('admin.inventory.requests.ready', $req->id))->assertStatus(200);
        $this->actingAs($this->staffWarehouse)->postJson(route('admin.inventory.requests.issue', $req->id))->assertStatus(200);

        // Duplicate issue kedua kali -> ditolak
        $dupIssueRes = $this->actingAs($this->staffWarehouse)->postJson(route('admin.inventory.requests.issue', $req->id));
        $this->assertTrue(in_array($dupIssueRes->status(), [403, 422], true));
    }

    public function test_foreign_product_and_serialized_asset_rejection(): void
    {
        // 1. Produk milik Company B ditolak saat dibuat oleh user Company A
        $foreignProduct = Product::factory()->create([
            'company_id' => $this->companyB->id,
            'name' => 'Produk Perusahaan Asing',
            'type' => 'consumable',
            'is_active' => true,
        ]);

        $resForeign = $this->actingAs($this->technician)
            ->postJson(route('admin.inventory.requests.store'), [
                'warehouse_location_id' => $this->warehouseA->id,
                'reason' => 'Coba ambil produk cross tenant',
                'items' => [
                    [
                        'product_id' => $foreignProduct->id,
                        'quantity_requested' => 1,
                    ],
                ],
            ]);

        $this->assertTrue(in_array($resForeign->status(), [404, 422], true));

        // 2. Produk tipe asset serial ditolak untuk permintaan massal
        $assetProduct = Product::factory()->create([
            'company_id' => $this->companyA->id,
            'name' => 'ONT Fiberhome Serialized',
            'type' => 'asset',
            'is_active' => true,
        ]);

        $resAsset = $this->actingAs($this->technician)
            ->postJson(route('admin.inventory.requests.store'), [
                'warehouse_location_id' => $this->warehouseA->id,
                'reason' => 'Coba ambil asset serial',
                'items' => [
                    [
                        'product_id' => $assetProduct->id,
                        'quantity_requested' => 1,
                    ],
                ],
            ]);

        $resAsset->assertStatus(422);
        $this->assertStringContainsString('asset', strtolower($resAsset->json('message') ?? ''));
    }

    public function test_permission_matrix_and_technician_cannot_directly_issue(): void
    {
        $req = InventoryRequest::create([
            'company_id' => $this->companyA->id,
            'code' => 'REQ-PERM-001',
            'requested_by' => $this->technician->id,
            'warehouse_location_id' => $this->warehouseA->id,
            'status' => 'submitted',
            'reason' => 'Uji permission matrix',
            'submitted_at' => now(),
        ]);

        $req->items()->create([
            'company_id' => $this->companyA->id,
            'product_id' => $this->cableProduct->id,
            'source_location_id' => $this->warehouseA->id,
            'quantity_requested' => 5,
        ]);

        // Teknisi mencoba approve -> 403 Forbidden
        $resApprove = $this->actingAs($this->technician)
            ->postJson(route('admin.inventory.requests.approve', $req->id));
        $resApprove->assertStatus(403);

        // Manager approve
        $this->actingAs($this->managerApprover)->postJson(route('admin.inventory.requests.approve', $req->id))->assertStatus(200);

        // Teknisi mencoba prepare -> 403 Forbidden
        $resPrepare = $this->actingAs($this->technician)
            ->postJson(route('admin.inventory.requests.prepare', $req->id));
        $resPrepare->assertStatus(403);

        // Staff gudang prepare & ready
        $this->actingAs($this->staffWarehouse)->postJson(route('admin.inventory.requests.prepare', $req->id))->assertStatus(200);

        // Teknisi mencoba mark ready -> 403 Forbidden
        $resReady = $this->actingAs($this->technician)
            ->postJson(route('admin.inventory.requests.ready', $req->id));
        $resReady->assertStatus(403);

        $this->actingAs($this->staffWarehouse)->postJson(route('admin.inventory.requests.ready', $req->id))->assertStatus(200);

        // Teknisi mencoba issue langsung -> 403 Forbidden
        $resIssue = $this->actingAs($this->technician)
            ->postJson(route('admin.inventory.requests.issue', $req->id));
        $resIssue->assertStatus(403);

        // User tanpa permission inventory.request apapun ditolak akses index
        $unauth = User::factory()->create(['company_id' => $this->companyA->id]);
        $unauthRes = $this->actingAs($unauth)->getJson(route('admin.inventory.requests.index'));
        $unauthRes->assertStatus(403);
    }

    public function test_server_side_datatable_filtering_sorting_and_badge_counts(): void
    {
        // Buat beberapa request
        $draftReq = InventoryRequest::create([
            'company_id' => $this->companyA->id,
            'code' => 'REQ-DT-001',
            'requested_by' => $this->technician->id,
            'warehouse_location_id' => $this->warehouseA->id,
            'status' => 'draft',
            'reason' => 'Perbaikan kabel blok A',
        ]);

        $submittedReq = InventoryRequest::create([
            'company_id' => $this->companyA->id,
            'code' => 'REQ-DT-002',
            'requested_by' => $this->technician->id,
            'warehouse_location_id' => $this->warehouseA->id,
            'status' => 'submitted',
            'reason' => 'Instalasi pelanggan baru',
            'submitted_at' => now(),
        ]);

        $technician2 = User::factory()->create(['company_id' => $this->companyA->id]);
        $technician2->assignRole('technician');

        $readyReq = InventoryRequest::create([
            'company_id' => $this->companyA->id,
            'code' => 'REQ-DT-003',
            'requested_by' => $technician2->id,
            'warehouse_location_id' => $this->warehouseA->id,
            'status' => 'ready_for_pickup',
            'reason' => 'Upgrade router gedung B',
            'submitted_at' => now(),
        ]);

        // Request di Company B (tidak boleh bocor ke Company A)
        $foreignReq = InventoryRequest::create([
            'company_id' => $this->companyB->id,
            'code' => 'REQ-DT-FOREIGN',
            'requested_by' => $this->technician->id,
            'warehouse_location_id' => $this->warehouseB->id,
            'status' => 'submitted',
            'reason' => 'Cross-tenant leak test',
        ]);

        // 1. Teknisi melihat tab 'my'
        $myRes = $this->actingAs($this->technician)
            ->getJson(route('admin.inventory.requests.index', ['tab' => 'my']));
        $myRes->assertStatus(200);

        $codes = collect($myRes->json('requests'))->pluck('code')->all();
        $this->assertContains('REQ-DT-001', $codes);
        $this->assertContains('REQ-DT-002', $codes);
        $this->assertNotContains('REQ-DT-003', $codes); // Milik teknisi 2
        $this->assertNotContains('REQ-DT-FOREIGN', $codes); // Milik Company B

        // Badge counts untuk teknisi:
        // warehouse_queue harus NULL (tidak bocor antrean gudang ke teknisi tanpa hak)
        $this->assertNull($myRes->json('badgeCounts.warehouse_queue'));
        $this->assertEquals(1, $myRes->json('badgeCounts.my_requests')); // 1 draft yang perlu disubmit

        // 2. Staff Gudang melihat tab 'warehouse'
        $whRes = $this->actingAs($this->staffWarehouse)
            ->getJson(route('admin.inventory.requests.index', ['tab' => 'warehouse']));
        $whRes->assertStatus(200);

        $whCodes = collect($whRes->json('requests'))->pluck('code')->all();
        $this->assertContains('REQ-DT-002', $whCodes); // submitted
        $this->assertContains('REQ-DT-003', $whCodes); // ready_for_pickup
        $this->assertNotContains('REQ-DT-001', $whCodes); // draft tidak masuk warehouse queue
        $this->assertNotContains('REQ-DT-FOREIGN', $whCodes); // Company B tidak ada

        $this->assertEquals(2, $whRes->json('badgeCounts.warehouse_queue'));

        // 3. Filter status
        $filterRes = $this->actingAs($this->staffWarehouse)
            ->getJson(route('admin.inventory.requests.index', ['status' => 'ready_for_pickup']));
        $filterCodes = collect($filterRes->json('requests'))->pluck('code')->all();
        $this->assertContains('REQ-DT-003', $filterCodes);
        $this->assertNotContains('REQ-DT-002', $filterCodes);

        // 4. Filter search by code
        $searchRes = $this->actingAs($this->staffWarehouse)
            ->getJson(route('admin.inventory.requests.index', ['search' => 'REQ-DT-002']));
        $searchCodes = collect($searchRes->json('requests'))->pluck('code')->all();
        $this->assertContains('REQ-DT-002', $searchCodes);
        $this->assertNotContains('REQ-DT-003', $searchCodes);
    }

    public function test_reservation_availability_calculation(): void
    {
        // Awalnya stok ada 10, reservasi 0 -> tersedia 10
        Stock::withoutCompany()->where('product_id', $this->cableProduct->id)->where('location_id', $this->warehouseA->id)->update([
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        // Request 1: minta 6
        $req1 = InventoryRequest::create([
            'company_id' => $this->companyA->id,
            'code' => 'REQ-AVAIL-001',
            'requested_by' => $this->technician->id,
            'warehouse_location_id' => $this->warehouseA->id,
            'status' => 'submitted',
            'reason' => 'Reservasi 6 unit',
            'submitted_at' => now(),
        ]);
        $req1->items()->create([
            'company_id' => $this->companyA->id,
            'product_id' => $this->cableProduct->id,
            'source_location_id' => $this->warehouseA->id,
            'quantity_requested' => 6,
        ]);

        // Approve Request 1 -> reserved menjadi 6, tersedia sisa 4
        $this->actingAs($this->managerApprover)->postJson(route('admin.inventory.requests.approve', $req1->id))->assertStatus(200);

        $stock = Stock::where('product_id', $this->cableProduct->id)->where('location_id', $this->warehouseA->id)->first();
        $this->assertEquals(10, (float) $stock->quantity);
        $this->assertEquals(6, (float) $stock->reserved_quantity);

        // Request 2: minta 5 (melebihi ketersediaan 4)
        $req2 = InventoryRequest::create([
            'company_id' => $this->companyA->id,
            'code' => 'REQ-AVAIL-002',
            'requested_by' => $this->technician->id,
            'warehouse_location_id' => $this->warehouseA->id,
            'status' => 'submitted',
            'reason' => 'Coba reservasi 5 unit padahal sisa 4',
            'submitted_at' => now(),
        ]);
        $req2->items()->create([
            'company_id' => $this->companyA->id,
            'product_id' => $this->cableProduct->id,
            'source_location_id' => $this->warehouseA->id,
            'quantity_requested' => 5,
        ]);

        // Coba approve Request 2 -> harus gagal 422 karena hanya ada 4 yang tersedia
        $resFail = $this->actingAs($this->managerApprover)->postJson(route('admin.inventory.requests.approve', $req2->id));
        $resFail->assertStatus(422);

        // Coba approve Request 2 dengan approved quantity 4 -> sukses!
        $resOk = $this->actingAs($this->managerApprover)->postJson(route('admin.inventory.requests.approve', $req2->id), [
            'items' => [
                [
                    'id' => $req2->items->first()->id,
                    'quantity_approved' => 4,
                    'source_location_id' => $this->warehouseA->id,
                ],
            ],
        ]);
        $resOk->assertStatus(200);

        $stock->refresh();
        $this->assertEquals(10, (float) $stock->quantity);
        $this->assertEquals(10, (float) $stock->reserved_quantity); // Sisa 0
    }

    public function test_staff_operator_cannot_approve_or_reject_requests(): void
    {
        $req = $this->submittedRequest('REQ-SOD-001');

        $this->actingAs($this->staffWarehouse)
            ->postJson(route('admin.inventory.requests.approve', $req->id))
            ->assertStatus(403);
        $this->actingAs($this->staffWarehouse)
            ->postJson(route('admin.inventory.requests.reject', $req->id), ['rejection_reason' => 'Bukan wewenang staff'])
            ->assertStatus(403);

        $this->assertEquals('submitted', $req->fresh()->status);
    }

    public function test_manager_controller_cannot_prepare_or_issue_goods(): void
    {
        $req = $this->submittedRequest('REQ-SOD-002');
        $this->actingAs($this->managerApprover)
            ->postJson(route('admin.inventory.requests.approve', $req->id))
            ->assertStatus(200);

        $this->actingAs($this->managerApprover)
            ->postJson(route('admin.inventory.requests.prepare', $req->id))
            ->assertStatus(403);

        $this->actingAs($this->staffWarehouse)->postJson(route('admin.inventory.requests.prepare', $req->id))->assertStatus(200);
        $this->actingAs($this->staffWarehouse)->postJson(route('admin.inventory.requests.ready', $req->id))->assertStatus(200);

        $this->actingAs($this->managerApprover)
            ->postJson(route('admin.inventory.requests.issue', $req->id))
            ->assertStatus(403);
        $this->assertEquals('ready_for_pickup', $req->fresh()->status);
    }

    public function test_approver_cannot_prepare_or_issue_what_they_approved_even_with_every_permission(): void
    {
        $req = $this->submittedRequest('REQ-SOD-003');

        // Admin holds approve + prepare + issue; the approver != preparer/issuer guard still binds.
        $this->actingAs($this->adminUser)->postJson(route('admin.inventory.requests.approve', $req->id))->assertStatus(200);
        $this->actingAs($this->adminUser)->postJson(route('admin.inventory.requests.prepare', $req->id))->assertStatus(403);

        $this->actingAs($this->staffWarehouse)->postJson(route('admin.inventory.requests.prepare', $req->id))->assertStatus(200);
        $this->actingAs($this->staffWarehouse)->postJson(route('admin.inventory.requests.ready', $req->id))->assertStatus(200);

        $this->actingAs($this->adminUser)->postJson(route('admin.inventory.requests.issue', $req->id))->assertStatus(403);
        $this->assertEquals('ready_for_pickup', $req->fresh()->status);

        $this->actingAs($this->staffWarehouse)->postJson(route('admin.inventory.requests.issue', $req->id))->assertStatus(200);
        $this->assertEquals('issued', $req->fresh()->status);
    }

    public function test_service_rejects_approver_as_preparer_and_issuer_even_when_policy_is_bypassed(): void
    {
        $req = $this->submittedRequest('REQ-SOD-004');
        $this->actingAs($this->adminUser)->postJson(route('admin.inventory.requests.approve', $req->id))->assertStatus(200);

        $this->assertMakerCheckerAbort(
            'Maker-checker: penyetujui tidak boleh menyiapkan barang.',
            fn () => InventoryRequestService::prepare($req->fresh(), $this->adminUser),
        );

        $this->actingAs($this->staffWarehouse)->postJson(route('admin.inventory.requests.prepare', $req->id))->assertStatus(200);
        $this->actingAs($this->staffWarehouse)->postJson(route('admin.inventory.requests.ready', $req->id))->assertStatus(200);

        $this->assertMakerCheckerAbort(
            'Maker-checker: penyetujui tidak boleh melakukan serah terima (issue) barang.',
            fn () => InventoryRequestService::issue($req->fresh(), $this->adminUser),
        );
    }

    private function assertMakerCheckerAbort(string $message, callable $call): void
    {
        try {
            $call();
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame($message, $e->getMessage());

            return;
        }

        $this->fail("Expected maker-checker abort: {$message}");
    }

    private function submittedRequest(string $code, int $quantity = 5): InventoryRequest
    {
        $req = InventoryRequest::create([
            'company_id' => $this->companyA->id,
            'code' => $code,
            'requested_by' => $this->technician->id,
            'warehouse_location_id' => $this->warehouseA->id,
            'status' => 'submitted',
            'reason' => 'Uji pemisahan tugas',
            'submitted_at' => now(),
        ]);

        $req->items()->create([
            'company_id' => $this->companyA->id,
            'product_id' => $this->cableProduct->id,
            'source_location_id' => $this->warehouseA->id,
            'quantity_requested' => $quantity,
        ]);

        return $req;
    }
}
