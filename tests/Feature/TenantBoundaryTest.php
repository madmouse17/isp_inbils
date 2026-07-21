<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Invoice;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Tests\TestCase;

class TenantBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $otherCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $company = Company::factory()->create(['is_active' => true]);
        $this->otherCompany = Company::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');
    }

    public function test_customer_detail_hides_other_company_customer(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->otherCompany->id]);

        $this->actingAs($this->admin)->get(route('admin.customers.show', $customer))->assertNotFound();
    }

    public function test_inventory_detail_hides_other_company_product(): void
    {
        $unit = Unit::withoutCompany()->forceCreate([
            'company_id' => $this->otherCompany->id,
            'name' => 'Other Unit',
            'symbol' => 'OU',
        ]);
        $product = Product::withoutCompany()->forceCreate([
            'company_id' => $this->otherCompany->id,
            'category_id' => Category::withoutCompany()->forceCreate([
                'company_id' => $this->otherCompany->id,
                'unit_id' => $unit->id,
                'name' => 'Other Category',
                'code' => 'OTHER-CAT',
                'is_active' => true,
            ])->id,
            'unit_id' => $unit->id,
            'sku' => 'OTHER-SKU',
            'name' => 'Other Product',
            'type' => 'consumable',
            'track_stock' => true,
            'sell_price' => 1000,
            'cost_price' => 500,
            'min_stock' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)->get(route('admin.products.show', $product))->assertNotFound();
    }

    public function test_billing_detail_hides_other_company_invoice(): void
    {
        $invoice = Invoice::factory()->create(['company_id' => $this->otherCompany->id]);

        $this->actingAs($this->admin)->get(route('admin.invoices.show', $invoice))->assertNotFound();
    }

    public function test_spk_detail_hides_other_company_work_order(): void
    {
        $workOrder = WorkOrder::withoutCompany()->forceCreate([
            'company_id' => $this->otherCompany->id,
            'code' => 'SPK-XTENANT',
            'type' => 'installation',
            'title' => 'Other work order',
            'status' => 'draft',
            'source' => 'manual',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->get(route('admin.spk.show', $workOrder))->assertNotFound();
    }

    public function test_ticket_detail_hides_other_company_ticket(): void
    {
        $category = TicketCategory::withoutCompany()->forceCreate([
            'company_id' => $this->otherCompany->id,
            'unit_id' => Unit::withoutCompany()->forceCreate([
                'company_id' => $this->otherCompany->id,
                'name' => 'Auto Unit '.fake()->unique()->numberBetween(1, 9999),
                'symbol' => 'AU'.fake()->unique()->numberBetween(1, 9999),
            ])->id,
            'name' => 'Other Support',
            'code' => 'OTHER',
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);
        $ticket = Ticket::withoutCompany()->forceCreate([
            'company_id' => $this->otherCompany->id,
            'code' => 'TKT-XTENANT',
            'title' => 'Other ticket',
            'description' => 'Other company ticket',
            'source' => 'manual',
            'category_id' => $category->id,
            'status' => 'open',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->get(route('admin.tickets.show', $ticket))->assertNotFound();
    }

    public function test_product_store_rejects_other_company_category_and_unit(): void
    {
        $unit = Unit::withoutCompany()->forceCreate([
            'company_id' => $this->otherCompany->id,
            'name' => 'Other Piece',
            'symbol' => 'OPC',
        ]);
        $category = Category::withoutCompany()->forceCreate([
            'company_id' => $this->otherCompany->id,
            'unit_id' => $unit->id,
            'name' => 'Other Product Category',
            'code' => 'OTHER-PROD-CAT',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)->post(route('admin.products.store'), [
            'sku' => 'XTENANT-PROD',
            'name' => 'Cross tenant product',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'type' => 'consumable',
            'track_stock' => true,
            'is_active' => true,
        ])->assertSessionHasErrors(['category_id']);

        $this->assertDatabaseMissing('products', [
            'sku' => 'XTENANT-PROD',
        ]);
    }

    public function test_subscription_store_rejects_other_company_package_address_and_pop(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $otherCustomer = Customer::factory()->create(['company_id' => $this->otherCompany->id]);
        $package = ServicePackageFactory::new()->create(['company_id' => $this->otherCompany->id]);
        $address = CustomerAddress::withoutCompany()->forceCreate([
            'company_id' => $this->otherCompany->id,
            'customer_id' => $otherCustomer->id,
            'label' => 'Other Address',
            'address' => 'Other Street',
            'city' => 'Other City',
            'is_installation_point' => true,
        ]);
        $pop = $this->location($this->otherCompany, 'OTHER-SUB-POP');

        $this->actingAs($this->admin)->post(route('admin.customers.subscriptions.store', $customer), [
            'service_package_id' => $package->id,
            'installation_address_id' => $address->id,
            'billing_day' => 5,
            'serving_pop_id' => $pop->id,
        ])->assertSessionHasErrors(['service_package_id', 'installation_address_id', 'serving_pop_id']);

        $this->assertDatabaseMissing('service_subscriptions', [
            'customer_id' => $customer->id,
            'service_package_id' => $package->id,
            'installation_address_id' => $address->id,
            'serving_pop_id' => $pop->id,
        ]);
    }

    public function test_ticket_store_rejects_other_company_references(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->otherCompany->id]);
        $package = ServicePackageFactory::new()->create(['company_id' => $this->otherCompany->id]);
        $address = CustomerAddress::withoutCompany()->forceCreate([
            'company_id' => $this->otherCompany->id,
            'customer_id' => $customer->id,
            'label' => 'Other Ticket Address',
            'address' => 'Other Street',
            'city' => 'Other City',
        ]);
        $subscription = ServiceSubscription::withoutCompany()->forceCreate([
            'company_id' => $this->otherCompany->id,
            'customer_id' => $customer->id,
            'service_package_id' => $package->id,
            'installation_address_id' => $address->id,
            'code' => 'OTHER-SUB-TICKET',
            'status' => 'active',
            'billing_day' => 5,
            'mrc_amount' => 100_000,
            'otc_installation_fee' => 0,
        ]);
        $category = TicketCategory::withoutCompany()->forceCreate([
            'company_id' => $this->otherCompany->id,
            'unit_id' => Unit::withoutCompany()->forceCreate([
                'company_id' => $this->otherCompany->id,
                'name' => 'Auto Unit '.fake()->unique()->numberBetween(1, 9999),
                'symbol' => 'AU'.fake()->unique()->numberBetween(1, 9999),
            ])->id,
            'name' => 'Other Ticket Category',
            'code' => 'OTHER-TICKET-CAT',
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);
        $asset = NetworkAssetFactory::new()->create(['company_id' => $this->otherCompany->id]);
        $location = $this->location($this->otherCompany, 'OTHER-TICKET-LOC');

        $this->actingAs($this->admin)->post(route('admin.tickets.store'), [
            'title' => 'Cross tenant ticket',
            'description' => 'Wrong references',
            'source' => 'customer',
            'category_id' => $category->id,
            'priority' => 'medium',
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'network_asset_id' => $asset->id,
            'location_id' => $location->id,
        ])->assertSessionHasErrors(['category_id', 'customer_id', 'subscription_id', 'network_asset_id', 'location_id']);

        $this->assertDatabaseMissing('tickets', ['title' => 'Cross tenant ticket']);
    }

    public function test_stock_receive_rejects_other_company_product_and_location(): void
    {
        $unit = Unit::withoutCompany()->forceCreate([
            'company_id' => $this->otherCompany->id,
            'name' => 'Other Stock Unit',
            'symbol' => 'OSU',
        ]);
        $category = Category::withoutCompany()->forceCreate([
            'company_id' => $this->otherCompany->id,
            'unit_id' => $unit->id,
            'name' => 'Other Stock Category',
            'code' => 'OTHER-STOCK-CAT',
            'is_active' => true,
        ]);
        $product = Product::withoutCompany()->forceCreate([
            'company_id' => $this->otherCompany->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku' => 'OTHER-STOCK-PROD',
            'name' => 'Other Stock Product',
            'type' => 'consumable',
            'track_stock' => true,
            'sell_price' => 1000,
            'cost_price' => 500,
            'min_stock' => 0,
            'is_active' => true,
        ]);
        $location = $this->location($this->otherCompany, 'OTHER-STOCK-LOC');

        $this->actingAs($this->admin)->post(route('admin.stocks.receive'), [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 1,
        ])->assertSessionHasErrors(['product_id', 'location_id']);

        $this->assertDatabaseMissing('stocks', [
            'product_id' => $product->id,
            'location_id' => $location->id,
        ]);
    }

    private function location(Company $company, string $code): Location
    {
        return Location::withoutCompany()->forceCreate([
            'company_id' => $company->id,
            'code' => $code,
            'name' => $code,
            'type' => 'warehouse',
            'path' => $code,
            'is_active' => true,
        ]);
    }
}
