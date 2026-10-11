<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\CustomerContact;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\Invoice;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Spatie\Permission\Models\Role;
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

    public function test_customer_index_filters_by_account(): void
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

        $this->actingAs($this->admin)->get(route('admin.customers.index', ['account' => 'inactive']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.name', 'Inactive Customer')
                ->where('filters.account', 'inactive'));
    }

    public function test_edit_resolves_geocode_results_only_with_address_manage_permission(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);

        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([[
                'display_name' => 'Banyuseri, Banjar, Buleleng, Bali, Indonesia',
                'lat' => '-8.2000000',
                'lon' => '115.1000000',
                'address' => [],
            ]]),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.customers.edit', [
                'customer' => $customer,
                'geocode_query' => 'Banyuseri Banjar Bali',
                'geocode_index' => 2,
            ]), $this->inertiaPartialHeaders('Admin/Customers/Edit', 'geocodeResults,geocodeIndex'))
            ->assertOk();

        $props = $response->json('props') ?? [];

        $this->assertCount(1, $props['geocodeResults'] ?? []);
        $this->assertSame('Banyuseri, Banjar, Buleleng, Bali, Indonesia', $props['geocodeResults'][0]['display_name'] ?? null);
        $this->assertSame(2, $props['geocodeIndex'] ?? null);

        Http::assertSentCount(1);
    }

    public function test_edit_geocode_without_address_manage_permission_returns_empty_without_external_call(): void
    {
        [$user, $customer] = $this->makeCustomerUpdaterWithoutAddressManage();

        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);

        $response = $this->actingAs($user)
            ->get(route('admin.customers.edit', [
                'customer' => $customer,
                'geocode_query' => 'Jl Sudirman Denpasar',
            ]), $this->inertiaPartialHeaders('Admin/Customers/Edit', 'geocodeResults'))
            ->assertOk();

        $props = $response->json('props') ?? [];

        $this->assertSame([], $props['geocodeResults'] ?? null);

        Http::assertNothingSent();
    }

    public function test_update_customer_ignores_forged_related_and_tenant_payload(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $address = CustomerAddress::withoutCompany()->forceCreate([
            'company_id' => $this->admin->company_id,
            'customer_id' => $customer->id,
            'label' => 'Home',
            'address' => 'Main Street',
            'city' => 'Jakarta',
            'is_primary' => true,
        ]);
        $contact = CustomerContact::withoutCompany()->forceCreate([
            'company_id' => $this->admin->company_id,
            'customer_id' => $customer->id,
            'name' => 'Primary Contact',
            'phone' => '08123456789',
            'is_primary' => true,
        ]);
        $otherCompany = Company::factory()->create();

        $this->actingAs($this->admin)
            ->put(route('admin.customers.update', $customer), [
                'code' => 'CUS-EDIT-01',
                'name' => 'Renamed Customer',
                'type' => 'Company',
                'email' => 'renamed@example.test',
                'phone' => '08987654321',
                'is_active' => false,
                // Forged related + tenant keys must never reach the database.
                'addresses' => [['id' => $address->id, 'label' => 'HACKED', 'is_primary' => false]],
                'contacts' => [['id' => $contact->id, 'name' => 'HACKED']],
                'company_id' => $otherCompany->id,
                'user_id' => $this->admin->id + 1000,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $customer->refresh();
        $this->assertSame('Renamed Customer', $customer->name);
        $this->assertFalse((bool) $customer->is_active);
        $this->assertSame($this->admin->company_id, $customer->company_id);
        $this->assertSame(CustomerAddress::withoutCompany()->count(), 1);
        $this->assertSame('Home', $address->fresh()->label);
        $this->assertTrue((bool) $address->fresh()->is_primary);
        $this->assertSame('Primary Contact', $contact->fresh()->name);
        $this->assertTrue((bool) $contact->fresh()->is_primary);
    }

    public function test_edit_sends_can_update_true_for_user_with_update_permission(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);

        $this->actingAs($this->admin)->get(route('admin.customers.edit', $customer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Customers/Edit')
                ->where('can.update', true));
    }

    public function test_edit_denied_for_viewer_without_update_permission(): void
    {
        [$user, $customer] = $this->makeCustomerViewerWithoutUpdate();

        $this->actingAs($user)->get(route('admin.customers.edit', $customer))
            ->assertForbidden();
    }

    public function test_update_rejects_cross_tenant_customer_without_mutating_it(): void
    {
        $foreignCompany = Company::factory()->create();
        $foreign = Customer::withoutCompany()->forceCreate([
            'company_id' => $foreignCompany->id,
            'code' => 'CUS-FRGN-01',
            'name' => 'Foreign Customer',
            'type' => 'Individual',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.customers.update', $foreign), [
                'code' => 'CUS-FRGN-01',
                'name' => 'Hacked Name',
                'type' => 'Individual',
                'is_active' => false,
            ])
            ->assertNotFound();

        $fresh = Customer::withoutCompany()->find($foreign->id);
        $this->assertNotNull($fresh);
        $this->assertSame('Foreign Customer', $fresh->name);
        $this->assertTrue((bool) $fresh->is_active);
    }

    public function test_update_forbidden_without_customer_update_permission(): void
    {
        [$user, $customer] = $this->makeCustomerViewerWithoutUpdate();
        $originalName = $customer->name;

        $this->actingAs($user)
            ->put(route('admin.customers.update', $customer), [
                'code' => $customer->code,
                'name' => 'Should Not Persist',
                'type' => 'Individual',
                'is_active' => false,
            ])
            ->assertForbidden();

        $this->assertSame($originalName, $customer->fresh()?->name);
    }

    private function makeCustomerUpdaterWithoutAddressManage(): array
    {
        $role = Role::firstOrCreate(['name' => 'customer-updater-test'], ['guard_name' => 'web']);
        $role->syncPermissions(['customer.view', 'customer.update']);
        app()['cache']->forget('spatie.permission.cache');

        $user = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        $customer = Customer::factory()->create(['company_id' => $user->company_id]);

        return [$user, $customer];
    }

    private function makeCustomerViewerWithoutUpdate(): array
    {
        $role = Role::firstOrCreate(['name' => 'customer-viewer-test'], ['guard_name' => 'web']);
        $role->syncPermissions(['customer.view']);
        app()['cache']->forget('spatie.permission.cache');

        $user = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        $customer = Customer::factory()->create(['company_id' => $user->company_id]);

        return [$user, $customer];
    }

    public function test_update_accepts_phone_with_allowed_chars(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);

        // PhoneInput sanitizer keeps only [0-9()+-] and strips spaces/letters/dots,
        // so the backend contract must accept exactly that set.
        $this->actingAs($this->admin)
            ->put(route('admin.customers.update', $customer), [
                'code' => $customer->code,
                'name' => $customer->name,
                'type' => $customer->type,
                'phone' => '+62(81)2345-6789',
                'is_active' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('+62(81)2345-6789', $customer->fresh()?->phone);
    }

    public function test_update_rejects_phone_outside_allowed_chars(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);

        $originalPhone = $customer->phone;

        $this->actingAs($this->admin)
            ->put(route('admin.customers.update', $customer), [
                'code' => $customer->code,
                'name' => $customer->name,
                'type' => $customer->type,
                'phone' => '+62 0812-3456 ext. 90',
                'is_active' => true,
            ])
            ->assertSessionHasErrors(['phone'])
            ->assertRedirect();

        $this->assertSame($originalPhone, $customer->fresh()?->phone);
    }

    public function test_update_contact_rejects_phone_outside_allowed_chars(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $contact = CustomerContact::withoutCompany()->forceCreate([
            'company_id' => $this->admin->company_id,
            'customer_id' => $customer->id,
            'name' => 'Primary Contact',
            'phone' => '08123456789',
            'is_primary' => true,
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.customers.contacts.update', [$customer, $contact]), [
                'name' => 'Primary Contact',
                'phone' => '0812-3456-7890 ext. 9',
                'is_primary' => true,
            ])
            ->assertSessionHasErrors(['phone'])
            ->assertRedirect();

        $this->assertSame('08123456789', $contact->fresh()?->phone);
    }

    public function test_show_includes_billing_spk_and_ticket_history(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $category = TicketCategory::forceCreate([
            'company_id' => $this->admin->company_id,
            'name' => 'Customer History',
            'code' => 'CUSTOMER-HISTORY',
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);
        Invoice::forceCreate([
            'company_id' => $this->admin->company_id,
            'number' => 'INV-CUSTOMER-HISTORY',
            'type' => 'recurring',
            'source' => 'manual',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'status' => 'partial',
            'subtotal' => 100000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 100000,
            'paid_amount' => 40000,
            'created_by' => $this->admin->id,
        ]);
        WorkOrder::forceCreate([
            'company_id' => $this->admin->company_id,
            'code' => 'SPK-CUSTOMER-HISTORY',
            'type' => 'maintenance',
            'title' => 'Customer SPK',
            'status' => 'generated',
            'customer_id' => $customer->id,
            'source' => 'manual',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
        ]);
        Ticket::forceCreate([
            'company_id' => $this->admin->company_id,
            'code' => 'TKT-CUSTOMER-HISTORY',
            'title' => 'Customer Ticket',
            'source' => 'internal',
            'category_id' => $category->id,
            'status' => 'open',
            'priority' => 'medium',
            'customer_id' => $customer->id,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.customers.show', $customer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('history.invoices.0.number', 'INV-CUSTOMER-HISTORY')
                ->where('history.invoices.0.paid_amount', '40000.00')
                ->where('history.work_orders.0.code', 'SPK-CUSTOMER-HISTORY')
                ->where('history.tickets.0.code', 'TKT-CUSTOMER-HISTORY')
            );
    }

    /** @return array<string, string> */
    private function inertiaPartialHeaders(string $component, string $partialData): array
    {
        $version = app(HandleInertiaRequests::class)
            ->version(Request::create('/'));

        return array_filter([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => is_string($version) ? $version : '',
            'X-Inertia-Partial-Component' => $component,
            'X-Inertia-Partial-Data' => $partialData,
        ], static fn (string $value): bool => $value !== '');
    }
}
