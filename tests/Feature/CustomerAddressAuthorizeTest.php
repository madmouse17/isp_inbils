<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
                'address_line' => 'Jl. Merdeka 1',
                'is_primary' => true,
            ]));

        if ($response->status() !== 302 || $response->headers->get('Location') === null) {
            fwrite(STDERR, 'ALLOW_STORE_DEBUG status='.$response->status().' loc='.($response->headers->get('Location') ?? 'null').' session='.json_encode($response->getSession()?->all())."\n");
        }

        $response->assertRedirect(route('admin.customers.edit', $customer));
        $this->assertDatabaseHas('customer_addresses', [
            'customer_id' => $customer->id,
            'company_id' => $user->company_id,
            'label' => 'HQ',
            'address_line' => 'Jl. Merdeka 1',
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
            'address_line' => 'Old street',
        ]);

        $response = $this
            ->actingAs($user)
            ->from(route('admin.customers.edit', $customer))
            ->put(route('admin.customers.addresses.update', [$customer, $address]), $this->validPayload([
                'label' => 'New',
                'address_line' => 'New street',
            ]));

        $response->assertRedirect(route('admin.customers.edit', $customer));
        $this->assertDatabaseHas('customer_addresses', [
            'id' => $address->id,
            'label' => 'New',
            'address_line' => 'New street',
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
            'address_line' => 'Jl. Sudirman 10',
            'city' => 'Jakarta',
            'province' => 'DKI',
            'postal_code' => '12190',
            'country' => 'ID',
            'lat' => -6.2,
            'lng' => 106.8,
            'is_primary' => false,
            'is_billing' => false,
            'is_shipping' => true,
            'notes' => null,
        ], $overrides);
    }
}
