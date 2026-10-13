<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\CustomerContact;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Notifications\CustomerAccountCreated;
use App\Services\Core\CustomerService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\Service\Models\ServicePackage;
use Modules\SPK\Models\WorkOrder;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\AllowsManualCodes;

class CustomerAccountCreationTest extends TestCase
{
    use AllowsManualCodes;
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
        $this->setManualCodes($this->company->id, true, 'customer');
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
        $this->assertFalse(Hash::check('0800000001', $user->password));
        $this->assertTrue($user->hasRole('customer'));
    }

    public function test_customer_phone_number_does_not_log_in(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.customers.store'), $this->payload())
            ->assertRedirect();
        auth()->logout();

        $this->post(route('login'), ['email' => 'customer.account@example.test', 'password' => '0800000001'])
            ->assertSessionHasErrors();
        $this->assertGuest();
    }

    public function test_access_link_is_queued_without_a_token_and_mail_carries_a_valid_one(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.customers.store'), $this->payload())
            ->assertRedirect();

        $user = User::query()->where('email', 'customer.account@example.test')->firstOrFail();
        $notification = null;
        Notification::assertSentTo($user, CustomerAccountCreated::class, function (CustomerAccountCreated $n) use (&$notification) {
            $notification = $n;

            return true;
        });
        Notification::assertCount(1);

        // The queued payload must not hold a reset token (jobs/failed_jobs are plaintext storage).
        $this->assertStringNotContainsString('token', serialize($notification));
        $this->assertNotInstanceOf(ShouldBeEncrypted::class, $notification);

        $url = (string) $notification->toMail($user)->actionUrl;
        $this->assertSame(1, preg_match('#/reset-password/([^?]+)\?email=#', $url, $m));
        $this->assertTrue(Password::broker()->tokenExists($user, $m[1]));
        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/reset-password/', $url);

        auth()->logout();
        $this->post(route('password.store'), [
            'token' => $m[1],
            'email' => $user->email,
            'password' => 'N3w-Secret-Pass!',
            'password_confirmation' => 'N3w-Secret-Pass!',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('N3w-Secret-Pass!', $user->fresh()->password));
    }

    public function test_inactive_customer_gets_no_access_link_at_creation(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.customers.store'), [...$this->payload(), 'is_active' => false])
            ->assertRedirect();

        Notification::assertNothingSent();
    }

    public function test_resend_is_refused_for_inactive_customer_or_portal_account(): void
    {
        $customer = $this->createCustomerViaForm();
        Notification::fake();
        $this->actingAs($this->admin);

        $customer->forceFill(['is_active' => false])->save();
        $this->post(route('admin.customers.access-link', $customer))->assertRedirect()->assertSessionHas('error');

        $customer->forceFill(['is_active' => true])->save();
        $customer->user->forceFill(['is_active' => false])->save();
        $this->post(route('admin.customers.access-link', $customer))->assertRedirect()->assertSessionHas('error');

        Notification::assertNothingSent();
    }

    public function test_access_link_is_not_sent_when_the_transaction_rolls_back(): void
    {
        Notification::fake();
        $this->actingAs($this->admin);

        try {
            DB::transaction(function () {
                CustomerService::createWithUser([
                    'code' => 'CUS-ROLLBACK',
                    'name' => 'Rollback',
                    'type' => 'Individual',
                    'email' => 'rollback@example.test',
                    'phone' => '0800000002',
                    'is_active' => true,
                ]);

                throw new \RuntimeException('abort');
            });
        } catch (\RuntimeException) {
        }

        Notification::assertNothingSent();
    }

    public function test_staff_can_resend_access_link_throttled(): void
    {
        $customer = $this->createCustomerViaForm();
        Notification::fake();

        $this->actingAs($this->admin);
        for ($i = 0; $i < 3; $i++) {
            $this->post(route('admin.customers.access-link', $customer))
                ->assertRedirect()
                ->assertSessionHas('success');
        }
        Notification::assertSentToTimes($customer->user, CustomerAccountCreated::class, 3);

        $this->post(route('admin.customers.access-link', $customer))->assertTooManyRequests();
        Notification::assertSentToTimes($customer->user, CustomerAccountCreated::class, 3);
    }

    public function test_resend_access_link_requires_customer_update_permission(): void
    {
        $customer = $this->createCustomerViaForm();
        $viewer = User::factory()->create([
            'company_id' => $this->company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $role = Role::create(['name' => 'customer-viewer', 'guard_name' => 'web']);
        $role->givePermissionTo('customer.view');
        $viewer->assignRole($role);
        Notification::fake();

        $this->actingAs($viewer)->post(route('admin.customers.access-link', $customer))->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_resend_without_portal_account_reports_error_and_sends_nothing(): void
    {
        $customer = $this->createCustomerViaForm();
        $customer->forceFill(['user_id' => null])->save();
        Notification::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.customers.access-link', $customer))
            ->assertRedirect()
            ->assertSessionHas('error');

        Notification::assertNothingSent();
    }

    private function createCustomerViaForm(): Customer
    {
        $this->actingAs($this->admin)
            ->post(route('admin.customers.store'), $this->payload())
            ->assertRedirect();

        return Customer::query()->with('user')->where('code', 'CUS-ACCOUNT')->firstOrFail();
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

    public function test_create_without_a_code_gets_the_configured_format(): void
    {
        $payload = $this->payload();
        unset($payload['code']);

        $this->actingAs($this->admin)
            ->post(route('admin.customers.store'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customers', ['code' => 'CUS-00001', 'company_id' => $this->company->id]);
    }

    public function test_create_with_a_code_is_rejected_while_manual_input_is_off(): void
    {
        $this->setManualCodes($this->company->id, false, 'customer');

        $this->actingAs($this->admin)
            ->post(route('admin.customers.store'), $this->payload())
            ->assertInvalid(['code']);

        $this->assertDatabaseMissing('customers', ['code' => 'CUS-ACCOUNT']);
    }

    public function test_update_ignores_the_code_while_manual_input_is_off(): void
    {
        $customer = $this->createCustomerViaForm();
        $this->setManualCodes($this->company->id, false, 'customer');

        $this->actingAs($this->admin)
            ->put(route('admin.customers.update', $customer), ['code' => 'CUS-CHANGED', 'name' => 'Renamed', 'type' => 'Individual'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $customer->refresh();
        $this->assertSame('CUS-ACCOUNT', $customer->code);
        $this->assertSame('Renamed', $customer->name);
    }

    public function test_update_changes_the_code_when_manual_input_is_on(): void
    {
        $customer = $this->createCustomerViaForm();

        $this->actingAs($this->admin)
            ->put(route('admin.customers.update', $customer), ['code' => 'CUS-CHANGED', 'name' => 'Renamed', 'type' => 'Individual'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('CUS-CHANGED', $customer->fresh()->code);
    }

    public function test_create_and_edit_pages_expose_the_code_sequence(): void
    {
        $customer = $this->createCustomerViaForm();
        $this->setManualCodes($this->company->id, false, 'customer');

        $this->actingAs($this->admin)->get(route('admin.customers.create'))
            ->assertInertia(fn (Assert $page) => $page->where('codeSequence.manual', false)->has('codeSequence.preview'));
        $this->actingAs($this->admin)->get(route('admin.customers.edit', $customer))
            ->assertInertia(fn (Assert $page) => $page->where('codeSequence.manual', false)->has('codeSequence.preview'));
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
