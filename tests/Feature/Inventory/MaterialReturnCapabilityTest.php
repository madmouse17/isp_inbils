<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Inventory\Http\Resources\MaterialReturnCaseResource;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\InventoryRequestItem;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Services\InventoryRequestService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class MaterialReturnCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Location $location;

    private User $technician;

    private User $approver;

    private User $receiver;

    private User $resolver;

    private User $unauthorizedUser;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = [
            'inventory.request.view.own', 'inventory.request.create', 'inventory.request.cancel.own',
            'inventory.request.view.all', 'inventory.request.approve', 'inventory.request.reject',
            'inventory.request.prepare', 'inventory.request.issue',
            'inventory.return.view.own', 'inventory.return.view.all',
            'inventory.return.declare', 'inventory.return.receive', 'inventory.return.resolve',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->company = Company::factory()->create();
        CompanyService::useCompanyForTests($this->company->id);

        $this->location = Location::factory()->create(['company_id' => $this->company->id]);

        $this->technician = User::factory()->create(['company_id' => $this->company->id]);
        $this->technician->givePermissionTo(['inventory.return.view.own', 'inventory.return.declare']);

        $this->approver = User::factory()->create(['company_id' => $this->company->id]);
        $this->approver->givePermissionTo(['inventory.request.view.all', 'inventory.request.approve']);

        $this->receiver = User::factory()->create(['company_id' => $this->company->id]);
        $this->receiver->givePermissionTo(['inventory.return.view.all', 'inventory.return.receive']);

        $this->resolver = User::factory()->create(['company_id' => $this->company->id]);
        $this->resolver->givePermissionTo(['inventory.return.view.all', 'inventory.return.resolve']);

        $this->unauthorizedUser = User::factory()->create(['company_id' => $this->company->id]);
    }

    private function createReturnCase(string $status = 'awaiting_declaration', array $attributes = []): MaterialReturnCase
    {
        $this->actingAs($this->receiver);

        $category = Category::factory()->create(['company_id' => $this->company->id]);
        $unit = Unit::factory()->create(['company_id' => $this->company->id]);
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'type' => 'consumable',
            'is_active' => true,
        ]);

        Stock::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
        ]);

        $ir = InventoryRequest::create([
            'company_id' => $this->company->id,
            'code' => 'REQ-TEST-'.uniqid(),
            'requested_by' => $this->technician->id,
            'warehouse_location_id' => $this->location->id,
            'reason' => 'Test return capabilities',
            'status' => 'draft',
        ]);

        $item = InventoryRequestItem::create([
            'company_id' => $this->company->id,
            'inventory_request_id' => $ir->id,
            'product_id' => $product->id,
            'quantity_requested' => 10,
            'source_location_id' => $this->location->id,
        ]);

        InventoryRequestService::submit($ir, $this->technician);
        InventoryRequestService::approve($ir, $this->approver, [
            'items' => [
                ['id' => $item->id, 'quantity_approved' => 10, 'source_location_id' => $this->location->id],
            ],
        ]);
        InventoryRequestService::prepare($ir, $this->receiver);
        InventoryRequestService::markReady($ir, $this->receiver);
        InventoryRequestService::issue($ir, $this->receiver);

        $case = MaterialReturnCase::where('inventory_request_id', $ir->id)->firstOrFail();

        if ($status !== 'awaiting_declaration' || ! empty($attributes)) {
            $case->forceFill(array_merge(['status' => $status], $attributes))->save();
        }

        return $case->fresh();
    }

    public function test_resource_fails_closed_when_unauthenticated(): void
    {
        $case = $this->createReturnCase('awaiting_declaration');

        $request = Request::create('/admin/inventory/returns/'.$case->id, 'GET');
        $resource = (new MaterialReturnCaseResource($case))->toArray($request);

        $this->assertArrayHasKey('can', $resource);
        $this->assertSame([
            'declare' => false,
            'receive' => false,
            'resolve' => false,
        ], $resource['can']);
    }

    public function test_technician_capabilities_in_declaration_states(): void
    {
        $caseAwaiting = $this->createReturnCase('awaiting_declaration');

        // Direct resource check with technician actor
        $request = Request::create('/admin/inventory/returns/'.$caseAwaiting->id, 'GET');
        $request->setUserResolver(fn () => $this->technician);
        $resource = (new MaterialReturnCaseResource($caseAwaiting))->toArray($request);

        $this->assertArrayHasKey('can', $resource);
        $this->assertTrue($resource['can']['declare']);
        $this->assertFalse($resource['can']['receive']);
        $this->assertFalse($resource['can']['resolve']);

        // Correction required state allows declaration as well
        $caseCorrection = $this->createReturnCase('correction_required', [
            'rejection_reason' => 'Physical discrepancy',
        ]);
        $resourceCorrection = (new MaterialReturnCaseResource($caseCorrection))->toArray($request);
        $this->assertTrue($resourceCorrection['can']['declare']);
        $this->assertFalse($resourceCorrection['can']['receive']);
        $this->assertFalse($resourceCorrection['can']['resolve']);

        // HTTP endpoint check
        $this->actingAs($this->technician);
        $response = $this->getJson(route('admin.inventory.returns.show', $caseAwaiting));
        $response->assertOk();
        $response->assertJsonPath('data.can.declare', true);
        $response->assertJsonPath('data.can.receive', false);
        $response->assertJsonPath('data.can.resolve', false);
    }

    public function test_receiver_capabilities_in_awaiting_receipt_with_maker_checker(): void
    {
        $case = $this->createReturnCase('awaiting_receipt', [
            'declared_by' => $this->technician->id,
            'declared_at' => now(),
        ]);

        // Legitimate receiver
        $this->actingAs($this->receiver);
        $response = $this->getJson(route('admin.inventory.returns.show', $case));
        $response->assertOk();
        $response->assertJsonPath('data.can.declare', false);
        $response->assertJsonPath('data.can.receive', true);
        $response->assertJsonPath('data.can.resolve', false);

        // Maker-checker invariant: technician cannot receive own return even with receive permission
        $this->technician->givePermissionTo('inventory.return.receive');
        $this->actingAs($this->technician);
        $techResponse = $this->getJson(route('admin.inventory.returns.show', $case));
        $techResponse->assertOk();
        $techResponse->assertJsonPath('data.can.declare', false);
        $techResponse->assertJsonPath('data.can.receive', false);
        $techResponse->assertJsonPath('data.can.resolve', false);
    }

    public function test_resolver_capabilities_in_exception_open_with_maker_checker(): void
    {
        $case = $this->createReturnCase('exception_open', [
            'declared_by' => $this->technician->id,
            'declared_at' => now(),
            'received_by' => $this->receiver->id,
            'received_at' => now(),
        ]);

        // Legitimate resolver
        $this->actingAs($this->resolver);
        $response = $this->getJson(route('admin.inventory.returns.show', $case));
        $response->assertOk();
        $response->assertJsonPath('data.can.declare', false);
        $response->assertJsonPath('data.can.receive', false);
        $response->assertJsonPath('data.can.resolve', true);

        // Maker-checker invariant 1: technician cannot resolve even with resolve permission
        $this->technician->givePermissionTo('inventory.return.resolve');
        $this->actingAs($this->technician);
        $techResponse = $this->getJson(route('admin.inventory.returns.show', $case));
        $techResponse->assertOk();
        $techResponse->assertJsonPath('data.can.resolve', false);

        // Maker-checker invariant 2: receiver (received_by) cannot resolve even with resolve permission
        $this->receiver->givePermissionTo('inventory.return.resolve');
        $this->actingAs($this->receiver);
        $receiverResponse = $this->getJson(route('admin.inventory.returns.show', $case));
        $receiverResponse->assertOk();
        $receiverResponse->assertJsonPath('data.can.resolve', false);
    }

    public function test_unauthorized_user_capabilities_are_all_false(): void
    {
        $case = $this->createReturnCase('awaiting_declaration');

        // User without return permissions
        $request = Request::create('/admin/inventory/returns/'.$case->id, 'GET');
        $request->setUserResolver(fn () => $this->unauthorizedUser);
        $resource = (new MaterialReturnCaseResource($case))->toArray($request);

        $this->assertArrayHasKey('can', $resource);
        $this->assertSame([
            'declare' => false,
            'receive' => false,
            'resolve' => false,
        ], $resource['can']);
    }

    public function test_wrong_state_disallows_capabilities(): void
    {
        // Resolved terminal state
        $resolvedCase = $this->createReturnCase('resolved', [
            'declared_by' => $this->technician->id,
            'received_by' => $this->receiver->id,
            'resolved_by' => $this->resolver->id,
            'resolved_at' => now(),
            'resolution_note' => 'Resolution note',
        ]);

        // Technician cannot declare when already resolved
        $reqTech = Request::create('/admin/inventory/returns/'.$resolvedCase->id, 'GET');
        $reqTech->setUserResolver(fn () => $this->technician);
        $resTech = (new MaterialReturnCaseResource($resolvedCase))->toArray($reqTech);
        $this->assertFalse($resTech['can']['declare']);
        $this->assertFalse($resTech['can']['receive']);
        $this->assertFalse($resTech['can']['resolve']);

        // Receiver cannot receive when already resolved
        $reqReceiver = Request::create('/admin/inventory/returns/'.$resolvedCase->id, 'GET');
        $reqReceiver->setUserResolver(fn () => $this->receiver);
        $resReceiver = (new MaterialReturnCaseResource($resolvedCase))->toArray($reqReceiver);
        $this->assertFalse($resReceiver['can']['receive']);

        // Resolver cannot resolve when already resolved
        $reqResolver = Request::create('/admin/inventory/returns/'.$resolvedCase->id, 'GET');
        $reqResolver->setUserResolver(fn () => $this->resolver);
        $resResolver = (new MaterialReturnCaseResource($resolvedCase))->toArray($reqResolver);
        $this->assertFalse($resResolver['can']['resolve']);

        // In awaiting_declaration: receiver cannot receive, resolver cannot resolve
        $awaitingCase = $this->createReturnCase('awaiting_declaration');
        $resAwaitingRecv = (new MaterialReturnCaseResource($awaitingCase))->toArray($reqReceiver);
        $this->assertFalse($resAwaitingRecv['can']['receive']);
        $resAwaitingResv = (new MaterialReturnCaseResource($awaitingCase))->toArray($reqResolver);
        $this->assertFalse($resAwaitingResv['can']['resolve']);
    }

    public function test_cross_tenant_user_capabilities_are_all_false(): void
    {
        $caseCompanyA = $this->createReturnCase('awaiting_declaration');

        // Create tenant B
        $companyB = Company::factory()->create();
        $techB = User::factory()->create(['company_id' => $companyB->id]);
        $techB->givePermissionTo(['inventory.return.view.own', 'inventory.return.declare']);

        $requestB = Request::create('/admin/inventory/returns/'.$caseCompanyA->id, 'GET');
        $requestB->setUserResolver(fn () => $techB);
        $resource = (new MaterialReturnCaseResource($caseCompanyA))->toArray($requestB);

        $this->assertArrayHasKey('can', $resource);
        $this->assertSame([
            'declare' => false,
            'receive' => false,
            'resolve' => false,
        ], $resource['can']);

        // HTTP access by tenant B is rejected (not found due to BelongsToCompany tenant boundary)
        $this->actingAs($techB);
        $response = $this->getJson(route('admin.inventory.returns.show', $caseCompanyA));
        $response->assertNotFound();
    }

    public function test_index_endpoint_returns_capabilities_per_record(): void
    {
        $case = $this->createReturnCase('awaiting_declaration');

        $this->actingAs($this->receiver);
        $response = $this->getJson(route('admin.inventory.returns.index'));
        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'can' => [
                        'declare',
                        'receive',
                        'resolve',
                    ],
                ],
            ],
        ]);
    }
}
