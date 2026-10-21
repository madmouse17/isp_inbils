<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Service\Database\Factories\ServicePackageFactory;
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

    public function test_store_rejects_cross_tenant_customer_without_writing_rows(): void
    {
        [$user] = $this->makeActorAndCustomer(['customer.address.manage', 'customer.view']);
        $foreignCompany = Company::factory()->create();
        $foreignCustomer = Customer::withoutCompany()->forceCreate([
            'company_id' => $foreignCompany->id,
            'code' => 'CUS-FRGN-ADDR-01',
            'name' => 'Foreign Address Customer',
            'type' => 'Individual',
        ]);

        $this
            ->actingAs($user)
            ->post(route('admin.customers.addresses.store', $foreignCustomer), $this->validPayload())
            ->assertNotFound();

        $this->assertDatabaseCount('customer_addresses', 0);
    }

    public function test_update_rejects_foreign_child_of_same_company_without_mutating_it(): void
    {
        [$user, $customer] = $this->makeActorAndCustomer(['customer.address.manage', 'customer.view']);
        $otherCustomer = Customer::factory()->create(['company_id' => $user->company_id]);
        $foreignChild = CustomerAddress::withoutCompany()->forceCreate([
            'company_id' => $user->company_id,
            'customer_id' => $otherCustomer->id,
            'label' => 'Untouched',
            'address' => 'Original street',
            'is_primary' => true,
        ]);

        $this
            ->actingAs($user)
            ->put(route('admin.customers.addresses.update', [$customer, $foreignChild]), $this->validPayload([
                'label' => 'Hacked',
                'address' => 'Hacked street',
            ]))
            ->assertNotFound();

        $fresh = $foreignChild->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('Untouched', $fresh->label);
        $this->assertTrue((bool) $fresh->is_primary);
    }

    public function test_destroy_blocks_address_referenced_by_subscription(): void
    {
        [$user, $customer] = $this->makeActorAndCustomer(['customer.address.manage', 'customer.view']);
        $address = CustomerAddress::factory()->create([
            'company_id' => $user->company_id,
            'customer_id' => $customer->id,
            'is_installation_point' => true,
            'is_primary' => true,
        ]);
        $package = ServicePackageFactory::new()->create(['company_id' => $user->company_id]);
        ServiceSubscription::withoutCompany()->forceCreate([
            'company_id' => $user->company_id,
            'customer_id' => $customer->id,
            'service_package_id' => $package->id,
            'installation_address_id' => $address->id,
            'code' => 'SUB-ADDR-GUARD-01',
            'status' => 'active',
            'billing_day' => 10,
            'mrc_amount' => 150000,
            'otc_installation_fee' => 0,
        ]);

        $this
            ->actingAs($user)
            ->delete(route('admin.customers.addresses.destroy', [$customer, $address]))
            ->assertSessionHasErrors(['lifecycle' => 'Alamat masih dipakai 1 langganan, jadi tidak bisa dihapus.']);

        $this->assertDatabaseHas('customer_addresses', ['id' => $address->id, 'label' => $address->label, 'deleted_at' => null]);
    }

    public function test_store_enforces_exactly_one_primary_marker(): void
    {
        [$user, $customer] = $this->makeActorAndCustomer(['customer.address.manage', 'customer.view']);
        $existingPrimary = CustomerAddress::withoutCompany()->forceCreate([
            'company_id' => $user->company_id,
            'customer_id' => $customer->id,
            'label' => 'First',
            'address' => 'First street',
            'is_primary' => true,
        ]);

        $this
            ->actingAs($user)
            ->post(route('admin.customers.addresses.store', $customer), $this->validPayload([
                'label' => 'Second primary',
                'is_primary' => true,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, CustomerAddress::withoutCompany()
            ->where('customer_id', $customer->id)->where('is_primary', true)->count());
        $this->assertFalse((bool) $existingPrimary->fresh()?->is_primary);
    }

    public function test_store_promotes_first_primary_when_collection_was_empty(): void
    {
        [$user, $customer] = $this->makeActorAndCustomer(['customer.address.manage', 'customer.view']);

        $this
            ->actingAs($user)
            ->post(route('admin.customers.addresses.store', $customer), $this->validPayload([
                'label' => 'Sole address',
                'is_primary' => false,
                'is_installation_point' => false,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $addresses = CustomerAddress::withoutCompany()->where('customer_id', $customer->id)->get();
        $this->assertCount(1, $addresses);
        $this->assertTrue((bool) $addresses[0]->is_primary);
        $this->assertTrue((bool) $addresses[0]->is_installation_point);
    }

    public function test_update_cannot_leave_collection_without_primary_marker(): void
    {
        [$user, $customer] = $this->makeActorAndCustomer(['customer.address.manage', 'customer.view']);
        $solePrimary = CustomerAddress::withoutCompany()->forceCreate([
            'company_id' => $user->company_id,
            'customer_id' => $customer->id,
            'label' => 'Sole',
            'address' => 'Sole street',
            'is_installation_point' => true,
            'is_primary' => true,
        ]);

        $this
            ->actingAs($user)
            ->put(route('admin.customers.addresses.update', [$customer, $solePrimary]), $this->validPayload([
                'label' => 'Sole updated',
                'is_primary' => false,
                'is_installation_point' => false,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $fresh = $solePrimary->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(1, CustomerAddress::withoutCompany()
            ->where('customer_id', $customer->id)->where('is_primary', true)->count());
        $this->assertSame(1, CustomerAddress::withoutCompany()
            ->where('customer_id', $customer->id)->where('is_installation_point', true)->count());
    }

    public function test_destroy_promotes_remaining_row_to_primary(): void
    {
        [$user, $customer] = $this->makeActorAndCustomer(['customer.address.manage', 'customer.view']);
        $primary = CustomerAddress::withoutCompany()->forceCreate([
            'company_id' => $user->company_id,
            'customer_id' => $customer->id,
            'label' => 'Primary to delete',
            'address' => 'Primary street',
            'is_primary' => true,
        ]);
        $remaining = CustomerAddress::withoutCompany()->forceCreate([
            'company_id' => $user->company_id,
            'customer_id' => $customer->id,
            'label' => 'Remaining',
            'address' => 'Remaining street',
            'is_primary' => false,
        ]);

        $this
            ->actingAs($user)
            ->delete(route('admin.customers.addresses.destroy', [$customer, $primary]))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Alamat dihapus permanen.');

        $this->assertDatabaseMissing('customer_addresses', ['id' => $primary->id]);
        $this->assertTrue((bool) $remaining->fresh()?->is_primary);
    }

    public function test_destroy_last_address_leaves_collection_with_zero_markers(): void
    {
        [$user, $customer] = $this->makeActorAndCustomer(['customer.address.manage', 'customer.view']);
        $sole = CustomerAddress::withoutCompany()->forceCreate([
            'company_id' => $user->company_id,
            'customer_id' => $customer->id,
            'label' => 'Only one',
            'address' => 'Only street',
            'is_installation_point' => true,
            'is_primary' => true,
        ]);

        $this
            ->actingAs($user)
            ->delete(route('admin.customers.addresses.destroy', [$customer, $sole]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(0, CustomerAddress::withoutCompany()->where('customer_id', $customer->id)->count());
    }

    private function makeActorAndCustomer(array $permissions): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $permissions[] = 'app.web';
        $role = $this->companyRole($company, 'customer-address-auth-'.uniqid(), $permissions);
        $this->grantRole($user, $role, $company);

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
