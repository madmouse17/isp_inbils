<?php

namespace Tests\Feature\Admin;

use App\Http\Resources\ApprovalInboxItemResource;
use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\User;
use App\Queries\Admin\ApprovalInboxQuery;
use App\Services\Core\CompanyService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\InventoryRequestItem;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Tests\Traits\WithoutAppOnlyRedirect;

class ApprovalInboxInventoryTest extends TestCase
{
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        foreach (['spk.approve', 'spk.reject', 'inventory.request.approve', 'inventory.request.reject'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }

    public function test_inventory_request_submitted_only_listed_in_approval_inbox(): void
    {
        [$company, $manager] = $this->approver();
        $technician = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $this->grantRole($technician, 'technician');

        $warehouse = $this->warehouse($company);
        $product = $this->product($company, $warehouse, 100);

        // 1. Submitted request -> must be listed
        $submittedReq = $this->inventoryRequest($company, $technician, $warehouse, $product, [
            'status' => 'submitted',
            'code' => 'REQ-SUB-001',
            'reason' => 'Permintaan FO Baru',
        ]);

        // 2. Non-submitted statuses -> must NOT be listed in waiting inbox
        $this->inventoryRequest($company, $technician, $warehouse, $product, ['status' => 'draft', 'code' => 'REQ-DRAFT-001']);
        $this->inventoryRequest($company, $technician, $warehouse, $product, ['status' => 'approved_reserved', 'code' => 'REQ-APP-001']);
        $this->inventoryRequest($company, $technician, $warehouse, $product, ['status' => 'preparing', 'code' => 'REQ-PREP-001']);
        $this->inventoryRequest($company, $technician, $warehouse, $product, ['status' => 'ready_for_pickup', 'code' => 'REQ-RDY-001']);
        $this->inventoryRequest($company, $technician, $warehouse, $product, ['status' => 'issued', 'code' => 'REQ-ISS-001']);
        $this->inventoryRequest($company, $technician, $warehouse, $product, ['status' => 'rejected', 'code' => 'REQ-REJ-001']);
        $this->inventoryRequest($company, $technician, $warehouse, $product, ['status' => 'cancelled', 'code' => 'REQ-CAN-001']);

        // Check via HTTP endpoint
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['domain' => 'inventory_request']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Approvals/Index')
                ->has('items.data', 1)
                ->where('items.data.0.id', $submittedReq->public_id)
                ->where('items.data.0.domain', 'inventory_request')
                ->where('items.data.0.code', 'REQ-SUB-001')
                ->where('items.data.0.title', 'Permintaan FO Baru')
                ->where('items.data.0.priority', 'medium')
                ->where('items.data.0.actions.approve', true)
                ->where('items.data.0.actions.reject', true)
                ->where('items.data.0.actions.view', true)
                ->where('items.data.0.detail_url', route('admin.inventory.requests.show', $submittedReq))
            );
    }

    public function test_inventory_request_maker_checker_fail_closed_and_single_source_of_truth_parity(): void
    {
        [$company, $manager] = $this->approver();
        $warehouse = $this->warehouse($company);
        $product = $this->product($company, $warehouse, 100);

        // Manager himself creates a submitted inventory request
        $ownReq = $this->inventoryRequest($company, $manager, $warehouse, $product, [
            'status' => 'submitted',
            'code' => 'REQ-OWN-001',
        ]);

        // Other technician creates a submitted inventory request
        $tech = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $this->grantRole($tech, 'technician');
        $otherReq = $this->inventoryRequest($company, $tech, $warehouse, $product, [
            'status' => 'submitted',
            'code' => 'REQ-OTHER-001',
        ]);

        // 1. In the inbox: manager only sees otherReq, NEVER ownReq
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['domain' => 'inventory_request']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Approvals/Index')
                ->has('items.data', 1)
                ->where('items.data.0.id', $otherReq->public_id)
                ->where('summary.waiting_for_me', 1)
            );

        // 2. Resource check for ownReq: actions.approve and actions.reject MUST be false
        $request = Request::create(route('admin.approvals.index'));
        $request->setUserResolver(fn () => $manager);

        $resource = (new ApprovalInboxItemResource($ownReq))->toArray($request);
        $this->assertFalse($resource['actions']['approve']);
        $this->assertFalse($resource['actions']['reject']);

