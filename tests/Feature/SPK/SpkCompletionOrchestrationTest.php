<?php

namespace Tests\Feature\SPK;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Invoice;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Models\Unit;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrderItem;
use Modules\SPK\Services\SpkService;
use Tests\TestCase;

class SpkCompletionOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_install_spk_completion_consumes_stock_installs_asset_activates_subscription_and_invoices(): void
    {
        [$company, $user, $location, $customer] = $this->scope();
        $company->update(['settings' => ['spk_auto_invoice' => true]]);
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
            'reserved_quantity' => 2,
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
            'quantity_reserved' => 2,
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
        $this->assertGreaterThan(0, (float) Invoice::where('work_order_id', $workOrder->id)->firstOrFail()->total);
    }

    public function test_cancel_releases_reserved_stock(): void
    {
        [$company, $user, $location] = $this->scope();
        $product = $this->product($company);
        Stock::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 5,
            'reserved_quantity' => 3,
        ]);
        $workOrder = WorkOrderFactory::new()->create([
            'company_id' => $company->id,
            'status' => 'assigned',
            'location_id' => $location->id,
            'created_by' => $user->id,
        ]);
        WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $workOrder->id,
            'product_id' => $product->id,
            'quantity_reserved' => 3,
            'quantity_used' => 0,
        ]);

        SpkService::cancel($workOrder, 'customer cancelled');

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('work_order_items', ['work_order_id' => $workOrder->id, 'quantity_reserved' => 0]);
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
            'type' => 'warehouse',
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
