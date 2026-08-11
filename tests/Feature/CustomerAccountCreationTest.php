<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\CustomerContact;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\Service\Models\ServicePackage;
use Modules\SPK\Models\WorkOrder;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerAccountCreationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private ServicePackage $package;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
        $this->seedRegion();

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create([
            'company_id' => $this->company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->admin->assignRole(Role::where('name', 'admin')->firstOrFail());
        $this->package = ServicePackageFactory::new()->create([
            'company_id' => $this->company->id,
            'price_mrc' => 250000,
            'price_otc' => 150000,
            'contract_min_months' => 12,
        ]);
        $this->location = Location::query()->create([
            'company_id' => $this->company->id,
            'code' => 'POP-ONBOARD',
            'name' => 'POP Onboarding',
            'type' => 'pop',
            'path' => 'POP Onboarding',
            'is_active' => true,
        ]);
    }

    public function test_customer_create_also_creates_customer_user(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.customers.store'), $this->payload())
            ->assertRedirect();

        $customer = Customer::query()->where('code', 'CUS-ACCOUNT')->firstOrFail();
        $user = User::query()->where('email', 'customer.account@example.test')->firstOrFail();

        $this->assertSame($this->company->id, $customer->company_id);
        $this->assertSame($user->id, $customer->user_id);
        $this->assertSame($this->company->id, $user->company_id);
        $this->assertTrue(Hash::check('0800000001', $user->password));
        $this->assertTrue($user->hasRole('customer'));
    }

    public function test_phone_is_required_when_creating_customer_user(): void
    {
        $payload = $this->payload();
        unset($payload['phone']);

        $this->actingAs($this->admin)
            ->post(route('admin.customers.store'), $payload)
            ->assertInvalid(['phone']);
    }

    public function test_individual_customer_does_not_require_tax_id(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.customers.store'), $this->payload())
            ->assertRedirect();

        $this->assertModelExists(Customer::query()->where('code', 'CUS-ACCOUNT')->firstOrFail());
    }

    public function test_customer_create_can_include_initial_address_and_contact(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.customers.store'), $this->payload())
            ->assertRedirect();

        $customer = Customer::query()->where('code', 'CUS-ACCOUNT')->firstOrFail();
        $subscription = ServiceSubscription::query()->where('customer_id', $customer->id)->firstOrFail();
        $workOrder = WorkOrder::query()->where('subscription_id', $subscription->id)->firstOrFail();

        $this->assertSame(2, CustomerAddress::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(2, CustomerContact::query()->where('customer_id', $customer->id)->count());
        $this->assertSame('Installation', $subscription->installationAddress->label);
        $this->assertSame($this->package->id, $subscription->service_package_id);
        $this->assertSame('250000.00', $subscription->mrc_amount);
        $this->assertSame('150000.00', $subscription->otc_installation_fee);
        $this->assertSame(12, $subscription->contract_months);
        $this->assertSame('installation', $workOrder->type);
        $this->assertSame('generated', $workOrder->status);
        $this->assertSame($this->location->id, $workOrder->location_id);
        $this->assertDatabaseHas('customer_addresses', [
            'customer_id' => $customer->id,
            'province_code' => '31',
            'city_code' => '3171',
            'district_code' => '3171010',
            'village_code' => '3171010001',
            'lat' => -6.2000000,
            'lng' => 106.8166667,
        ]);
    }

    public function test_initial_address_requires_related_data_permission(): void
    {
        $creator = User::factory()->create([
            'company_id' => $this->company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $role = Role::create(['name' => 'customer-creator', 'guard_name' => 'web']);
        $role->givePermissionTo('customer.create');
        $creator->assignRole($role);

        $this->actingAs($creator)
            ->post(route('admin.customers.store'), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseMissing('customers', ['code' => 'CUS-ACCOUNT']);
    }

    public function test_onboarding_requires_one_primary_and_one_installation_address(): void
    {
        $payload = $this->payload();
        $payload['addresses'][1]['is_primary'] = true;
        $payload['addresses'][1]['is_installation_point'] = false;

        $this->actingAs($this->admin)
            ->post(route('admin.customers.store'), $payload)
            ->assertInvalid(['addresses']);

        $this->assertDatabaseMissing('customers', ['code' => 'CUS-ACCOUNT']);
    }

    public function test_onboarding_rejects_malformed_address_and_contact_items(): void
    {
        $payload = $this->payload();
        $payload['addresses'] = ['invalid'];
        $payload['contacts'] = ['invalid'];

        $this->actingAs($this->admin)
            ->post(route('admin.customers.store'), $payload)
            ->assertInvalid(['addresses.0', 'contacts.0']);

        $this->assertDatabaseMissing('customers', ['code' => 'CUS-ACCOUNT']);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'code' => 'CUS-ACCOUNT',
            'name' => 'Customer Account',
            'type' => 'Individual',
            'email' => 'customer.account@example.test',
            'phone' => '0800000001',
            'is_active' => true,
            'addresses' => [
                [
                    'label' => 'Main',
                    'address' => 'Main Street 1',
                    'city' => 'Jakarta',
                    'postal_code' => '12345',
                    'province_code' => '31',
                    'city_code' => '3171',
                    'district_code' => '3171010',
                    'village_code' => '3171010001',
                    'lat' => -6.2,
                    'lng' => 106.8166667,
                    'is_primary' => true,
                    'is_installation_point' => false,
                    'notes' => null,
                ],
                [
                    'label' => 'Installation',
                    'address' => 'Installation Street 2',
                    'city' => 'Jakarta',
                    'postal_code' => '12346',
                    'province_code' => '31',
                    'city_code' => '3171',
                    'district_code' => '3171010',
                    'village_code' => '3171010001',
                    'lat' => -6.201,
                    'lng' => 106.817,
                    'is_primary' => false,
                    'is_installation_point' => true,
                    'notes' => null,
                ],
            ],
            'contacts' => [
                [
                    'name' => 'Primary Contact',
                    'position' => 'Owner',
                    'phone' => '0812-3456-7890',
                    'email' => 'primary.contact@example.test',
                    'is_primary' => true,
                    'notes' => null,
                ],
                [
                    'name' => 'Technical Contact',
                    'position' => 'Technical',
                    'phone' => '0812-3456-7891',
                    'email' => 'technical.contact@example.test',
                    'is_primary' => false,
                    'notes' => null,
                ],
            ],
            'subscription' => [
                'service_package_id' => $this->package->id,
                'serving_pop_id' => $this->location->id,
                'billing_day' => 5,
                'mrc_amount' => null,
                'otc_installation_fee' => null,
                'contract_months' => null,
                'notes' => null,
            ],
        ];
    }

    private function seedRegion(): void
    {
        DB::table('indonesia_provinces')->insert(['code' => '31', 'name' => 'DKI JAKARTA']);
        DB::table('indonesia_cities')->insert(['code' => '3171', 'province_code' => '31', 'name' => 'KOTA JAKARTA SELATAN']);
        DB::table('indonesia_districts')->insert(['code' => '3171010', 'city_code' => '3171', 'name' => 'KEBAYORAN BARU']);
        DB::table('indonesia_villages')->insert(['code' => '3171010001', 'district_code' => '3171010', 'name' => 'GANDARIA UTARA']);
    }
}
