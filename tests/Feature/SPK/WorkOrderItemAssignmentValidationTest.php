<?php

namespace Tests\Feature\SPK;

use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderItem;
use Modules\SPK\Services\SpkService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\WithoutAppOnlyRedirect;

class WorkOrderItemAssignmentValidationTest extends TestCase
{
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
    }

    public function test_direct_service_assign_requires_expected_assignment_state(): void
    {
        $admin = $this->user('admin');
        $company = $admin->company;
        $workOrder = $this->workOrder($company, $admin, 'generated');
        $technician = $this->user('technician', $company);
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $technician->id,
            'status' => 'active',
        ]);

        $this->actingAs($admin);
        try {
            SpkService::assign($workOrder, $technician->id, $admin->id);
            $this->fail('Missing assignment state must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }

    public function test_direct_service_assign_accepts_explicit_unassigned_state(): void
    {
        $admin = $this->user('admin');
        $company = $admin->company;
        $workOrder = $this->workOrder($company, $admin, 'generated');
        $technician = $this->user('technician', $company);
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $technician->id,
            'status' => 'active',
        ]);

        $this->actingAs($admin);
        SpkService::assign(
            $workOrder,
            $technician->id,
            $admin->id,
            null,
            $workOrder->fresh()->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z')
        );

        $this->assertSame($technician->id, $workOrder->fresh()->assigned_to);
    }

    public function test_direct_service_assign_rejects_stale_assignment_state(): void
    {
        $admin = $this->user('admin');
        $company = $admin->company;
        $workOrder = $this->workOrder($company, $admin, 'generated');
        $technician = $this->user('technician', $company);
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $technician->id,
            'status' => 'active',
        ]);

        $this->actingAs($admin);
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(409);
        SpkService::assign($workOrder, $technician->id, $admin->id, null, now()->subMinute()->utc()->format('Y-m-d\\TH:i:s.v\\Z'));
    }

    public function test_add_item_rejects_network_asset_with_mismatched_product(): void
    {
        $admin = $this->user('admin');
        $company = $admin->company;
        $workOrder = $this->workOrder($company, $admin);
        $product = $this->product($company, 'WO-MATCH');
        $otherProduct = $this->product($company, 'WO-OTHER', $product);
        $wrongAsset = NetworkAssetFactory::new()->create([
            'company_id' => $company->id,
            'product_id' => $otherProduct->id,
            'status' => 'available',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.spk.items.store', $workOrder), [
                'product_id' => $product->public_id,
                'network_asset_id' => $wrongAsset->public_id,
                'quantity_used' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('network_asset_id');
    }

    public function test_service_add_item_rechecks_product_company_without_writing(): void
    {
        $admin = $this->user('admin');
        $company = $admin->company;
        $foreign = Company::factory()->create(['is_active' => true]);
        $workOrder = $this->workOrder($company, $admin);
        $foreignProduct = $this->product($foreign, 'WO-FOREIGN');

        $this->actingAs($admin);

        try {
            SpkService::addItem($workOrder, ['product_id' => $foreignProduct->id, 'quantity_used' => 1]);
            $this->fail('Foreign product must be rejected by SPK service.');
        } catch (ModelNotFoundException|HttpException $e) {
            if ($e instanceof HttpException) {
                $this->assertContains($e->getStatusCode(), [404, 422]);
            }
        }

        $this->assertSame(0, WorkOrderItem::withoutCompany()->where('work_order_id', $workOrder->id)->count());
    }

    public function test_service_add_item_rechecks_network_asset_product_without_writing(): void
    {
        $admin = $this->user('admin');
        $company = $admin->company;
        $workOrder = $this->workOrder($company, $admin);
        $product = $this->product($company, 'WO-SERVICE-MATCH');
        $otherProduct = $this->product($company, 'WO-SERVICE-OTHER', $product);
        $wrongAsset = NetworkAssetFactory::new()->create([
            'company_id' => $company->id,
            'product_id' => $otherProduct->id,
            'status' => 'available',
        ]);

        $this->actingAs($admin);

        try {
            SpkService::addItem($workOrder, [
                'product_id' => $product->id,
                'network_asset_id' => $wrongAsset->id,
                'quantity_used' => 1,
            ]);
            $this->fail('Mismatched network asset must be rejected by SPK service.');
        } catch (ModelNotFoundException|HttpException $e) {
            if ($e instanceof HttpException) {
                $this->assertContains($e->getStatusCode(), [404, 422]);
            }
        }

        $this->assertSame(0, WorkOrderItem::withoutCompany()->where('work_order_id', $workOrder->id)->count());
    }

    public function test_assign_rejects_non_technician_employee(): void
    {
        $admin = $this->user('admin');
        $company = $admin->company;
        $workOrder = $this->workOrder($company, $admin, 'generated');
        $nonTechnician = User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $nonTechnician->id,
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.spk.assign', $workOrder), [
                'technician_id' => $nonTechnician->public_id,
                'expected_assigned_to' => null,
                'expected_updated_at' => $workOrder->fresh()->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('technician_id');
    }

    public function test_assign_rejects_role_only_employee_without_spk_capability(): void
    {
        $admin = $this->user('admin');
        $company = $admin->company;
        $workOrder = $this->workOrder($company, $admin, 'generated');
        $roleOnly = $this->user(null, $company);
        $this->grantRole($roleOnly, $this->companyRole($company, 'role-only-technician', []), $company);
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $roleOnly->id,
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.spk.assign', $workOrder), [
                'technician_id' => $roleOnly->public_id,
                'expected_assigned_to' => null,
                'expected_updated_at' => $workOrder->fresh()->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('technician_id');
    }

    public function test_assign_rejects_active_capable_user_with_malformed_foreign_profile(): void
    {
        $admin = $this->user('admin');
        $company = $admin->company;
        $foreignCompany = Company::factory()->create(['is_active' => true]);
        $workOrder = $this->workOrder($company, $admin, 'generated');
        $malformed = $this->user('admin', $company);
        $malformed->givePermissionTo('spk.start');
        EmployeeProfile::factory()->create([
            'company_id' => $foreignCompany->id,
            'user_id' => $malformed->id,
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.spk.assign', $workOrder), [
                'technician_id' => $malformed->public_id,
                'expected_assigned_to' => null,
                'expected_updated_at' => $workOrder->fresh()->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('technician_id');
    }

    public function test_assign_accepts_active_technician_employee(): void
    {
        $admin = $this->user('admin');
        $company = $admin->company;
        $workOrder = $this->workOrder($company, $admin, 'generated');
        $technician = $this->user('technician', $company);
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $technician->id,
            'status' => 'active',
        ]);

        // Post exactly what the Show page sends, so a resource that drops updated_at fails here.
        $shown = $this->actingAs($admin)->get(route('admin.spk.show', $workOrder))
            ->assertOk()->viewData('page')['props']['workOrder']['data'];

        $this->post(route('admin.spk.assign', $workOrder), [
            'technician_id' => $technician->public_id,
            'expected_assigned_to' => $shown['assigned_to'] ?? null,
            'expected_updated_at' => $shown['updated_at'],
        ])
            ->assertRedirect();

        $this->assertDatabaseHas('work_orders', [
            'id' => $workOrder->id,
            'status' => 'assigned',
            'assigned_to' => $technician->id,
        ]);
    }

    public function test_assign_accepts_active_employee_with_lifecycle_capability(): void
    {
        $admin = $this->user('admin');
        $company = $admin->company;
        $workOrder = $this->workOrder($company, $admin, 'generated');
        $operatorRole = $this->companyRole($company, 'field-operator', ['spk.start', 'spk.submit']);
        $operator = $this->user(null, $company);
        $this->grantRole($operator, $operatorRole, $company);
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $operator->id,
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.spk.assign', $workOrder), [
                'technician_id' => $operator->public_id,
                'expected_assigned_to' => null,
                'expected_updated_at' => $workOrder->fresh()->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
            ])
            ->assertRedirect();

        $this->actingAs($operator)
            ->post(route('admin.spk.start', $workOrder))
            ->assertRedirect();

        $this->assertSame('in_progress', $workOrder->fresh()->status);
    }

    public function test_spk_start_requires_assigned_technician(): void
    {
        $admin = $this->user('admin');
        $workOrder = $this->workOrder($admin->company, $admin, 'generated');

        $this->actingAs($admin)
            ->post(route('admin.spk.start', $workOrder))
            ->assertStatus(403);

        $this->assertSame('generated', $workOrder->fresh()->status);
    }

    public function test_spk_start_rejects_other_assignee(): void
    {
        $admin = $this->user('admin');
        $company = $admin->company;
        $technician = $this->user('technician', $company);
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $technician->id,
            'status' => 'active',
        ]);
        $otherTechnician = $this->user('technician', $company);
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $otherTechnician->id,
            'status' => 'active',
        ]);
        $workOrder = $this->workOrder($company, $admin, 'generated');

        $this->actingAs($admin)
            ->post(route('admin.spk.assign', $workOrder), [
                'technician_id' => $technician->public_id,
                'expected_assigned_to' => null,
                'expected_updated_at' => $workOrder->fresh()->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
            ])
            ->assertRedirect();

        $this->actingAs($otherTechnician)
            ->post(route('admin.spk.start', $workOrder))
            ->assertStatus(403);

        $this->assertSame('assigned', $workOrder->fresh()->status);
    }

    private function user(?string $roleName, ?Company $company = null): User
    {
        $company ??= Company::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        if ($roleName !== null) {
            $this->grantRole($user, $roleName, $company);
        }

        return $user;
    }

    private function workOrder(Company $company, User $creator, string $status = 'draft'): WorkOrder
    {
        return WorkOrder::forceCreate([
            'company_id' => $company->id,
            'code' => 'SPK-WO-VAL-'.fake()->unique()->numberBetween(1, 9999),
            'type' => 'installation',
            'title' => 'Work order validation',
            'created_by' => $creator->id,
            'status' => $status,
            'source' => 'manual',
            'priority' => 'medium',
        ]);
    }

    private function product(Company $company, string $sku, ?Product $reuse = null): Product
    {
        $unit = $reuse?->unit_id
            ? Unit::withoutCompany()->findOrFail($reuse->unit_id)
            : Unit::forceCreate(['company_id' => $company->id, 'name' => 'Piece', 'symbol' => 'pcs']);
        $category = $reuse?->category_id
            ? Category::withoutCompany()->findOrFail($reuse->category_id)
            : Category::forceCreate([
                'company_id' => $company->id,
                'unit_id' => $unit->id,
                'name' => 'WO Category',
                'code' => 'WO-CAT-'.fake()->unique()->numberBetween(1, 9999),
                'is_active' => true,
            ]);

        return Product::forceCreate([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku' => $sku.'-'.fake()->unique()->numberBetween(1, 9999),
            'name' => 'WO Product '.$sku,
            'type' => 'asset',
            'track_stock' => true,
            'sell_price' => 100_000,
            'cost_price' => 50_000,
            'min_stock' => 0,
            'is_active' => true,
        ]);
    }
}
