<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Service\Models\ServicePackage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SubscriptionServerTableExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private Company $otherCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $company = Company::factory()->create(['is_active' => true]);
        $this->otherCompany = Company::factory()->create(['is_active' => true]);
        $this->customer = Customer::factory()->create(['company_id' => $company->id]);
        $this->admin = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');
    }

    private function makeSubscription(string $code, string $status = 'pending'): ServiceSubscription
    {
        $package = ServicePackage::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Package '.$code,
        ]);
        $address = CustomerAddress::factory()->create([
            'company_id' => $this->admin->company_id,
            'customer_id' => $this->customer->id,
            'label' => $code,
        ]);

        return ServiceSubscription::factory()->create([
            'company_id' => $this->admin->company_id,
            'customer_id' => $this->customer->id,
            'service_package_id' => $package->id,
            'installation_address_id' => $address->id,
            'code' => $code,
            'status' => $status,
        ]);
    }

    public function test_export_is_allowed_with_permission_and_forbidden_without_it(): void
    {
        $this->makeSubscription('SUB-AUTHZ');

        $this->actingAs($this->admin)
            ->get(route('admin.customers.subscriptions.export', [$this->customer, 'format' => 'csv']))
            ->assertOk();

        $user = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $role = Role::firstOrCreate(['name' => 'subscription-view-only', 'guard_name' => 'web']);
        $role->syncPermissions(['customer.subscription.view']);
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(route('admin.customers.subscriptions.export', [$this->customer, 'format' => 'csv']))
            ->assertForbidden();
    }

    public function test_index_and_export_reject_another_tenants_customer(): void
    {
        $otherCustomer = Customer::factory()->create(['company_id' => $this->otherCompany->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.customers.subscriptions.index', $otherCustomer))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->get(route('admin.customers.subscriptions.export', [$otherCustomer, 'format' => 'csv']))
            ->assertNotFound();
    }

    public function test_index_and_csv_export_apply_the_same_filters(): void
    {
        $this->makeSubscription('SUB-PARITY-INCLUDED', 'active');
        $this->makeSubscription('SUB-PARITY-STATUS-SKIP', 'pending');
        $this->makeSubscription('SUB-SEARCH-SKIP', 'active');

        $filters = [
            'search' => 'SUB-PARITY',
            'status' => 'active',
            'sort' => 'code',
            'direction' => 'asc',
        ];

        $this->actingAs($this->admin)
            ->get(route('admin.customers.subscriptions.index', [$this->customer] + $filters))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Subscriptions/Index')
                ->has('subscriptions.data', 1)
                ->where('subscriptions.data.0.code', 'SUB-PARITY-INCLUDED')
                ->where('filters.search', 'SUB-PARITY')
                ->where('filters.status', 'active')
            );

        $response = $this->actingAs($this->admin)
            ->get(route('admin.customers.subscriptions.export', [$this->customer, 'format' => 'csv'] + $filters));

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('SUB-PARITY-INCLUDED', $content);
        $this->assertStringNotContainsString('SUB-PARITY-STATUS-SKIP', $content);
        $this->assertStringNotContainsString('SUB-SEARCH-SKIP', $content);
    }
}
