<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\User;
use App\Services\Core\CustomerService;
use App\Support\PublicId;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\Service\Models\ServicePackage;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\AllowsManualCodes;
use Tests\Traits\BuildsCustomerAcquirers;

class CustomerSalesAttributionTest extends TestCase
{
    use AllowsManualCodes;
    use BuildsCustomerAcquirers;
    use RefreshDatabase;

    private const ONBOARDING_PERMISSIONS = [
        'customer.view', 'customer.create', 'customer.address.manage', 'customer.subscription.manage', 'spk.create',
    ];

    private Company $otherCompany;

    private ServicePackage $package;

    private Location $location;

    private User $manager;

    private User $staff;

    private User $adminWithoutProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndCompany();
        $this->setManualCodes($this->company->id, true, 'customer');
        $this->seedRegion();
        $this->otherCompany = Company::factory()->create(['is_active' => true]);
        $this->package = ServicePackageFactory::new()->create(['company_id' => $this->company->id]);
        $this->location = Location::query()->create([
            'company_id' => $this->company->id,
            'code' => 'POP-SALES',
            'name' => 'POP Sales',
            'type' => 'pop',
            'path' => 'POP Sales',
            'is_active' => true,
        ]);
        $this->manager = $this->employee('manager');
        $this->staff = $this->employee('staff');
        $this->adminWithoutProfile = $this->employee('admin', profile: false);
    }

    // create

    public function test_create_defaults_the_acquirer_to_the_eligible_creator(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.customers.store'), $this->payload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $customer = Customer::query()->where('code', 'CUS-SALES')->firstOrFail();
        $this->assertSame($this->manager->id, $customer->acquired_by_user_id);
        $this->assertAcquirerAudit($customer, 'acquirer_assigned', null, $this->manager->id, null, $this->manager);
    }

    public function test_create_stays_unassigned_when_the_creator_is_not_an_eligible_employee(): void
    {
        $this->actingAs($this->adminWithoutProfile)
            ->post(route('admin.customers.store'), $this->payload())
            ->assertRedirect();

        $customer = Customer::query()->where('code', 'CUS-SALES')->firstOrFail();
        $this->assertNull($customer->acquired_by_user_id);
        $this->assertSame(0, $this->acquirerAuditCount());
    }

    public function test_create_honors_an_explicit_eligible_acquirer_from_an_assign_holder(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.customers.store'), $this->payload(['acquired_by_user_id' => $this->staff->public_id]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $customer = Customer::query()->where('code', 'CUS-SALES')->firstOrFail();
        $this->assertSame($this->staff->id, $customer->acquired_by_user_id);
        $this->assertAcquirerAudit($customer, 'acquirer_assigned', null, $this->staff->id, null, $this->manager);
    }

    public function test_create_honors_an_explicit_null_as_unassigned_instead_of_the_default(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.customers.store'), $this->payload(['acquired_by_user_id' => null]))
            ->assertRedirect();

        $this->assertNull(Customer::query()->where('code', 'CUS-SALES')->firstOrFail()->acquired_by_user_id);
        $this->assertSame(0, $this->acquirerAuditCount());
    }

    public function test_create_rejects_ineligible_acquirers_for_an_assign_holder(): void
    {
        $inactive = $this->employee('manager', attributes: ['is_active' => false]);
        $foreign = $this->employee('manager', $this->otherCompany);
        $ineligible = [
            'technician' => $this->employee('technician')->public_id,
            'inactive' => $inactive->public_id,
            'without employee profile' => $this->adminWithoutProfile->public_id,
            'foreign company' => $foreign->public_id,
            'unknown' => PublicId::new(),
            'an integer id' => 987654,
        ];

        foreach ($ineligible as $userId) {
            $this->actingAs($this->manager)
                ->post(route('admin.customers.store'), $this->payload(['acquired_by_user_id' => $userId]))
                ->assertInvalid(['acquired_by_user_id']);
        }

        $this->assertDatabaseMissing('customers', ['code' => 'CUS-SALES']);
    }

    public function test_create_ignores_a_forged_acquirer_without_the_assign_capability(): void
    {
        $creator = $this->employeeWithPermissions(self::ONBOARDING_PERMISSIONS);

        foreach ([$this->staff->public_id, PublicId::new(), 987654] as $index => $forged) {
            $this->actingAs($creator)
                ->post(route('admin.customers.store'), $this->payload([
                    'code' => 'CUS-FORGED-'.$index,
                    'email' => "forged{$index}@example.test",
                    'acquired_by_user_id' => $forged,
                ]))
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            $customer = Customer::query()->where('code', 'CUS-FORGED-'.$index)->firstOrFail();
            $this->assertSame($creator->id, $customer->acquired_by_user_id, 'The server default applies, not the forged value.');
        }
    }

    public function test_acquirer_column_is_not_mass_assignable(): void
    {
        $this->assertFalse((new Customer())->isFillable('acquired_by_user_id'));

        $this->actingAs($this->manager);
        $customer = CustomerService::createWithUser([
            'code' => 'CUS-MASS',
            'name' => 'Mass Assignment',
            'type' => 'Individual',
            'email' => 'mass@example.test',
            'phone' => '0800000009',
            'is_active' => true,
            'acquired_by_user_id' => $this->staff->id,
        ]);

        $this->assertNull($customer->fresh()->acquired_by_user_id);
    }

    // update: assign

    public function test_assign_holder_can_assign_an_unassigned_customer(): void
    {
        $customer = $this->customerAcquiredBy(null);

        $this->actingAs($this->staff)
            ->put(route('admin.customers.update', $customer), $this->updatePayload($customer, ['acquired_by_user_id' => $this->manager->public_id]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($this->manager->id, $customer->fresh()->acquired_by_user_id);
        $this->assertAcquirerAudit($customer, 'acquirer_assigned', null, $this->manager->id, null, $this->staff);
    }

    public function test_assign_rejects_ineligible_and_foreign_acquirers_without_leaking_their_existence(): void
    {
        $customer = $this->customerAcquiredBy(null);
        $foreign = $this->employee('manager', $this->otherCompany);

        $messages = [];
        $unknown = PublicId::new();
        foreach ([$foreign->public_id, $unknown, $this->employee('technician')->public_id, $this->adminWithoutProfile->public_id] as $userId) {
            $response = $this->actingAs($this->manager)
                ->put(route('admin.customers.update', $customer), $this->updatePayload($customer, ['acquired_by_user_id' => $userId]))
                ->assertInvalid(['acquired_by_user_id']);
            $messages[$userId] = session('errors')->first('acquired_by_user_id');
            $this->assertNotNull($response);
        }

        $this->assertSame($messages[$foreign->public_id], $messages[$unknown]);
        $this->assertNull($customer->fresh()->acquired_by_user_id);
        $this->assertSame(0, $this->acquirerAuditCount());
    }

    public function test_update_rejects_non_id_acquirer_values_and_leaves_the_column_unchanged(): void
    {
        foreach (['staff' => $this->staff, 'manager' => $this->manager] as $label => $actor) {
            foreach ([true, '1abc', [], 1.5] as $forged) {
                $unassigned = $this->customerAcquiredBy(null);
                $acquired = $this->customerAcquiredBy($this->manager);

                $this->actingAs($actor)
                    ->putJson(route('admin.customers.update', $unassigned), $this->updatePayload($unassigned, ['acquired_by_user_id' => $forged]))
                    ->assertStatus(422)
                    ->assertJsonValidationErrors('acquired_by_user_id');

                $this->actingAs($actor)
                    ->putJson(route('admin.customers.update', $acquired), $this->updatePayload($acquired, [
                        'acquired_by_user_id' => $forged,
                        'acquirer_correction_reason' => 'Forged',
                    ]))
                    ->assertStatus(422)
                    ->assertJsonValidationErrors('acquired_by_user_id');

                $this->assertNull($unassigned->fresh()->acquired_by_user_id, "$label sending ".json_encode($forged));
                $this->assertSame($this->manager->id, $acquired->fresh()->acquired_by_user_id, "$label sending ".json_encode($forged));
            }
        }

        $this->assertSame(0, $this->acquirerAuditCount());
    }

    public function test_create_rejects_non_id_acquirer_values(): void
    {
        foreach ([true, '1abc', [], 1.5] as $index => $forged) {
            $this->actingAs($this->manager)
                ->postJson(route('admin.customers.store'), $this->payload(['code' => 'CUS-BAD-'.$index, 'acquired_by_user_id' => $forged]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('acquired_by_user_id');
        }

        $this->assertDatabaseMissing('customers', ['code' => 'CUS-SALES']);
    }

    // update: correct

    public function test_correction_requires_a_reason_and_is_audited_with_old_new_reason_actor_and_company(): void
    {
        $customer = $this->customerAcquiredBy($this->staff);
        $newAcquirer = $this->employee('manager');

        $this->actingAs($this->manager)
            ->put(route('admin.customers.update', $customer), $this->updatePayload($customer, ['acquired_by_user_id' => $newAcquirer->public_id]))
            ->assertInvalid(['acquirer_correction_reason']);
        $this->assertSame($this->staff->id, $customer->fresh()->acquired_by_user_id);
        $this->assertSame(0, $this->acquirerAuditCount());

        $this->actingAs($this->manager)
            ->put(route('admin.customers.update', $customer), $this->updatePayload($customer, [
                'acquired_by_user_id' => $newAcquirer->public_id,
                'acquirer_correction_reason' => 'Handed over after the original salesperson left the team.',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($newAcquirer->id, $customer->fresh()->acquired_by_user_id);
        $this->assertAcquirerAudit(
            $customer,
            'acquirer_corrected',
            $this->staff->id,
            $newAcquirer->id,
            'Handed over after the original salesperson left the team.',
            $this->manager,
        );
    }

    public function test_clearing_a_set_acquirer_is_a_correction_that_needs_a_reason(): void
    {
        $customer = $this->customerAcquiredBy($this->staff);

        $this->actingAs($this->manager)
            ->put(route('admin.customers.update', $customer), $this->updatePayload($customer, ['acquired_by_user_id' => null]))
            ->assertInvalid(['acquirer_correction_reason']);
        $this->assertSame($this->staff->id, $customer->fresh()->acquired_by_user_id);

        $this->actingAs($this->manager)
            ->put(route('admin.customers.update', $customer), $this->updatePayload($customer, [
                'acquired_by_user_id' => null,
                'acquirer_correction_reason' => 'Credited to the wrong employee.',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNull($customer->fresh()->acquired_by_user_id);
        $this->assertAcquirerAudit($customer, 'acquirer_corrected', $this->staff->id, null, 'Credited to the wrong employee.', $this->manager);
    }

    public function test_correction_reason_is_bounded(): void
    {
        $customer = $this->customerAcquiredBy($this->staff);

        $this->actingAs($this->manager)
            ->put(route('admin.customers.update', $customer), $this->updatePayload($customer, [
                'acquired_by_user_id' => $this->manager->public_id,
                'acquirer_correction_reason' => str_repeat('x', 501),
            ]))
            ->assertInvalid(['acquirer_correction_reason']);

        $this->assertSame($this->staff->id, $customer->fresh()->acquired_by_user_id);
    }

    public function test_correction_rejects_an_ineligible_new_acquirer_even_with_a_reason(): void
    {
        $customer = $this->customerAcquiredBy($this->staff);

        $this->actingAs($this->manager)
            ->put(route('admin.customers.update', $customer), $this->updatePayload($customer, [
                'acquired_by_user_id' => $this->employee('technician')->public_id,
                'acquirer_correction_reason' => 'Technician took the order.',
            ]))
            ->assertInvalid(['acquired_by_user_id']);

        $this->assertSame($this->staff->id, $customer->fresh()->acquired_by_user_id);
    }

    public function test_resubmitting_the_unchanged_acquirer_is_a_noop_even_when_that_user_is_no_longer_eligible(): void
    {
        $former = $this->employee('staff', attributes: ['is_active' => false]);
        $customer = $this->customerAcquiredBy($former);

        $this->actingAs($this->manager)
            ->put(route('admin.customers.update', $customer), $this->updatePayload($customer, [
                'name' => 'Renamed While Keeping History',
                'acquired_by_user_id' => $former->public_id,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $fresh = $customer->fresh();
        $this->assertSame('Renamed While Keeping History', $fresh->name);
        $this->assertSame($former->id, $fresh->acquired_by_user_id);
        $this->assertSame(0, $this->acquirerAuditCount());
    }

    public function test_update_without_the_key_leaves_the_acquirer_untouched(): void
    {
        $customer = $this->customerAcquiredBy($this->staff);

        $this->actingAs($this->manager)
            ->put(route('admin.customers.update', $customer), $this->updatePayload($customer, ['name' => 'Only The Name']))
            ->assertRedirect();

        $this->assertSame($this->staff->id, $customer->fresh()->acquired_by_user_id);
        $this->assertSame(0, $this->acquirerAuditCount());
    }

    // update: capability matrix

    public function test_assign_only_user_cannot_change_or_clear_a_set_acquirer_and_nothing_else_is_saved(): void
    {
        $customer = $this->customerAcquiredBy($this->manager, ['name' => 'Untouched Customer']);

        foreach ([$this->staff->public_id, null] as $attempt) {
            $this->actingAs($this->staff)
                ->put(route('admin.customers.update', $customer), $this->updatePayload($customer, [
                    'name' => 'Hijacked Customer',
                    'acquired_by_user_id' => $attempt,
                    'acquirer_correction_reason' => 'Trying to correct without the capability.',
                ]))
                ->assertForbidden();
        }

        $fresh = $customer->fresh();
        $this->assertSame('Untouched Customer', $fresh->name, 'The whole update is rolled back.');
        $this->assertSame($this->manager->id, $fresh->acquired_by_user_id);
        $this->assertSame(0, $this->acquirerAuditCount());
    }

    public function test_correct_only_user_can_correct_but_cannot_assign_from_unassigned(): void
    {
        $corrector = $this->employeeWithPermissions(['customer.view', 'customer.update', 'customer.sales.correct']);
        $acquired = $this->customerAcquiredBy($this->staff);
        $unassigned = $this->customerAcquiredBy(null);

        $this->actingAs($corrector)
            ->put(route('admin.customers.update', $unassigned), $this->updatePayload($unassigned, ['acquired_by_user_id' => $this->manager->public_id]))
            ->assertForbidden();
        $this->assertNull($unassigned->fresh()->acquired_by_user_id);

        $this->actingAs($corrector)
            ->put(route('admin.customers.update', $acquired), $this->updatePayload($acquired, [
                'acquired_by_user_id' => $this->manager->public_id,
                'acquirer_correction_reason' => 'Account transferred.',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame($this->manager->id, $acquired->fresh()->acquired_by_user_id);
    }

    public function test_forged_acquirer_from_a_user_without_any_sales_capability_is_silently_ignored(): void
    {
        $editor = $this->employeeWithPermissions(['customer.view', 'customer.update']);
        $acquired = $this->customerAcquiredBy($this->staff);
        $unassigned = $this->customerAcquiredBy(null);

        foreach ([[$acquired, $this->manager->public_id], [$unassigned, $this->manager->public_id], [$unassigned, PublicId::new()], [$unassigned, 987654], [$acquired, null]] as [$customer, $forged]) {
            $this->actingAs($editor)
                ->put(route('admin.customers.update', $customer), $this->updatePayload($customer, [
                    'name' => 'Edited By Editor',
                    'acquired_by_user_id' => $forged,
                    'acquirer_correction_reason' => 'forged',
                ]))
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }

        $this->assertSame('Edited By Editor', $acquired->fresh()->name);
        $this->assertSame($this->staff->id, $acquired->fresh()->acquired_by_user_id);
        $this->assertNull($unassigned->fresh()->acquired_by_user_id);
        $this->assertSame(0, $this->acquirerAuditCount());
    }

    public function test_a_foreign_company_customer_stays_unreachable_even_with_a_valid_acquirer_payload(): void
    {
        $foreignCustomer = $this->customerAcquiredBy(null, company: $this->otherCompany);

        $this->actingAs($this->manager)
            ->put(route('admin.customers.update', $foreignCustomer), $this->updatePayload($foreignCustomer, ['acquired_by_user_id' => $this->staff->public_id]))
            ->assertNotFound();

        $this->assertNull(Customer::withoutCompany()->find($foreignCustomer->id)->acquired_by_user_id);
    }

    // deleting acquirer users

    public function test_database_refuses_to_delete_a_user_who_acquired_a_customer(): void
    {
        $acquirer = $this->companyUser();
        $customer = $this->customerAcquiredBy($acquirer);

        try {
            DB::table('users')->where('id', $acquirer->id)->delete();
            $this->fail('Deleting an acquirer must be refused by the foreign key.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('customers_acquired_by_user_id_foreign', $exception->getMessage());
        }

        $this->assertSame($acquirer->id, $customer->fresh()->acquired_by_user_id);
        $this->assertDatabaseHas('users', ['id' => $acquirer->id]);
    }

    public function test_user_delete_is_refused_with_a_message_when_the_user_acquired_customers(): void
    {
        $admin = $this->employee('admin', profile: false);
        $acquirer = $this->companyUser();
        $this->customerAcquiredBy($acquirer);
        $this->customerAcquiredBy($acquirer)->delete();
        $bystander = $this->companyUser();

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $acquirer))
            ->assertSessionHasErrors(['user' => 'Pengguna yang dicatat memperoleh pelanggan tidak bisa dihapus. Nonaktifkan akunnya.']);
        $this->assertDatabaseHas('users', ['id' => $acquirer->id]);

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $bystander))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('users', ['id' => $bystander->id]);
    }

    public function test_profile_delete_is_refused_before_logout_when_the_user_acquired_customers(): void
    {
        $acquirer = $this->employee('staff');
        $this->customerAcquiredBy($acquirer)->delete();

        $this->actingAs($acquirer)
            ->from('/profile')
            ->delete('/profile', ['password' => 'password'])
            ->assertRedirect('/profile')
            ->assertSessionHasErrors(['user' => 'Pengguna yang dicatat memperoleh pelanggan tidak bisa dihapus. Nonaktifkan akunnya.']);

        $this->assertAuthenticatedAs($acquirer);
        $this->assertDatabaseHas('users', ['id' => $acquirer->id]);
    }

    // helpers

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function updatePayload(Customer $customer, array $overrides = []): array
    {
        return [
            'code' => $customer->code,
            'name' => $customer->name,
            'type' => $customer->type,
            'is_active' => true,
            ...$overrides,
        ];
    }

    private function assertAcquirerAudit(Customer $customer, string $description, ?int $old, ?int $new, ?string $reason, User $actor): void
    {
        $activity = Activity::query()
            ->where('log_name', 'customer')
            ->where('description', $description)
            ->where('subject_type', Customer::class)
            ->where('subject_id', $customer->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($activity, "No {$description} audit row for customer {$customer->id}.");
        $this->assertSame($old, $activity->properties->get('old'));
        $this->assertSame($new, $activity->properties->get('new'));
        $this->assertSame($reason, $activity->properties->get('reason'));
        $this->assertSame($actor->id, $activity->properties->get('actor_id'));
        $this->assertSame($actor->id, $activity->causer_id);
        $this->assertSame($customer->company_id, (int) $activity->getAttribute('company_id'));
    }

    private function acquirerAuditCount(): int
    {
        return Activity::query()
            ->where('log_name', 'customer')
            ->whereIn('description', ['acquirer_assigned', 'acquirer_corrected'])
            ->count();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'code' => 'CUS-SALES',
            'name' => 'Sales Attribution Customer',
            'type' => 'Individual',
            'email' => 'sales.attribution@example.test',
            'phone' => '0800000002',
            'is_active' => true,
            'addresses' => [[
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
                'is_primary' => true,
                'is_installation_point' => true,
                'notes' => null,
            ]],
            'contacts' => [[
                'name' => 'Primary Contact',
                'position' => 'Owner',
                'phone' => '0812-3456-7890',
                'email' => 'primary.contact@example.test',
                'is_primary' => true,
                'notes' => null,
            ]],
            'subscription' => [
                'service_package_id' => $this->package->public_id,
                'serving_pop_id' => $this->location->public_id,
                'billing_day' => 5,
                'mrc_amount' => null,
                'otc_installation_fee' => null,
                'contract_months' => null,
                'notes' => null,
            ],
            ...$overrides,
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
