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
use Modules\Billing\Models\Payment;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Models\Unit;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderItem;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class P0CriticalPathTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
    }

    public function test_registration_is_unavailable_and_admin_login_reaches_dashboard(): void
    {
        $admin = $this->user('admin');

        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Public User',
            'email' => 'public@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->post('/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard', absolute: false));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_admin_customer_subscription_spk_stock_invoice_payment_and_ticket_to_spk_flow(): void
    {
        $admin = $this->user('admin');
        $company = $admin->company;
        $company->update(['settings' => ['spk_auto_invoice' => true]]);
        $location = Location::create([
            'company_id' => $company->id,
            'code' => 'P0-LOC',
            'name' => 'P0 Warehouse',
            'type' => 'warehouse',
            'path' => 'P0 Warehouse',
            'is_active' => true,
        ]);
        $servicePackage = ServicePackageFactory::new()->create(['company_id' => $company->id, 'price_mrc' => 150_000, 'price_otc' => 25_000]);
        $product = $this->product($company, ['sell_price' => 100_000]);
        $asset = NetworkAssetFactory::new()->create(['company_id' => $company->id, 'product_id' => $product->id, 'status' => 'available']);
        Stock::forceCreate(['company_id' => $company->id, 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 10, 'reserved_quantity' => 0]);

        $this->actingAs($admin)
            ->post(route('admin.customers.store'), [
                'code' => 'CUST-P0',
                'name' => 'P0 Customer',
                'type' => 'Individual',
                'email' => 'p0@example.test',
                'password' => 'customer-password',
                'phone' => '0800000000',
                'is_active' => true,
            ])
            ->assertRedirect();

        $customer = Customer::where('code', 'CUST-P0')->firstOrFail();
        $address = CustomerAddress::factory()->create([
            'customer_id' => $customer->id,
            'is_installation_point' => true,
            'is_primary' => true,
        ]);

        $subscription = ServiceSubscription::forceCreate([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'service_package_id' => $servicePackage->id,
            'installation_address_id' => $address->id,
            'code' => 'SUB-P0',
            'status' => 'pending',
            'billing_day' => 5,
            'mrc_amount' => 150_000,
            'otc_installation_fee' => 25_000,
            'contract_months' => 12,
            'serving_pop_id' => $location->id,
        ]);
        $this->post(route('admin.spk.store'), [
            'type' => 'installation',
            'title' => 'Install P0 service',
            'description' => 'Critical path install',
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'location_id' => $location->id,
            'source' => 'subscription',
            'priority' => 'high',
        ])->assertRedirect();

        $workOrder = WorkOrder::where('subscription_id', $subscription->id)->firstOrFail();
        WorkOrderItem::create([
            'company_id' => $company->id,
            'work_order_id' => $workOrder->id,
            'product_id' => $product->id,
            'network_asset_id' => $asset->id,
            'quantity_reserved' => 2,
            'quantity_used' => 2,
        ]);
        $workOrder->addMediaFromString('test')
            ->usingFileName('p0.jpg')
            ->withCustomProperties([
                'company_id' => $company->id,
                'type' => 'photo',
                'uploaded_by' => $admin->id,
            ])
            ->toMediaCollection('evidence', 'public');
        Stock::where('product_id', $product->id)->where('location_id', $location->id)->update(['reserved_quantity' => 2]);

        $technician = $this->user('technician', $company);
        $this->post(route('admin.spk.generate', $workOrder))->assertRedirect();
        $this->post(route('admin.spk.assign', $workOrder), ['technician_id' => $technician->id])->assertRedirect();
        $this->post(route('admin.spk.start', $workOrder))->assertRedirect();
        $this->post(route('admin.spk.submit', $workOrder))->assertRedirect();
        $this->post(route('admin.spk.approve', $workOrder))->assertRedirect();

        $this->assertDatabaseHas('stocks', ['product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 8, 'reserved_quantity' => 0]);
        $this->assertDatabaseHas('network_assets', ['id' => $asset->id, 'status' => 'installed', 'customer_id' => $customer->id, 'subscription_id' => $subscription->id]);
        $this->assertDatabaseHas('service_subscriptions', ['id' => $subscription->id, 'status' => 'active', 'ont_asset_id' => $asset->id]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'movement_type' => 'issue',
            'reference_type' => WorkOrderItem::class,
            'reference_id' => WorkOrderItem::where('work_order_id', $workOrder->id)->firstOrFail()->id,
        ]);

        $invoice = Invoice::where('work_order_id', $workOrder->id)->firstOrFail();
        $this->post(route('admin.invoices.payments.store', $invoice), [
            'amount' => $invoice->total,
            'method' => 'transfer',
            'reference' => 'P0-PAY-001',
            'notes' => 'P0 full payment',
        ])->assertRedirect();

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());

        $category = TicketCategory::create(['company_id' => $company->id, 'name' => 'P0 Support', 'code' => 'P0-SUP', 'default_sla_hours' => 24, 'default_priority' => 'medium', 'is_active' => true]);
        $this->post(route('admin.tickets.store'), [
            'title' => 'P0 ticket',
            'description' => 'Needs field SPK',
            'source' => 'customer',
            'category_id' => $category->id,
            'priority' => 'medium',
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'location_id' => $location->id,
        ])->assertRedirect();

        $ticket = Ticket::where('title', 'P0 ticket')->firstOrFail();
        $this->post(route('admin.tickets.assign', $ticket), ['handler_id' => $admin->id])->assertRedirect();
        $this->post(route('admin.tickets.spawn-spk', $ticket))->assertRedirect();

        $spawnedSpk = $ticket->fresh()->spawnedSpk;
        $this->assertNotNull($spawnedSpk);
        $this->assertSame('ticket', $spawnedSpk->source);
        $this->assertSame('generated', $spawnedSpk->status);
    }

    public function test_admin_customer_subscription_route_creates_subscription_and_rejects_other_company_customer(): void
    {
        $admin = $this->user('admin');
        $company = $admin->company;
        $customer = Customer::factory()->create(['company_id' => $company->id]);
        $address = CustomerAddress::forceCreate([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'label' => 'P0 Route Address',
            'address' => 'P0 Street',
            'city' => 'P0 City',
            'is_installation_point' => true,
        ]);
        $servicePackage = ServicePackageFactory::new()->create(['company_id' => $company->id]);

        $payload = [
            'customer_id' => $customer->id,
            'service_package_id' => $servicePackage->id,
            'installation_address_id' => $address->id,
            'billing_day' => 5,
            'mrc_amount' => 150_000,
            'otc_installation_fee' => 25_000,
            'contract_months' => 12,
        ];

        $this->actingAs($admin)
            ->post(route('admin.customers.subscriptions.store', $customer), $payload)
            ->assertRedirect();

        $this->assertDatabaseHas('service_subscriptions', [
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'service_package_id' => $servicePackage->id,
            'installation_address_id' => $address->id,
            'status' => 'pending',
        ]);

        $sameCompanyOtherCustomer = Customer::factory()->create(['company_id' => $company->id]);
        $sameCompanyOtherAddress = CustomerAddress::forceCreate([
            'company_id' => $company->id,
            'customer_id' => $sameCompanyOtherCustomer->id,
            'label' => 'P0 Other Route Address',
            'address' => 'P0 Other Street',
            'city' => 'P0 Other City',
            'is_installation_point' => true,
        ]);

        $this->post(route('admin.customers.subscriptions.store', $customer), array_merge($payload, [
            'customer_id' => $sameCompanyOtherCustomer->id,
            'installation_address_id' => $sameCompanyOtherAddress->id,
        ]))->assertSessionHasErrors('installation_address_id');

        $this->assertDatabaseMissing('service_subscriptions', [
            'customer_id' => $customer->id,
            'installation_address_id' => $sameCompanyOtherAddress->id,
        ]);

        $otherCustomer = Customer::factory()->create([
            'company_id' => Company::factory()->create(['is_active' => true])->id,
        ]);

        $this->post(route('admin.customers.subscriptions.store', $otherCustomer), $payload)
            ->assertNotFound();

        $this->assertDatabaseMissing('service_subscriptions', [
            'customer_id' => $otherCustomer->id,
        ]);
    }

    public function test_role_permissions_drive_admin_menu_visibility_inputs(): void
    {
        $admin = $this->user('admin');
        $customer = $this->user('customer');

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('auth.user.roles.0', 'admin')
                ->where('auth.user.permissions', fn ($permissions) => collect($permissions)->contains('billing.view') && collect($permissions)->contains('spk.view') && collect($permissions)->contains('ticket.view') && collect($permissions)->contains('inventory.view'))
            );

        $this->actingAs($customer)
            ->get(route('admin.stocks.index'))
            ->assertForbidden();
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

    private function product(Company $company, array $extra = []): Product
    {
        $category = Category::forceCreate(['company_id' => $company->id, 'name' => 'P0 Category', 'code' => 'P0-CAT', 'is_active' => true]);
        $unit = Unit::forceCreate(['company_id' => $company->id, 'name' => 'Piece', 'symbol' => 'pcs']);

        return Product::forceCreate(array_merge([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku' => 'P0-PRD',
            'name' => 'P0 Product',
            'type' => 'asset',
            'track_stock' => true,
            'sell_price' => 100_000,
            'cost_price' => 50_000,
            'min_stock' => 0,
            'is_active' => true,
        ], $extra));
    }
}
