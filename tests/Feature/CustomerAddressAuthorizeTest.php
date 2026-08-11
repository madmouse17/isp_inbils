<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CustomerAddressAuthorizeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        DB::table('indonesia_provinces')->insert(['code' => '31', 'name' => 'DKI JAKARTA']);
        DB::table('indonesia_cities')->insert(['code' => '3171', 'province_code' => '31', 'name' => 'KOTA JAKARTA SELATAN']);
        DB::table('indonesia_districts')->insert(['code' => '3171010', 'city_code' => '3171', 'name' => 'KEBAYORAN BARU']);
        DB::table('indonesia_villages')->insert(['code' => '3171010001', 'district_code' => '3171010', 'name' => 'GANDARIA UTARA']);
    }

    public function test_store_forbids_user_without_customer_address_manage(): void
    {
        [$user, $customer] = $this->makeActorAndCustomer(['customer.view']);

        $response = $this
            ->actingAs($user)
            ->from(route('admin.customers.edit', $customer))
            ->post(route('admin.customers.addresses.store', $customer), $this->validPayload());

        $response->assertForbidden();
        $this->assertDatabaseCount('customer_addresses', 0);
    }

    public function test_store_forbids_user_with_only_legacy_customers_update(): void
    {
        [$user, $customer] = $this->makeActorAndCustomer(['customers.update', 'customer.view']);

        $response = $this
            ->actingAs($user)
            ->from(route('admin.customers.edit', $customer))
            ->post(route('admin.customers.addresses.store', $customer), $this->validPayload());

        $response->assertForbidden();
        $this->assertDatabaseCount('customer_addresses', 0);
    }

    public function test_store_allows_user_with_customer_address_manage(): void
    {
        [$user, $customer] = $this->makeActorAndCustomer(['customer.address.manage', 'customer.view']);

        $response = $this
            ->actingAs($user)
            ->from(route('admin.customers.edit', $customer))
            ->post(route('admin.customers.addresses.store', $customer), $this->validPayload([
                'label' => 'HQ',
                'address' => 'Jl. Merdeka 1',
                'is_primary' => true,
            ]));

        $response->assertRedirect(route('admin.customers.edit', $customer));
        $this->assertDatabaseHas('customer_addresses', [
            'customer_id' => $customer->id,
            'company_id' => $user->company_id,
            'label' => 'HQ',
            'address' => 'Jl. Merdeka 1',
            'is_primary' => 1,
        ]);
    }

    public function test_update_allows_user_with_customer_address_manage(): void
    {
        [$user, $customer] = $this->makeActorAndCustomer(['customer.address.manage', 'customer.view']);
        $address = CustomerAddress::factory()->create([
            'company_id' => $user->company_id,
            'customer_id' => $customer->id,
            'label' => 'Old',
            'address' => 'Old street',
        ]);

        $response = $this
            ->actingAs($user)
            ->from(route('admin.customers.edit', $customer))
            ->put(route('admin.customers.addresses.update', [$customer, $address]), $this->validPayload([
                'label' => 'New',
                'address' => 'New street',
            ]));

        $response->assertRedirect(route('admin.customers.edit', $customer));
        $this->assertDatabaseHas('customer_addresses', [
            'id' => $address->id,
            'label' => 'New',
            'address' => 'New street',
        ]);
    }

    private function makeActorAndCustomer(array $permissions): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $role = Role::create([
            'name' => 'customer-address-auth-'.uniqid(),
            'guard_name' => 'web',
        ]);
        $role->syncPermissions($permissions);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $customer = Customer::factory()->create(['company_id' => $company->id]);

        return [$user->fresh(), $customer];
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'label' => 'Office',
            'address' => 'Jl. Sudirman 10',
            'province_code' => '31',
            'city_code' => '3171',
            'district_code' => '3171010',
            'village_code' => '3171010001',
            'postal_code' => '12190',
            'lat' => -6.2,
            'lng' => 106.8,
            'is_primary' => false,
            'is_installation_point' => false,
            'notes' => null,
        ], $overrides);
    }
}
