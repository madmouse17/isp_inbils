<?php

namespace Tests\Feature\TenantIsolation;

use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use App\Services\Core\SubscriptionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Services\StockService;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Services\TicketService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class TenantSafeWriteReferenceTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    public function test_subscription_service_rejects_other_company_customer(): void
    {
        $user = $this->createCompanyUser();
        $other = $this->createCompanyUser();
        $this->actingAs($user);

        $otherCustomer = Customer::factory()->create(['company_id' => $other->company_id]);
        $package = ServicePackageFactory::new()->create(['company_id' => $user->company_id]);
        $address = CustomerAddress::factory()->create([
            'company_id' => $user->company_id,
            'customer_id' => Customer::factory()->create(['company_id' => $user->company_id])->id,
        ]);

        try {
            SubscriptionService::create([
                'customer_id' => $otherCustomer->id,
                'service_package_id' => $package->id,
                'installation_address_id' => $address->id,
                'billing_day' => 5,
            ]);
            $this->fail('Cross-company customer must be rejected.');
        } catch (ModelNotFoundException|HttpException $e) {
            if ($e instanceof HttpException) {
                $this->assertContains($e->getStatusCode(), [403, 404, 422]);
            } else {
                $this->assertSame(Customer::class, $e->getModel());
            }
        }

        $this->assertSame(0, ServiceSubscription::withoutCompany()->where('customer_id', $otherCustomer->id)->count());
    }

    public function test_subscription_service_rejects_address_belonging_to_other_customer(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);

        $customer = Customer::factory()->create(['company_id' => $user->company_id]);
        $otherCustomer = Customer::factory()->create(['company_id' => $user->company_id]);
        $package = ServicePackageFactory::new()->create(['company_id' => $user->company_id]);
        $foreignAddress = CustomerAddress::factory()->create([
            'company_id' => $user->company_id,
            'customer_id' => $otherCustomer->id,
        ]);

        try {
            SubscriptionService::create([
                'customer_id' => $customer->id,
                'service_package_id' => $package->id,
                'installation_address_id' => $foreignAddress->id,
                'billing_day' => 5,
            ]);
            $this->fail('Wrong-parent address must be rejected.');
        } catch (ModelNotFoundException|HttpException $e) {
            if ($e instanceof HttpException) {
                $this->assertContains($e->getStatusCode(), [403, 404, 422]);
            } else {
                $this->assertSame(CustomerAddress::class, $e->getModel());
            }
        }

        $this->assertSame(0, ServiceSubscription::withoutCompany()->where('customer_id', $customer->id)->count());
    }

    public function test_subscription_service_fails_closed_without_company_context(): void
    {
        $user = $this->createCompanyUser();
        $customer = Customer::factory()->create(['company_id' => $user->company_id]);
        $package = ServicePackageFactory::new()->create(['company_id' => $user->company_id]);
        $address = CustomerAddress::factory()->create([
            'company_id' => $user->company_id,
            'customer_id' => $customer->id,
        ]);

        CompanyService::resetCache();
        auth()->logout();

        try {
            SubscriptionService::create([
                'customer_id' => $customer->id,
                'service_package_id' => $package->id,
                'installation_address_id' => $address->id,
                'billing_day' => 5,
            ]);
            $this->fail('Missing company context must fail closed.');
        } catch (HttpException $e) {
            $this->assertContains($e->getStatusCode(), [403, 404, 422]);
        }

        $this->assertSame(0, ServiceSubscription::withoutCompany()->where('customer_id', $customer->id)->count());
    }

    public function test_stock_service_adjust_rejects_other_company_product_and_location(): void
    {
        $user = $this->createCompanyUser();
        $other = $this->createCompanyUser();
        $this->actingAs($user);

        [$product, $location] = $this->otherCompanyProductAndLocation($other->company_id);

        try {
            StockService::adjust($product->id, $location->id, 5, 'cross-tenant probe');
            $this->fail('Cross-company stock adjust must be rejected.');
        } catch (HttpException $e) {
            $this->assertContains($e->getStatusCode(), [403, 404, 422]);
        }

        $this->assertSame(0, StockMovement::withoutCompany()->where('product_id', $product->id)->count());
    }

    public function test_stock_service_receive_rejects_other_company_product(): void
    {
        $user = $this->createCompanyUser();
        $other = $this->createCompanyUser();
        $this->actingAs($user);

        [$product, $location] = $this->otherCompanyProductAndLocation($other->company_id);

        try {
            StockService::receive($product->id, $location->id, 1, 'cross-tenant receive');
            $this->fail('Cross-company stock receive must be rejected.');
        } catch (HttpException $e) {
            $this->assertContains($e->getStatusCode(), [403, 404, 422]);
        }

        $this->assertSame(0, StockMovement::withoutCompany()->where('product_id', $product->id)->count());
    }

    public function test_stock_service_fails_closed_without_company_context(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);

        $unit = Unit::query()->create(['name' => 'Piece', 'symbol' => 'PCS-TS']);
        $category = Category::query()->create([
            'name' => 'Tenant Safe Cat',
            'code' => 'TS-CAT',
            'unit_id' => $unit->id,
        ]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku' => 'TS-PROD-1',
            'name' => 'Tenant Safe Product',
            'type' => 'consumable',
            'track_stock' => true,
            'min_stock' => 0,
            'is_active' => true,
        ]);
        $location = Location::query()->create([
            'code' => 'TS-LOC-1',
            'name' => 'TS Loc',
            'type' => 'site',
            'is_active' => true,
        ]);

        CompanyService::resetCache();
        auth()->logout();

        try {
            StockService::adjust($product->id, $location->id, 1, 'no company');
            $this->fail('Missing company context must fail closed on stock adjust.');
        } catch (HttpException $e) {
            $this->assertContains($e->getStatusCode(), [403, 404, 422]);
        }

        $this->assertSame(0, StockMovement::withoutCompany()->where('product_id', $product->id)->count());
    }

    public function test_ticket_service_rechecks_wrong_subscription_parent_without_writing(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);

        $customer = Customer::factory()->create(['company_id' => $user->company_id]);
        $otherCustomer = Customer::factory()->create(['company_id' => $user->company_id]);
        $package = ServicePackageFactory::new()->create(['company_id' => $user->company_id]);
        $address = CustomerAddress::factory()->create([
            'company_id' => $user->company_id,
            'customer_id' => $otherCustomer->id,
        ]);
        $subscription = ServiceSubscription::factory()->create([
            'company_id' => $user->company_id,
            'customer_id' => $otherCustomer->id,
            'service_package_id' => $package->id,
            'installation_address_id' => $address->id,
            'status' => 'active',
            'billing_day' => 5,
            'mrc_amount' => 100_000,
        ]);
        $category = TicketCategory::query()->create([
            'name' => 'Service recheck',
            'code' => 'SRV-TS',
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        try {
            TicketService::create([
                'title' => 'Wrong parent service ticket',
                'description' => 'Subscription belongs to other customer',
                'source' => 'customer',
                'category_id' => $category->id,
                'priority' => 'medium',
                'customer_id' => $customer->id,
                'subscription_id' => $subscription->id,
            ], $user->id);
            $this->fail('Wrong-parent subscription must be rejected inside service transaction.');
        } catch (HttpException $e) {
            $this->assertContains($e->getStatusCode(), [403, 404, 422]);
        }

        $this->assertSame(0, Ticket::withoutCompany()->where('title', 'Wrong parent service ticket')->count());
    }

    public function test_subscription_store_rejects_address_for_wrong_customer(): void
    {
        $user = $this->createCompanyUser();
        $user->givePermissionTo(Permission::findOrCreate('subscription.create', 'web'));
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->actingAs($user->fresh());

        $customer = Customer::factory()->create(['company_id' => $user->company_id]);
        $otherCustomer = Customer::factory()->create(['company_id' => $user->company_id]);
        $package = ServicePackageFactory::new()->create(['company_id' => $user->company_id]);
        $foreignAddress = CustomerAddress::factory()->create([
            'company_id' => $user->company_id,
            'customer_id' => $otherCustomer->id,
        ]);

        $this->post(route('admin.customers.subscriptions.store', $customer), [
            'service_package_id' => $package->id,
            'installation_address_id' => $foreignAddress->id,
            'billing_day' => 5,
        ])->assertSessionHasErrors(['installation_address_id']);

        $this->assertDatabaseMissing('service_subscriptions', [
            'customer_id' => $customer->id,
            'installation_address_id' => $foreignAddress->id,
        ]);
    }

    public function test_ticket_store_rejects_subscription_for_wrong_customer(): void
    {
        $user = $this->createCompanyUser();
        $user->givePermissionTo(Permission::findOrCreate('ticket.create', 'web'));
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->actingAs($user->fresh());

        $customer = Customer::factory()->create(['company_id' => $user->company_id]);
        $otherCustomer = Customer::factory()->create(['company_id' => $user->company_id]);
        $package = ServicePackageFactory::new()->create(['company_id' => $user->company_id]);
        $address = CustomerAddress::factory()->create([
            'company_id' => $user->company_id,
            'customer_id' => $otherCustomer->id,
        ]);
        $subscription = ServiceSubscription::factory()->create([
            'company_id' => $user->company_id,
            'customer_id' => $otherCustomer->id,
            'service_package_id' => $package->id,
            'installation_address_id' => $address->id,
            'status' => 'active',
            'billing_day' => 5,
            'mrc_amount' => 100_000,
        ]);
        $category = TicketCategory::query()->create([
            'name' => 'Support',
            'code' => 'SUP-TS',
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        $this->post(route('admin.tickets.store'), [
            'title' => 'Wrong parent ticket',
            'description' => 'Subscription belongs to other customer',
            'source' => 'customer',
            'category_id' => $category->id,
            'priority' => 'medium',
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
        ])->assertSessionHasErrors(['subscription_id']);

        $this->assertDatabaseMissing('tickets', ['title' => 'Wrong parent ticket']);
        $this->assertSame(0, Ticket::withoutCompany()->where('title', 'Wrong parent ticket')->count());
    }

    /**
     * @return array{0: Product, 1: Location}
     */
    private function otherCompanyProductAndLocation(int $companyId): array
    {
        $unit = Unit::withoutCompany()->forceCreate([
            'company_id' => $companyId,
            'name' => 'Other Piece',
            'symbol' => 'OP'.fake()->unique()->numberBetween(1, 9999),
        ]);
        $category = Category::withoutCompany()->forceCreate([
            'company_id' => $companyId,
            'unit_id' => $unit->id,
            'name' => 'Other Cat',
            'code' => 'OC'.fake()->unique()->numberBetween(1, 9999),
            'is_active' => true,
        ]);
        $product = Product::withoutCompany()->forceCreate([
            'company_id' => $companyId,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku' => 'OX-'.fake()->unique()->bothify('####'),
            'name' => 'Other Product',
            'type' => 'consumable',
            'track_stock' => true,
            'sell_price' => 1000,
            'cost_price' => 500,
            'min_stock' => 0,
            'is_active' => true,
        ]);
        $location = Location::withoutCompany()->forceCreate([
            'company_id' => $companyId,
            'code' => 'OX-LOC-'.fake()->unique()->numberBetween(1, 9999),
            'name' => 'Other Loc',
            'type' => 'site',
            'is_active' => true,
        ]);

        return [$product, $location];
    }
}
