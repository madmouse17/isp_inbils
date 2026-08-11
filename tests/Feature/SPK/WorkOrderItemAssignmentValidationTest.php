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
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkOrderItemAssignmentValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
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
                'product_id' => $product->id,
                'network_asset_id' => $wrongAsset->id,
                'quantity_reserved' => 1,
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
            SpkService::addItem($workOrder, ['product_id' => $foreignProduct->id, 'quantity_reserved' => 1]);
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
                'quantity_reserved' => 1,
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
        $nonTechnician = $this->user('admin', $company);
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $nonTechnician->id,
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.spk.assign', $workOrder), [
                'technician_id' => $nonTechnician->id,
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

        $this->actingAs($admin)
            ->post(route('admin.spk.assign', $workOrder), [
                'technician_id' => $technician->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('work_orders', [
            'id' => $workOrder->id,
            'status' => 'assigned',
            'assigned_to' => $technician->id,
        ]);
    }

    private function user(string $roleName, ?Company $company = null): User
    {
        $user = User::factory()->create([
            'company_id' => ($company ?? Company::factory()->create(['is_active' => true]))->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole(Role::where('name', $roleName)->firstOrFail());

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
            ? Unit::query()->findOrFail($reuse->unit_id)
            : Unit::forceCreate(['company_id' => $company->id, 'name' => 'Piece', 'symbol' => 'pcs']);
        $category = $reuse?->category_id
            ? Category::query()->findOrFail($reuse->category_id)
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
