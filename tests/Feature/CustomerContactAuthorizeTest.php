<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CustomerContactAuthorizeTest extends TestCase
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

        $this
            ->actingAs($user)
            ->post(route('admin.customers.contacts.store', $customer), $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('customer_contacts', 0);
    }

    public function test_store_forbids_user_with_only_legacy_customers_update(): void
    {
        [$user, $customer] = $this->makeActorAndCustomer(['customers.update', 'customer.view']);

        $this
            ->actingAs($user)
            ->post(route('admin.customers.contacts.store', $customer), $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('customer_contacts', 0);
    }

    public function test_store_maps_position_to_role_and_promotes_first_primary(): void
    {
        [$user, $customer] = $this->makeActorAndCustomer(['customer.address.manage', 'customer.view']);

        $this
            ->actingAs($user)
            ->post(route('admin.customers.contacts.store', $customer), $this->validPayload([
                'name' => 'Sole Contact',
                'position' => 'Site Manager',
                'is_primary' => false,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $contact = CustomerContact::withoutCompany()->first();
        $this->assertNotNull($contact);
        $this->assertSame('Site Manager', $contact->role);
        $this->assertTrue((bool) $contact->is_primary);
    }

    public function test_update_rejects_cross_tenant_customer_and_foreign_child_without_mutating_them(): void
    {
        [$user, $customer] = $this->makeActorAndCustomer(['customer.address.manage', 'customer.view']);

        $foreignCompany = Company::factory()->create();
        $foreignCustomer = Customer::withoutCompany()->forceCreate([
            'company_id' => $foreignCompany->id,
            'code' => 'CUS-FRGN-CONTACT-01',
            'name' => 'Foreign Contact Customer',
            'type' => 'Individual',
        ]);

        $this
            ->actingAs($user)
            ->post(route('admin.customers.contacts.store', $foreignCustomer), $this->validPayload())
            ->assertNotFound();
        $this->assertDatabaseCount('customer_contacts', 0);

        $otherCustomer = Customer::factory()->create(['company_id' => $user->company_id]);
        $foreignChild = CustomerContact::withoutCompany()->forceCreate([
            'company_id' => $user->company_id,
            'customer_id' => $otherCustomer->id,
            'name' => 'Untouched Contact',
            'phone' => '0811111111',
            'is_primary' => true,
        ]);

        $this
            ->actingAs($user)
            ->put(route('admin.customers.contacts.update', [$customer, $foreignChild]), $this->validPayload([
                'name' => 'Hacked Contact',
            ]))
            ->assertNotFound();

        $this->assertSame('Untouched Contact', $foreignChild->fresh()?->name);
    }

    public function test_update_maps_position_to_role(): void
    {
        [$user, $customer] = $this->makeActorAndCustomer(['customer.address.manage', 'customer.view']);
        $contact = CustomerContact::withoutCompany()->forceCreate([
            'company_id' => $user->company_id,
            'customer_id' => $customer->id,
            'name' => 'Old Contact',
            'phone' => '08123456789',
            'is_primary' => true,
        ]);

        $this
            ->actingAs($user)
            ->put(route('admin.customers.contacts.update', [$customer, $contact]), $this->validPayload([
                'name' => 'New Contact',
                'position' => 'Operations Lead',
                'phone' => '0899999999',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $fresh = $contact->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('New Contact', $fresh->name);
        $this->assertSame('Operations Lead', $fresh->role);
        $this->assertSame('0899999999', $fresh->phone);
    }

    public function test_update_forbids_user_without_permission_without_mutating(): void
    {
        [$user, $customer] = $this->makeActorAndCustomer(['customer.view']);
        $contact = CustomerContact::withoutCompany()->forceCreate([
            'company_id' => $user->company_id,
            'customer_id' => $customer->id,
            'name' => 'Original Contact',
            'phone' => '08123456789',
            'is_primary' => true,
        ]);

        $this
            ->actingAs($user)
            ->put(route('admin.customers.contacts.update', [$customer, $contact]), $this->validPayload([
                'name' => 'Changed Name',
            ]))
            ->assertForbidden();

        $this->assertSame('Original Contact', $contact->fresh()?->name);
    }

    public function test_primary_invariant_enforced_on_store_update_and_destroy(): void
    {
        [$user, $customer] = $this->makeActorAndCustomer(['customer.address.manage', 'customer.view']);
        $primary = CustomerContact::withoutCompany()->forceCreate([
            'company_id' => $user->company_id,
            'customer_id' => $customer->id,
            'name' => 'Primary',
            'phone' => '0811111111',
            'is_primary' => true,
        ]);
        $secondary = CustomerContact::withoutCompany()->forceCreate([
            'company_id' => $user->company_id,
            'customer_id' => $customer->id,
            'name' => 'Secondary',
            'phone' => '0822222222',
            'is_primary' => false,
        ]);

        // Store a new primary — old primary must be demoted.
        $this
            ->actingAs($user)
            ->post(route('admin.customers.contacts.store', $customer), $this->validPayload([
                'name' => 'New Primary',
                'is_primary' => true,
            ]))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, CustomerContact::withoutCompany()
            ->where('customer_id', $customer->id)->where('is_primary', true)->count());
        $this->assertFalse((bool) $primary->fresh()?->is_primary);

        // Updating the current primary to non-primary must leave exactly one primary.
        $newPrimary = CustomerContact::withoutCompany()
            ->where('customer_id', $customer->id)->where('is_primary', true)->firstOrFail();
        $this
            ->actingAs($user)
            ->put(route('admin.customers.contacts.update', [$customer, $newPrimary]), $this->validPayload([
                'name' => 'Still sole-ish',
                'is_primary' => false,
            ]))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, CustomerContact::withoutCompany()
            ->where('customer_id', $customer->id)->where('is_primary', true)->count());

        // Destroying the primary promotes a remaining contact.
        $this
            ->actingAs($user)
            ->delete(route('admin.customers.contacts.destroy', [$customer, $newPrimary]))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, CustomerContact::withoutCompany()
            ->where('customer_id', $customer->id)->where('is_primary', true)->count());
        $this->assertNotNull($secondary->fresh());
    }

    public function test_destroy_last_contact_leaves_collection_with_zero_markers(): void
    {
        [$user, $customer] = $this->makeActorAndCustomer(['customer.address.manage', 'customer.view']);
        $sole = CustomerContact::withoutCompany()->forceCreate([
            'company_id' => $user->company_id,
            'customer_id' => $customer->id,
            'name' => 'Only Contact',
            'phone' => '0811111111',
            'is_primary' => true,
        ]);

        $this
            ->actingAs($user)
            ->delete(route('admin.customers.contacts.destroy', [$customer, $sole]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(0, CustomerContact::withoutCompany()->where('customer_id', $customer->id)->count());
    }

    private function makeActorAndCustomer(array $permissions): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $permissions[] = 'app.web';
        $role = $this->companyRole($company, 'customer-contact-auth-'.uniqid(), $permissions);
        $this->grantRole($user, $role, $company);

        $customer = Customer::factory()->create(['company_id' => $company->id]);

        return [$user->fresh(), $customer];
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Contact Person',
            'position' => 'Manager',
            'phone' => '08123456789',
            'email' => 'contact@example.test',
            'is_primary' => false,
            'notes' => null,
        ], $overrides);
    }
}
