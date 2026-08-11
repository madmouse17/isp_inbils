<?php

namespace Tests\Feature\SPK;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\NetworkAsset\Models\NetworkAssetInstallation;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderItem;
use Modules\SPK\Services\SpkService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * P0-C smoke: serialized asset concurrency and side effects.
 *
 * Covers:
 *  - lockForUpdate prevents race on completion
 *  - duplicate asset assignment is rejected
 *  - multiple serialized assets installed in one SPK
 *  - orphan prevention (asset must be available)
 *  - subscription ONT asset linkage
 */
class SerializedAssetConcurrencySmokeTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function lock_for_update_prevents_concurrent_completion(): void
    {
        [$company, $user, $location, $customer] = $this->scope();
        $product = $this->product($company);
        $subscription = ServiceSubscription::factory()->create(['customer_id' => $customer->id]);
        $asset = NetworkAssetFactory::new()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'status' => 'available',
            'serial_number' => 'SN-RACE-001',
        ]);
        $workOrder = $this->workOrder($company, $user, $location, $customer, $subscription);
        WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $workOrder->id,
            'product_id' => $product->id,
            'network_asset_id' => $asset->id,
        ]);
        $this->evidence($workOrder, $user);

        // Worker 1: complete in a transaction with lock
        DB::transaction(function () use ($workOrder) {
            $locked = WorkOrder::withoutCompany()
                ->lockForUpdate()
                ->findOrFail($workOrder->id);
            if ($locked->status === 'waiting_review') {
                SpkService::approve($locked);
            }
        });

        // Worker 2: tries after commit — status is now 'completed'
        $locked = WorkOrder::withoutCompany()
            ->lockForUpdate()
            ->findOrFail($workOrder->id);

        $this->assertSame('completed', $locked->status, 'Worker 2 should see completed status');

        // Asset installed exactly once
        $this->assertDatabaseHas('network_assets', ['id' => $asset->id, 'status' => 'installed']);
        $this->assertDatabaseCount('network_asset_installations', 1);
    }

    /** @test */
    public function duplicate_asset_assignment_is_rejected(): void
    {
        [$company, $user, $location, $customer] = $this->scope();
        $product = $this->product($company);
        $subscription1 = ServiceSubscription::factory()->create(['customer_id' => $customer->id]);
        $asset = NetworkAssetFactory::new()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'status' => 'available',
            'serial_number' => 'SN-DUP-001',
        ]);

        // First SPK completes and installs the asset
        $wo1 = $this->workOrder($company, $user, $location, $customer, $subscription1);
        WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $wo1->id,
            'product_id' => $product->id,
            'network_asset_id' => $asset->id,
        ]);
        $this->evidence($wo1, $user);
        SpkService::approve($wo1);
        $this->assertSame('installed', $asset->fresh()->status);

        // Second SPK tries to use the same asset
        $subscription2 = ServiceSubscription::factory()->create(['customer_id' => $customer->id]);
        $wo2 = $this->workOrder($company, $user, $location, $customer, $subscription2);
        WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $wo2->id,
            'product_id' => $product->id,
            'network_asset_id' => $asset->id,
        ]);
        $this->evidence($wo2, $user);

        try {
            SpkService::approve($wo2);
            $this->fail('Duplicate asset assignment was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('work_orders', ['id' => $wo2->id, 'status' => 'waiting_review']);
        $this->assertDatabaseCount('network_asset_installations', 1);
    }

    /** @test */
    public function multiple_serialized_assets_can_be_installed_in_one_spk(): void
    {
        [$company, $user, $location, $customer] = $this->scope();
        $product1 = $this->product($company, 'SMK-PRD-M1-');
        $product2 = $this->product($company, 'SMK-PRD-M2-');
        $subscription = ServiceSubscription::factory()->create(['customer_id' => $customer->id]);

        $asset1 = NetworkAssetFactory::new()->create([
            'company_id' => $company->id,
            'product_id' => $product1->id,
            'status' => 'available',
            'serial_number' => 'SN-MULTI-001',
        ]);
        $asset2 = NetworkAssetFactory::new()->create([
            'company_id' => $company->id,
            'product_id' => $product2->id,
            'status' => 'available',
            'serial_number' => 'SN-MULTI-002',
        ]);

        $workOrder = $this->workOrder($company, $user, $location, $customer, $subscription);
        WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $workOrder->id,
            'product_id' => $product1->id,
            'network_asset_id' => $asset1->id,
        ]);
        WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $workOrder->id,
            'product_id' => $product2->id,
            'network_asset_id' => $asset2->id,
        ]);
        $this->evidence($workOrder, $user);

        SpkService::approve($workOrder);

        $this->assertSame('installed', $asset1->fresh()->status);
        $this->assertSame('installed', $asset2->fresh()->status);
        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'completed']);
        $this->assertDatabaseCount('network_asset_installations', 2);

        // First asset should be linked as ONT
        $this->assertEquals($asset1->id, $subscription->fresh()->ont_asset_id);
    }

    /** @test */
    public function asset_must_be_available_to_install(): void
    {
        [$company, $user, $location, $customer] = $this->scope();
        $product = $this->product($company);
        $subscription = ServiceSubscription::factory()->create(['customer_id' => $customer->id]);

        $asset = NetworkAssetFactory::new()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'status' => 'retired',
            'serial_number' => 'SN-ORPHAN-001',
        ]);

        $workOrder = $this->workOrder($company, $user, $location, $customer, $subscription);
        WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $workOrder->id,
            'product_id' => $product->id,
            'network_asset_id' => $asset->id,
        ]);
        $this->evidence($workOrder, $user);

        try {
            SpkService::approve($workOrder);
            $this->fail('Non-available asset was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('network_assets', ['id' => $asset->id, 'status' => 'retired']);
        $this->assertDatabaseMissing('network_asset_installations', ['network_asset_id' => $asset->id]);
    }

    /** @test */
    public function serialized_item_without_network_asset_id_is_rejected(): void
    {
        [$company, $user, $location, $customer] = $this->scope();
        $product = $this->product($company);
        $subscription = ServiceSubscription::factory()->create(['customer_id' => $customer->id]);

        $workOrder = $this->workOrder($company, $user, $location, $customer, $subscription);
        WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $workOrder->id,
            'product_id' => $product->id,
            'network_asset_id' => null,
        ]);
        $this->evidence($workOrder, $user);

        try {
            SpkService::approve($workOrder);
            $this->fail('Serialized item without asset selection was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'waiting_review']);
        $this->assertDatabaseMissing('network_asset_installations', ['spk_id' => $workOrder->id]);
    }

    /** @test */
    public function asset_with_active_installation_is_rejected(): void
    {
        [$company, $user, $location, $customer] = $this->scope();
        $product = $this->product($company);
        $subscription = ServiceSubscription::factory()->create(['customer_id' => $customer->id]);

        $asset = NetworkAssetFactory::new()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'status' => 'available',
            'serial_number' => 'SN-ACTIVE-001',
        ]);

        // Pre-seed an active installation
        NetworkAssetInstallation::create([
            'company_id' => $company->id,
            'network_asset_id' => $asset->id,
            'location_id' => $location->id,
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'spk_id' => 999999,
            'installed_by' => $user->id,
            'installed_at' => now(),
        ]);

        $workOrder = $this->workOrder($company, $user, $location, $customer, $subscription);
        WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $workOrder->id,
            'product_id' => $product->id,
            'network_asset_id' => $asset->id,
        ]);
        $this->evidence($workOrder, $user);

        try {
            SpkService::approve($workOrder);
            $this->fail('Asset with active installation was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'waiting_review']);
        $this->assertDatabaseCount('network_asset_installations', 1);
    }

    // ── Helpers (mirror DeterministicAssetProvisioningTest) ─────────────

    private function scope(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $location = Location::create([
            'company_id' => $company->id,
            'code' => 'LOC-SMK-'.fake()->unique()->numberBetween(1, 9999),
            'name' => 'SMK Test Location',
            'type' => 'warehouse',
            'path' => 'SMK Test Location',
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);

        return [$company, $user, $location, $customer];
    }

    private function product(Company $company, string $prefix = 'SMK-PRD-'): Product
    {
        $uid = fake()->unique()->numberBetween(1, 9999);
        $unit = Unit::create([
            'company_id' => $company->id,
            'name' => 'Piece-'.$uid,
            'symbol' => 'pcs-'.$uid,
        ]);
        $category = Category::create([
            'company_id' => $company->id,
            'unit_id' => $unit->id,
            'name' => 'SMK Test Category-'.$uid,
            'code' => 'SMK-CAT-'.$uid,
            'is_active' => true,
        ]);

        return Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku' => $prefix.$uid,
            'name' => 'SMK Test Product',
            'type' => 'asset',
            'track_stock' => true,
            'sell_price' => 100_000,
            'cost_price' => 50_000,
            'min_stock' => 0,
            'is_active' => true,
        ]);
    }

    private function workOrder(Company $company, User $user, Location $location, Customer $customer, ServiceSubscription $subscription): WorkOrder
    {
        return WorkOrderFactory::new()->create([
            'company_id' => $company->id,
            'type' => 'installation',
            'status' => 'waiting_review',
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'location_id' => $location->id,
            'created_by' => $user->id,
        ]);
    }

    private function evidence(WorkOrder $workOrder, User $user): void
    {
        $workOrder->addMediaFromString('test')
            ->usingFileName('test.jpg')
            ->withCustomProperties([
                'company_id' => $workOrder->company_id,
                'type' => 'photo',
                'uploaded_by' => $user->id,
            ])
            ->toMediaCollection('evidence', 'public');
    }
}
