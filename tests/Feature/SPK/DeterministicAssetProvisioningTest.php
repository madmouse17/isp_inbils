<?php

namespace Tests\Feature\SPK;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrderItem;
use Modules\SPK\Services\SpkService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DeterministicAssetProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_installation_uses_explicitly_selected_asset_not_first_matching_asset(): void
    {
        [$company, $user, $location, $customer] = $this->scope();
        $product = $this->product($company);
        $subscription = ServiceSubscription::factory()->create(['customer_id' => $customer->id]);
        $firstAsset = NetworkAssetFactory::new()->create(['company_id' => $company->id, 'product_id' => $product->id, 'status' => 'available']);
        $selectedAsset = NetworkAssetFactory::new()->create(['company_id' => $company->id, 'product_id' => $product->id, 'status' => 'available']);
        $workOrder = $this->workOrder($company, $user, $location, $customer, $subscription);
        WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $workOrder->id,
            'product_id' => $product->id,
            'network_asset_id' => $selectedAsset->id,
        ]);
        $this->evidence($workOrder, $user);

        SpkService::approve($workOrder);

        $this->assertDatabaseHas('network_assets', ['id' => $selectedAsset->id, 'status' => 'installed', 'subscription_id' => $subscription->id]);
        $this->assertDatabaseHas('network_assets', ['id' => $firstAsset->id, 'status' => 'available']);
        $this->assertDatabaseHas('network_asset_installations', ['network_asset_id' => $selectedAsset->id, 'spk_id' => $workOrder->id, 'subscription_id' => $subscription->id]);
    }

    public function test_installation_fails_closed_without_explicit_asset_selection(): void
    {
        [$company, $user, $location, $customer] = $this->scope();
        $product = $this->product($company);
        $subscription = ServiceSubscription::factory()->create(['customer_id' => $customer->id]);
        $workOrder = $this->workOrder($company, $user, $location, $customer, $subscription);
        WorkOrderItem::create(['company_id' => $company->id, 'work_order_id' => $workOrder->id, 'product_id' => $product->id]);
        $this->evidence($workOrder, $user);

        $this->expectException(HttpException::class);
        SpkService::approve($workOrder);
    }

    public function test_installation_rejects_item_product_from_another_company(): void
    {
        [$company, $user, $location, $customer] = $this->scope();
        $foreignCompany = Company::factory()->create();
        $foreignProduct = Product::withoutCompany()->forceCreate([
            'company_id' => $foreignCompany->id,
            'category_id' => Category::withoutCompany()->forceCreate(['company_id' => $foreignCompany->id, 'name' => 'SPK Foreign Category', 'code' => 'SPK-FCAT-'.fake()->unique()->numberBetween(1, 9999), 'is_active' => true])->id,
            'unit_id' => Unit::withoutCompany()->forceCreate(['company_id' => $foreignCompany->id, 'name' => 'Foreign Piece', 'symbol' => 'fpcs'])->id,
            'sku' => 'SPK-FPRD-'.fake()->unique()->numberBetween(1, 9999),
            'name' => 'SPK Foreign Product',
            'type' => 'asset',
            'track_stock' => true,
            'sell_price' => 100_000,
            'cost_price' => 50_000,
            'min_stock' => 0,
            'is_active' => true,
        ]);
        $this->assertNotSame($company->id, $foreignProduct->company_id);
        $subscription = ServiceSubscription::factory()->create(['customer_id' => $customer->id]);
        $asset = NetworkAssetFactory::new()->create(['company_id' => $company->id, 'product_id' => $foreignProduct->id, 'status' => 'available']);
        $workOrder = $this->workOrder($company, $user, $location, $customer, $subscription);
        WorkOrderItem::create(['company_id' => $company->id, 'work_order_id' => $workOrder->id, 'product_id' => $foreignProduct->id, 'network_asset_id' => $asset->id]);
        $this->evidence($workOrder, $user);

        try {
            SpkService::approve($workOrder);
            $this->fail('Foreign-company SPK item product was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'waiting_review']);
        $this->assertDatabaseHas('network_assets', ['id' => $asset->id, 'status' => 'available', 'customer_id' => null, 'subscription_id' => null]);
    }

    public function test_termination_releases_ont_only_when_requested(): void
    {
        [$company, , $location, $customer] = $this->scope();
        $subscription = ServiceSubscription::factory()->create(['customer_id' => $customer->id, 'status' => 'active']);
        $asset = NetworkAssetFactory::new()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'location_id' => $location->id,
            'status' => 'installed',
        ]);
        $subscription->update(['ont_asset_id' => $asset->id]);

        SubscriptionService::terminate($subscription, 'customer cancelled', true);

        $this->assertDatabaseHas('service_subscriptions', ['id' => $subscription->id, 'status' => 'terminated', 'ont_asset_id' => null]);
        $this->assertDatabaseHas('network_assets', ['id' => $asset->id, 'status' => 'available', 'customer_id' => null, 'subscription_id' => null]);
    }

    private function scope(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $location = Location::create(['company_id' => $company->id, 'code' => 'LOC-SPK-'.fake()->unique()->numberBetween(1, 9999), 'name' => 'SPK Test Location', 'type' => 'warehouse', 'path' => 'SPK Test Location', 'is_active' => true]);
        $customer = Customer::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);

        return [$company, $user, $location, $customer];
    }

    private function product(Company $company): Product
    {
        $category = Category::create(['company_id' => $company->id, 'name' => 'SPK Test Category', 'code' => 'SPK-CAT-'.fake()->unique()->numberBetween(1, 9999), 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Piece', 'symbol' => 'pcs']);

        return Product::create(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'sku' => 'SPK-PRD-'.fake()->unique()->numberBetween(1, 9999), 'name' => 'SPK Test Product', 'type' => 'asset', 'track_stock' => true, 'sell_price' => 100_000, 'cost_price' => 50_000, 'min_stock' => 0, 'is_active' => true]);
    }

    private function workOrder(Company $company, User $user, Location $location, Customer $customer, ServiceSubscription $subscription)
    {
        return WorkOrderFactory::new()->create(['company_id' => $company->id, 'type' => 'installation', 'status' => 'waiting_review', 'customer_id' => $customer->id, 'subscription_id' => $subscription->id, 'location_id' => $location->id, 'created_by' => $user->id]);
    }

    private function evidence($workOrder, User $user): void
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
