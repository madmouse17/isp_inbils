<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\CustomerContact;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Tests\TestCase;

class CustomerPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $company = Company::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');
    }

    public function test_show_and_edit_include_customer_related_tables(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $address = CustomerAddress::withoutCompany()->forceCreate([
            'company_id' => $this->admin->company_id,
            'customer_id' => $customer->id,
            'label' => 'Home',
            'address' => 'Main Street',
            'city' => 'Jakarta',
            'is_installation_point' => true,
            'is_primary' => true,
        ]);
        CustomerContact::withoutCompany()->forceCreate([
            'company_id' => $this->admin->company_id,
            'customer_id' => $customer->id,
            'name' => 'Primary Contact',
            'phone' => '08123456789',
            'is_primary' => true,
        ]);
        $package = ServicePackageFactory::new()->create(['company_id' => $this->admin->company_id]);
        ServiceSubscription::withoutCompany()->forceCreate([
            'company_id' => $this->admin->company_id,
            'customer_id' => $customer->id,
            'service_package_id' => $package->id,
            'installation_address_id' => $address->id,
            'code' => 'SUB-TEST-001',
            'status' => 'active',
            'billing_day' => 10,
            'mrc_amount' => 150000,
            'otc_installation_fee' => 0,
        ]);

        foreach (['admin.customers.show', 'admin.customers.edit'] as $routeName) {
            $this->actingAs($this->admin)->get(route($routeName, $customer))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('customer.data.addresses', 1)
                    ->where('customer.data.addresses.0.label', 'Home')
                    ->has('customer.data.contacts', 1)
                    ->where('customer.data.contacts.0.name', 'Primary Contact')
                    ->has('customer.data.subscriptions', 1)
                    ->where('customer.data.subscriptions.0.code', 'SUB-TEST-001')
                    ->where('customer.data.subscriptions.0.package.name', $package->name));
        }
    }

    public function test_customer_index_filters_by_status(): void
    {
        Customer::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Active Customer',
            'is_active' => true,
        ]);
        Customer::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Inactive Customer',
            'is_active' => false,
        ]);

        $this->actingAs($this->admin)->get(route('admin.customers.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.name', 'Inactive Customer')
                ->where('filters.status', 'inactive'));
    }
}
