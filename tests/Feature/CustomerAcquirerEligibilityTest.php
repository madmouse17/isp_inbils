<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Queries\Admin\CustomerAcquirerQuery;
use App\Rules\IsEligibleCustomerAcquirer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;
use Tests\Traits\BuildsCustomerAcquirers;

class CustomerAcquirerEligibilityTest extends TestCase
{
    use BuildsCustomerAcquirers;
    use RefreshDatabase;

    private Company $otherCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndCompany();
        $this->otherCompany = Company::factory()->create(['is_active' => true]);
    }

    public function test_eligible_users_are_active_same_company_employees_holding_customer_create(): void
    {
        $viaRole = $this->employee('manager');
        $viaDirectPermission = $this->companyUser();
        $viaDirectPermission->givePermissionTo('customer.create');
        $this->giveProfile($viaDirectPermission);

        $this->employee('manager', attributes: ['is_active' => false]);
        $this->employee('manager', profile: ['status' => 'inactive']);
        $softDeletedProfile = $this->employee('manager');
        $softDeletedProfile->employeeProfile()->withoutCompany()->firstOrFail()->delete();
        $this->employee('admin', profile: false);
        $this->employee('technician');
        $this->employee('customer');
        $this->employee('manager', $this->otherCompany);
        $this->employee('manager', profile: ['company_id' => $this->otherCompany->id]);
        $this->companyUser();

        $this->assertEqualsCanonicalizing(
            [$viaRole->id, $viaDirectPermission->id],
            CustomerAcquirerQuery::eligible($this->company->id)->pluck('id')->all(),
        );
    }

    public function test_eligibility_is_scoped_to_the_given_company(): void
    {
        $foreign = $this->employee('manager', $this->otherCompany);

        $this->assertFalse(CustomerAcquirerQuery::isEligible($this->company->id, $foreign->id));
        $this->assertTrue(CustomerAcquirerQuery::isEligible($this->otherCompany->id, $foreign->id));
        $this->assertFalse(CustomerAcquirerQuery::isEligible($this->company->id, 987654));
    }

    public function test_options_are_sorted_by_name_and_expose_only_display_fields(): void
    {
        $zed = $this->employee('manager', attributes: ['name' => 'Zed Seller'], profile: ['employee_number' => 'EMP-Z']);
        $amy = $this->employee('staff', attributes: ['name' => 'Amy Seller'], profile: ['employee_number' => 'EMP-A']);
        $this->employee('technician');
        $this->employee('manager', $this->otherCompany, ['name' => 'Foreign Seller']);

        $this->assertSame([
            ['id' => $amy->public_id, 'name' => 'Amy Seller', 'is_active' => true, 'employee_number' => 'EMP-A'],
            ['id' => $zed->public_id, 'name' => 'Zed Seller', 'is_active' => true, 'employee_number' => 'EMP-Z'],
        ], CustomerAcquirerQuery::options($this->company->id));
    }

    public function test_filter_options_add_historical_acquirers_without_leaking_foreign_users(): void
    {
        $eligible = $this->employee('manager', attributes: ['name' => 'Eligible Seller'], profile: ['employee_number' => 'EMP-E']);
        $former = $this->employee('staff', attributes: ['name' => 'Former Seller', 'is_active' => false]);
        $foreignSeller = $this->employee('manager', $this->otherCompany, ['name' => 'Foreign Seller']);

        $this->customerAcquiredBy($eligible);
        $this->customerAcquiredBy($eligible);
        $this->customerAcquiredBy($former);
        $this->customerAcquiredBy($foreignSeller, company: $this->otherCompany);

        $options = CustomerAcquirerQuery::filterOptions($this->company->id);

        $this->assertSame(
            [
                ['id' => $eligible->public_id, 'name' => 'Eligible Seller', 'is_active' => true],
                ['id' => $former->public_id, 'name' => 'Former Seller', 'is_active' => false],
            ],
            array_map(fn (array $option): array => array_intersect_key($option, array_flip(['id', 'name', 'is_active'])), $options),
        );
    }

    public function test_default_id_is_the_creator_only_when_eligible(): void
    {
        $manager = $this->employee('manager');
        $adminWithoutProfile = $this->employee('admin', profile: false);
        $technician = $this->employee('technician');

        $this->assertSame($manager->id, CustomerAcquirerQuery::defaultId($manager));
        $this->assertNull(CustomerAcquirerQuery::defaultId($adminWithoutProfile));
        $this->assertNull(CustomerAcquirerQuery::defaultId($technician));
    }

    public function test_validation_rule_accepts_only_eligible_users_of_the_current_company(): void
    {
        $actor = $this->employee('manager');
        $eligible = $this->employee('staff');
        $adminWithoutProfile = $this->employee('admin', profile: false);
        $technician = $this->employee('technician');
        $inactive = $this->employee('manager', attributes: ['is_active' => false]);
        $foreign = $this->employee('manager', $this->otherCompany);
        $this->actingAs($actor);

        $this->assertTrue($this->validates($eligible->id));
        foreach ([$adminWithoutProfile, $technician, $inactive, $foreign] as $ineligible) {
            $this->assertFalse($this->validates($ineligible->id), "User {$ineligible->id} must not validate.");
        }

        $this->assertSame(
            $this->firstError($foreign->id),
            $this->firstError(987654),
            'A foreign-company id must be indistinguishable from an unknown id.',
        );
    }

    private function validates(int $userId): bool
    {
        return Validator::make(
            ['acquired_by_user_id' => $userId],
            ['acquired_by_user_id' => ['integer', new IsEligibleCustomerAcquirer()]],
        )->passes();
    }

    private function firstError(int $userId): ?string
    {
        return Validator::make(
            ['acquired_by_user_id' => $userId],
            ['acquired_by_user_id' => ['integer', new IsEligibleCustomerAcquirer()]],
        )->errors()->first('acquired_by_user_id');
    }
}