        // 3. Maker-checker single source of truth parity: listCount === summary.waiting_for_me === badgeCount
        $listCount = ApprovalInboxQuery::filtered($request)->count();
        $summary = ApprovalInboxQuery::summary($request);
        $badgeCount = ApprovalInboxQuery::badgeCount($request);

        $this->assertSame(1, $listCount);
        $this->assertSame(1, $summary['waiting_for_me']);
        $this->assertSame(1, $badgeCount);

        // 4. Direct POST by manager to approve own request must fail-closed (403 forbidden)
        $this->actingAs($manager)
            ->post(route('admin.inventory.requests.approve', $ownReq), [
                'items' => [['id' => $ownReq->items->first()->public_id, 'quantity_approved' => 1]],
            ])
            ->assertForbidden();

        // 5. Direct POST by manager to reject own request must fail-closed (403 forbidden)
        $this->actingAs($manager)
            ->post(route('admin.inventory.requests.reject', $ownReq), [
                'rejection_reason' => 'Rejecting myself',
            ])
            ->assertForbidden();
    }

    public function test_inventory_request_two_tenants_isolation(): void
    {
        [$companyA, $managerA] = $this->approver();
        $companyB = Company::factory()->create(['is_active' => true]);
        $managerB = User::factory()->create(['company_id' => $companyB->id, 'is_active' => true]);
        $this->grantRole($managerB, 'manager');

        $warehouseA = $this->warehouse($companyA);
        $productA = $this->product($companyA, $warehouseA, 50);

        $warehouseB = $this->warehouse($companyB);
        $productB = $this->product($companyB, $warehouseB, 50);

        $techA = User::factory()->create(['company_id' => $companyA->id, 'is_active' => true]);
        $techB = User::factory()->create(['company_id' => $companyB->id, 'is_active' => true]);

        $reqA = $this->inventoryRequest($companyA, $techA, $warehouseA, $productA, ['code' => 'REQ-A-001', 'status' => 'submitted']);
        $reqB = $this->inventoryRequest($companyB, $techB, $warehouseB, $productB, ['code' => 'REQ-B-001', 'status' => 'submitted']);

        // Manager A cannot see Company B request
        $this->actingAs($managerA)
            ->get(route('admin.approvals.index', ['domain' => 'inventory_request']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.id', $reqA->public_id)
                ->where('items.data.0.code', 'REQ-A-001')
            );

        // Manager B cannot see Company A request
        $this->actingAs($managerB)
            ->get(route('admin.approvals.index', ['domain' => 'inventory_request']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.id', $reqB->public_id)
                ->where('items.data.0.code', 'REQ-B-001')
            );

        // Direct cross-tenant POST approve fails-closed (403 forbidden or 404 not found)
        $approveResponse = $this->actingAs($managerB)
            ->post(route('admin.inventory.requests.approve', $reqA), [
                'items' => [['id' => $reqA->items->first()->public_id, 'quantity_approved' => 1]],
            ]);
        $this->assertTrue(in_array($approveResponse->getStatusCode(), [403, 404], true));

        // Direct cross-tenant POST reject fails-closed (403 forbidden or 404 not found)
        $rejectResponse = $this->actingAs($managerB)
            ->post(route('admin.inventory.requests.reject', $reqA), [
                'rejection_reason' => 'Cross tenant attack',
            ]);
        $this->assertTrue(in_array($rejectResponse->getStatusCode(), [403, 404], true));
    }

    public function test_inventory_request_permission_matrix_and_unauthorized_direct_requests(): void
    {
        [$company] = $this->approver();
        $warehouse = $this->warehouse($company);
        $product = $this->product($company, $warehouse, 100);
        $tech = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);

        $req = $this->inventoryRequest($company, $tech, $warehouse, $product, [
            'status' => 'submitted',
            'code' => 'REQ-PERM-001',
        ]);

        // 1. Actor with ONLY inventory.request.approve (no reject, no view permission)
        $approverOnly = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $approverOnly->givePermissionTo('inventory.request.approve');

        $request = Request::create(route('admin.approvals.index'));
        $request->setUserResolver(fn () => $approverOnly);
        $resource = (new ApprovalInboxItemResource($req))->toArray($request);

        $this->assertTrue($resource['actions']['approve']);
        $this->assertFalse($resource['actions']['reject']);
        $this->assertFalse($resource['actions']['view'], 'Actor approve-only without view permission must advertise actions.view=false');
        $this->assertNull($resource['detail_url'], 'Actor approve-only without view permission must have null detail_url');

        // Direct POST to reject fails-closed (403)
        $this->actingAs($approverOnly)
            ->post(route('admin.inventory.requests.reject', $req), ['rejection_reason' => 'Not allowed'])
            ->assertForbidden();

        // Crafted direct GET to show route fails-closed (403)
        $this->actingAs($approverOnly)
            ->get(route('admin.inventory.requests.show', $req))
            ->assertForbidden();

        // When granted view permission, affordance is honestly enabled
        $approverOnly->givePermissionTo('inventory.request.view.all');
        $resourceWithView = (new ApprovalInboxItemResource($req))->toArray($request);
        $this->assertTrue($resourceWithView['actions']['view']);
        $this->assertSame(route('admin.inventory.requests.show', $req), $resourceWithView['detail_url']);
        $this->actingAs($approverOnly)
            ->get(route('admin.inventory.requests.show', $req))
            ->assertOk();

        // 2. Actor with ONLY inventory.request.reject (no approve, no view permission)
        $rejecterOnly = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $rejecterOnly->givePermissionTo('inventory.request.reject');

        $requestReject = Request::create(route('admin.approvals.index'));
        $requestReject->setUserResolver(fn () => $rejecterOnly);
        $resourceReject = (new ApprovalInboxItemResource($req))->toArray($requestReject);

        $this->assertFalse($resourceReject['actions']['approve']);
        $this->assertTrue($resourceReject['actions']['reject']);
        $this->assertFalse($resourceReject['actions']['view'], 'Actor reject-only without view permission must advertise actions.view=false');
        $this->assertNull($resourceReject['detail_url'], 'Actor reject-only without view permission must have null detail_url');

        // Direct POST to approve fails-closed (403)
        $this->actingAs($rejecterOnly)
            ->post(route('admin.inventory.requests.approve', $req), [
                'items' => [['id' => $req->items->first()->public_id, 'quantity_approved' => 1]],
            ])
            ->assertForbidden();

        // Crafted direct GET to show route fails-closed (403)
        $this->actingAs($rejecterOnly)
            ->get(route('admin.inventory.requests.show', $req))
            ->assertForbidden();

        // 3. Actor without ANY inventory permissions (e.g. technician)
        $unauthorized = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $this->grantRole($unauthorized, 'technician');

        $this->actingAs($unauthorized)
            ->get(route('admin.approvals.index'))
            ->assertForbidden();
    }

    public function test_inventory_request_canonical_action_reuse_and_side_effects(): void
    {
        [$company, $manager] = $this->approver();
        $warehouse = $this->warehouse($company);
        $product = $this->product($company, $warehouse, 100);
        $tech = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);

        // 1. Approve canonical flow
        $reqToApprove = $this->inventoryRequest($company, $tech, $warehouse, $product, [
            'status' => 'submitted',
            'code' => 'REQ-ACTION-001',
        ]);

        $this->actingAs($manager)
            ->post(route('admin.inventory.requests.approve', $reqToApprove), [
                'items' => [['id' => $reqToApprove->items->first()->public_id, 'quantity_approved' => 2]],
            ])
            ->assertRedirect();

        $freshApproved = $reqToApprove->fresh();
        $this->assertSame('approved_reserved', $freshApproved->status);
        $this->assertSame($manager->id, $freshApproved->approved_by);
        $this->assertNotNull($freshApproved->approved_at);

        // Check stock reserved
        $stock = Stock::where('company_id', $company->id)
            ->where('product_id', $product->id)
            ->where('location_id', $warehouse->id)
            ->first();
        $this->assertEquals(2, $stock->reserved_quantity);

        // Inbox removal check
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['domain' => 'inventory_request']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 0));

        // 2. Reject canonical flow
        $reqToReject = $this->inventoryRequest($company, $tech, $warehouse, $product, [
            'status' => 'submitted',
            'code' => 'REQ-ACTION-002',
        ]);

        // Missing reason fails validation (422)
        $this->actingAs($manager)
            ->post(route('admin.inventory.requests.reject', $reqToReject), [])
            ->assertSessionHasErrors(['rejection_reason']);

        // Valid reject
        $this->actingAs($manager)
            ->post(route('admin.inventory.requests.reject', $reqToReject), [
                'rejection_reason' => 'Stok tidak mencukupi untuk request ini',
            ])
            ->assertRedirect();

        $freshRejected = $reqToReject->fresh();
        $this->assertSame('rejected', $freshRejected->status);
        $this->assertSame($manager->id, $freshRejected->rejected_by);
        $this->assertSame('Stok tidak mencukupi untuk request ini', $freshRejected->rejection_reason);

        // Inbox removal check
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['domain' => 'inventory_request']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 0));
    }

    public function test_inventory_request_stale_or_double_action_prevention_on_inertia(): void
    {
        [$company, $manager] = $this->approver();
        $warehouse = $this->warehouse($company);
        $product = $this->product($company, $warehouse, 100);
        $tech = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);

        $req = $this->inventoryRequest($company, $tech, $warehouse, $product, [
            'status' => 'submitted',
            'code' => 'REQ-STALE-001',
        ]);

        // First approval succeeds
        $this->actingAs($manager)
            ->post(route('admin.inventory.requests.approve', $req), [
                'items' => [['id' => $req->items->first()->public_id, 'quantity_approved' => 1]],
            ])
            ->assertRedirect();

        $this->assertSame('approved_reserved', $req->fresh()->status);

        // Second approval via Inertia returns 302 redirect with session error
        $approveResponse = $this->actingAs($manager)
            ->from(route('admin.approvals.index'))
            ->withHeader('X-Inertia', 'true')
            ->post(route('admin.inventory.requests.approve', $req), [
                'items' => [['id' => $req->items->first()->public_id, 'quantity_approved' => 1]],
            ]);

        $approveResponse->assertRedirect(route('admin.approvals.index'))
            ->assertSessionHasErrors([
                'error' => 'Permintaan barang tidak dapat disetujui karena status telah berubah atau Anda tidak berwenang.',
            ]);

        // Stale rejection via Inertia returns 302 redirect with session error
        $rejectResponse = $this->actingAs($manager)
            ->from(route('admin.approvals.index'))
            ->withHeader('X-Inertia', 'true')
            ->post(route('admin.inventory.requests.reject', $req), [
                'rejection_reason' => 'Too late',
            ]);

        $rejectResponse->assertRedirect(route('admin.approvals.index'))
            ->assertSessionHasErrors([
                'error' => 'Permintaan barang tidak dapat ditolak karena status telah berubah atau Anda tidak berwenang.',
            ]);
    }

    public function test_inventory_request_filtering_and_sorting(): void
    {
        [$company, $manager] = $this->approver();
        $warehouse = $this->warehouse($company);
        $product = $this->product($company, $warehouse, 100);

        $techA = User::factory()->create(['company_id' => $company->id, 'name' => 'Tech Alpha', 'is_active' => true]);
        $techB = User::factory()->create(['company_id' => $company->id, 'name' => 'Tech Beta', 'is_active' => true]);

        $req1 = $this->inventoryRequest($company, $techA, $warehouse, $product, [
            'code' => 'REQ-AAA-001',
            'reason' => 'Kabel drop FO Baru',
            'source_type' => 'installation',
            'created_at' => now()->subHours(5),
            'status' => 'submitted',
        ]);

        $req2 = $this->inventoryRequest($company, $techB, $warehouse, $product, [
            'code' => 'REQ-BBB-002',
            'reason' => 'Patchcord pengganti',
            'source_type' => 'maintenance',
            'created_at' => now()->subHours(1),
            'status' => 'submitted',
        ]);

        // 1. Filter by search
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['search' => 'Kabel drop']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.id', $req1->public_id)
            );

        // 2. Filter by type
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['type' => 'maintenance']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.id', $req2->public_id)
            );

        // 3. Filter by creator
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['creator' => $techA->public_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.id', $req1->public_id)
            );

        // 4. Non-requester creator ID is ignored safely
        $foreignUser = User::factory()->create(['is_active' => true]);
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['creator' => $foreignUser->public_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 2)
            );

        // 5. Sorting by code asc / desc
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['sort' => 'code', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.data.0.id', $req1->public_id)
                ->where('items.data.1.id', $req2->public_id)
            );

        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['sort' => 'code', 'direction' => 'desc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.data.0.id', $req2->public_id)
                ->where('items.data.1.id', $req1->public_id)
            );
    }

    public function test_unified_approval_inbox_spk_and_inventory_request_parity(): void
    {
        [$company, $manager] = $this->approver();
        $warehouse = $this->warehouse($company);
        $product = $this->product($company, $warehouse, 100);

        $techA = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $techB = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);

        // Create 1 SPK (its requester is the technician who did the work) and 1 Inventory Request
        $spk = $this->waitingSpk($company, $techA, ['code' => 'SPK-UNI-001', 'assigned_to' => $techA->id, 'created_at' => now()->subHours(2)]);
        $inv = $this->inventoryRequest($company, $techB, $warehouse, $product, [
            'code' => 'REQ-UNI-001',
            'created_at' => now()->subHour(),
            'status' => 'submitted',
        ]);

        // Hit unified approval inbox (no domain filter)
        $this->actingAs($manager)
            ->get(route('admin.approvals.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Approvals/Index')
                ->has('items.data', 2)
                ->where('summary.waiting_for_me', 2)
                ->where('summary.processed', 0)
                ->has('types')
                ->has('requesters', 2)
            );

        // Filter domain=spk only gets SPK
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['domain' => 'spk']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.domain', 'spk')
                ->where('items.data.0.id', $spk->public_id)
            );

        // Filter domain=inventory_request only gets Inventory Request
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['domain' => 'inventory_request']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.domain', 'inventory_request')
                ->where('items.data.0.id', $inv->public_id)
            );
    }

    public function test_unified_approval_inbox_pagination_order_with_nullable_normalized_values_and_tie_breakers(): void
    {
        [$company, $manager] = $this->approver();
        $warehouse = $this->warehouse($company);
        $product = $this->product($company, $warehouse, 100);
        $tech = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);

        // 1. Test sorting by type across > per_page items (per_page is 10)
        // 11 items with nullable source_type (normalizes to 'inventory_request')
        for ($i = 1; $i <= 11; $i++) {
            $this->inventoryRequest($company, $tech, $warehouse, $product, [
                'code' => sprintf('REQ-NULL-TYPE-%02d', $i),
                'source_type' => null,
                'status' => 'submitted',
            ]);
        }

        // 1 item with source_type = 'aaa_custom' (should appear first in asc)
        $this->inventoryRequest($company, $tech, $warehouse, $product, [
            'code' => 'REQ-AAA-TYPE',
            'source_type' => 'aaa_custom',
            'status' => 'submitted',
        ]);

        // 1 item with source_type = 'zzz_custom' (should appear first in desc)
        $this->inventoryRequest($company, $tech, $warehouse, $product, [
            'code' => 'REQ-ZZZ-TYPE',
            'source_type' => 'zzz_custom',
            'status' => 'submitted',
        ]);

        // SPK with type = 'installation'
        $this->waitingSpk($company, $tech, [
            'code' => 'SPK-INSTALL-TYPE',
            'type' => 'installation',
        ]);

        // Total items = 11 + 1 + 1 + 1 = 14 items > 10 perPage

        // Type Ascending:
        // 'aaa_custom' < 'installation' < 'inventory_request' (from null source_type) < 'zzz_custom'
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['sort' => 'type', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 10)
                ->where('items.data.0.domain', 'inventory_request')
                ->where('items.data.0.code', 'REQ-AAA-TYPE')
                ->where('items.data.0.type', 'aaa_custom')
                ->where('items.data.1.domain', 'spk')
                ->where('items.data.1.code', 'SPK-INSTALL-TYPE')
                ->where('items.data.1.type', 'installation')
                ->where('items.data.2.domain', 'inventory_request')
                ->where('items.data.2.type', 'inventory_request')
            );

        // Type Descending:
        // 'zzz_custom' > 'inventory_request' > 'installation' > 'aaa_custom'
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['sort' => 'type', 'direction' => 'desc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 10)
                ->where('items.data.0.domain', 'inventory_request')
                ->where('items.data.0.code', 'REQ-ZZZ-TYPE')
                ->where('items.data.0.type', 'zzz_custom')
            );

        // 2. Test sorting by title across > per_page items with nullable reason
        // 11 items with null reason (normalizes to 'Permintaan Material #' . code)
        for ($i = 1; $i <= 11; $i++) {
            $this->inventoryRequest($company, $tech, $warehouse, $product, [
                'code' => sprintf('REQ-NULL-TITL-%02d', $i),
                'reason' => null,
                'status' => 'submitted',
            ]);
        }

        $this->inventoryRequest($company, $tech, $warehouse, $product, [
            'code' => 'REQ-AAA-TITLE',
            'reason' => 'AAA Early Title Reason',
            'status' => 'submitted',
        ]);

        $this->inventoryRequest($company, $tech, $warehouse, $product, [
            'code' => 'REQ-ZZZ-TITLE',
            'reason' => 'ZZZ Late Title Reason',
            'status' => 'submitted',
        ]);

        // Title Ascending:
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['sort' => 'title', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.data.0.domain', 'inventory_request')
                ->where('items.data.0.code', 'REQ-AAA-TITLE')
                ->where('items.data.0.title', 'AAA Early Title Reason')
            );

        // Title Descending:
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['sort' => 'title', 'direction' => 'desc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.data.0.domain', 'inventory_request')
                ->where('items.data.0.code', 'REQ-ZZZ-TITLE')
                ->where('items.data.0.title', 'ZZZ Late Title Reason')
            );

        // 3. Test cross-domain tie-breaking when primary sort values match
        // Both SPK and Inventory Request with identical priority 'medium' and same created_at
        $fixedTime = now()->subMinutes(30);
        $tieSpk = $this->waitingSpk($company, $tech, [
            'code' => 'SPK-TIE-001',
            'created_at' => $fixedTime,
        ]);
        $tieInv = $this->inventoryRequest($company, $tech, $warehouse, $product, [
            'code' => 'REQ-TIE-001',
            'created_at' => $fixedTime,
            'status' => 'submitted',
        ]);

        // Query filtering specifically to those two codes
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['search' => 'TIE-001']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 2)
                // Deterministic tie-breaker: domain 'inventory_request' precedes 'spk' in alphabetical domain order
                ->where('items.data.0.domain', 'inventory_request')
                ->where('items.data.0.composite_id', "inventory_request:{$tieInv->public_id}")
                ->where('items.data.1.domain', 'spk')
                ->where('items.data.1.composite_id', "spk:{$tieSpk->public_id}")
            );
    }

    public function test_inventory_request_bounded_queries_no_n_plus_1(): void
    {
        [$company, $manager] = $this->approver();
        $warehouse = $this->warehouse($company);
        $product = $this->product($company, $warehouse, 100);
        $tech = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);

        for ($i = 1; $i <= 5; $i++) {
            $this->inventoryRequest($company, $tech, $warehouse, $product, [
                'code' => "REQ-QUERY-0{$i}",
                'status' => 'submitted',
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['domain' => 'inventory_request']))
            ->assertOk();

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 24 + 1: the shared `canTechnicianReport` prop reads a manager's employee profile once per page.
        // + 1: the shared `company.map` prop reads the system map defaults in one query when the company set none.
        // + 1: CompanyService::canActIn() reads is_active of the home company once per request.
        $this->assertLessThan(28, $queryCount, "Expected bounded query count, got {$queryCount} queries.");
    }

    public function test_unified_approval_inbox_pagination_title_sorting_with_reason_zero_preserves_page_boundaries(): void
    {
        [$company, $manager] = $this->approver();
        $warehouse = $this->warehouse($company);
        $product = $this->product($company, $warehouse, 100);
        $tech = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);

        // 1. Direct resource test for reason='0' and source_type='0'
        $zeroReq = $this->inventoryRequest($company, $tech, $warehouse, $product, [
            'code' => 'REQ-ZERO',
            'reason' => '0',
            'source_type' => '0',
            'status' => 'submitted',
        ]);
        $emptyReq = $this->inventoryRequest($company, $tech, $warehouse, $product, [
            'code' => 'REQ-EMPTY',
            'reason' => '',
            'source_type' => '',
            'status' => 'submitted',
        ]);
        $nullReq = $this->inventoryRequest($company, $tech, $warehouse, $product, [
            'code' => 'REQ-NULL',
            'reason' => null,
            'source_type' => null,
            'status' => 'submitted',
        ]);

        $request = Request::create(route('admin.approvals.index'));
        $request->setUserResolver(fn () => $manager);

        $zeroResource = (new ApprovalInboxItemResource($zeroReq))->toArray($request);
        $this->assertSame('0', $zeroResource['title'], "Expected title '0' to be preserved in resource.");
        $this->assertSame('0', $zeroResource['type'], "Expected type '0' to be preserved in resource.");

        $emptyResource = (new ApprovalInboxItemResource($emptyReq))->toArray($request);
        $this->assertSame('Permintaan Material #REQ-EMPTY', $emptyResource['title']);
        $this->assertSame('inventory_request', $emptyResource['type']);

        $nullResource = (new ApprovalInboxItemResource($nullReq))->toArray($request);
        $this->assertSame('Permintaan Material #REQ-NULL', $nullResource['title']);
        $this->assertSame('inventory_request', $nullResource['type']);

        // Clean up direct test items to avoid interfering with dataset boundary test
        InventoryRequestItem::withoutCompany()->delete();
        InventoryRequest::withoutCompany()->delete();

        // 2. Dataset of 10 items with titles B-title through K-title + 1 item with reason='0'
        // Total = 11 items (> per_page 10)
        $this->inventoryRequest($company, $tech, $warehouse, $product, [
            'code' => 'REQ-ZZZ-ZERO',
            'reason' => '0',
            'status' => 'submitted',
        ]);

        $letters = ['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'];
        foreach ($letters as $letter) {
            $this->inventoryRequest($company, $tech, $warehouse, $product, [
                'code' => "REQ-{$letter}",
                'reason' => "{$letter}-title",
                'status' => 'submitted',
            ]);
        }

        // Ascending sort: '0' < 'B-title' ... < 'K-title'
        // Page 1 should have REQ-ZZZ-ZERO ('0') followed by B through J
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['sort' => 'title', 'direction' => 'asc', 'page' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 10)
                ->where('items.data.0.code', 'REQ-ZZZ-ZERO')
                ->where('items.data.0.title', '0')
                ->where('items.data.1.code', 'REQ-B')
                ->where('items.data.1.title', 'B-title')
                ->where('items.data.9.code', 'REQ-J')
                ->where('items.data.9.title', 'J-title')
                ->where('items.meta.total', 11)
                ->where('items.meta.current_page', 1)
            );

        // Page 2 should have REQ-K ('K-title')
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['sort' => 'title', 'direction' => 'asc', 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.code', 'REQ-K')
                ->where('items.data.0.title', 'K-title')
                ->where('items.meta.total', 11)
                ->where('items.meta.current_page', 2)
            );

        // Descending sort: 'K-title' down to 'B-title', and '0' at the very end
        // Page 1 should have K down to B
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['sort' => 'title', 'direction' => 'desc', 'page' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 10)
                ->where('items.data.0.code', 'REQ-K')
                ->where('items.data.0.title', 'K-title')
                ->where('items.data.9.code', 'REQ-B')
                ->where('items.data.9.title', 'B-title')
                ->where('items.meta.total', 11)
                ->where('items.meta.current_page', 1)
            );

        // Page 2 should have REQ-ZZZ-ZERO ('0')
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['sort' => 'title', 'direction' => 'desc', 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.code', 'REQ-ZZZ-ZERO')
                ->where('items.data.0.title', '0')
                ->where('items.meta.total', 11)
                ->where('items.meta.current_page', 2)
            );

        // 3. Parallel audit: source_type='0' deterministic pagination order across > per_page items
        InventoryRequestItem::query()->delete();
        InventoryRequest::query()->delete();

        $this->inventoryRequest($company, $tech, $warehouse, $product, [
            'code' => 'REQ-TYPE-ZERO',
            'source_type' => '0',
            'status' => 'submitted',
        ]);

        foreach ($letters as $letter) {
            $this->inventoryRequest($company, $tech, $warehouse, $product, [
                'code' => "REQ-TYPE-{$letter}",
                'source_type' => strtolower($letter).'_type',
                'status' => 'submitted',
            ]);
        }

        // Ascending sort by type: '0' < 'b_type' ... < 'k_type'
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['sort' => 'type', 'direction' => 'asc', 'page' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 10)
                ->where('items.data.0.code', 'REQ-TYPE-ZERO')
                ->where('items.data.0.type', '0')
                ->where('items.data.1.code', 'REQ-TYPE-B')
                ->where('items.data.1.type', 'b_type')
                ->where('items.data.9.code', 'REQ-TYPE-J')
                ->where('items.data.9.type', 'j_type')
                ->where('items.meta.total', 11)
                ->where('items.meta.current_page', 1)
            );

        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['sort' => 'type', 'direction' => 'asc', 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.code', 'REQ-TYPE-K')
                ->where('items.data.0.type', 'k_type')
                ->where('items.meta.total', 11)
                ->where('items.meta.current_page', 2)
            );

        // Descending sort by type: 'k_type' down to 'b_type', and '0' on page 2
        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['sort' => 'type', 'direction' => 'desc', 'page' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 10)
                ->where('items.data.0.code', 'REQ-TYPE-K')
                ->where('items.data.0.type', 'k_type')
                ->where('items.data.9.code', 'REQ-TYPE-B')
                ->where('items.data.9.type', 'b_type')
                ->where('items.meta.total', 11)
                ->where('items.meta.current_page', 1)
            );

        $this->actingAs($manager)
            ->get(route('admin.approvals.index', ['sort' => 'type', 'direction' => 'desc', 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.code', 'REQ-TYPE-ZERO')
                ->where('items.data.0.type', '0')
                ->where('items.meta.total', 11)
                ->where('items.meta.current_page', 2)
            );
    }

    private function approver(): array
    {
        $company = Company::factory()->create(['is_active' => true]);
        $manager = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->grantRole($manager, 'manager');
        CompanyService::useCompanyForTests($company->id);

        return [$company, $manager];
    }

    private function warehouse(Company $company): Location
    {
        return Location::create([
            'company_id' => $company->id,
            'code' => 'LOC-WH-'.fake()->unique()->numberBetween(1, 99999),
            'name' => 'Gudang Utama',
            'type' => 'site',
            'path' => 'Gudang',
            'is_active' => true,
        ]);
    }

    private function product(Company $company, Location $warehouse, float $stockQty = 100): Product
    {
        $product = Product::factory()->create([
            'company_id' => $company->id,
            'name' => 'Kabel FO Dropcore '.fake()->unique()->word(),
            'type' => 'consumable',
            'is_active' => true,
        ]);

        Stock::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $warehouse->id,
            'quantity' => $stockQty,
            'reserved_quantity' => 0,
        ]);

        return $product;
    }

    private function inventoryRequest(
        Company $company,
        User $requester,
        Location $warehouse,
        Product $product,
        array $extra = []
    ): InventoryRequest {
        $req = InventoryRequest::create(array_merge([
            'company_id' => $company->id,
            'code' => 'REQ-TEST-'.fake()->unique()->numberBetween(1000, 9999),
            'requested_by' => $requester->id,
            'warehouse_location_id' => $warehouse->id,
            'status' => 'submitted',
            'source_type' => 'installation',
            'reason' => 'Kebutuhan instalasi baru',
            'submitted_at' => now(),
        ], $extra));

        InventoryRequestItem::create([
            'company_id' => $company->id,
            'inventory_request_id' => $req->id,
            'product_id' => $product->id,
            'quantity_requested' => 2,
            'quantity_approved' => null,
            'source_location_id' => $warehouse->id,
        ]);

        return CompanyService::runAs((int) $company->id, fn () => $req->load(['items.product', 'warehouseLocation', 'requester']));
    }

    private function waitingSpk(Company $company, ?User $creator, array $extra = []): WorkOrder
    {
        $location = Location::create([
            'company_id' => $company->id,
            'code' => 'LOC-APPROVAL-'.fake()->unique()->numberBetween(1, 99999),
            'name' => 'Approval Location',
            'type' => 'site',
            'path' => 'Approval',
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['company_id' => $company->id]);

        /** @var WorkOrder $workOrder */
        $workOrder = WorkOrderFactory::new()->create(array_merge([
            'company_id' => $company->id,
            'type' => 'maintenance',
            'status' => 'waiting_review',
            'customer_id' => $customer->id,
            'location_id' => $location->id,
            'created_by' => $creator?->id,
            'assigned_to' => null,
        ], $extra));

        $workOrder->addMediaFromString('test')
            ->usingFileName('test.jpg')
            ->withCustomProperties([
                'company_id' => $company->id,
                'type' => 'photo',
                'uploaded_by' => $creator->id,
            ])
            ->toMediaCollection('evidence', 'public');

        return $workOrder;
    }
}
