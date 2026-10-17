<?php

namespace Tests\Feature\Tenant;

use App\Models\Core\Customer;
use App\Models\Core\Vehicle;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyService;
use App\Support\PublicId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;

class CompanySwitchTest extends TestCase
{
    use BuildsCompanyGroup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGroup();
    }

    // ---------------------------------------------------------------------------------------------
    // Two-company isolation after switching
    // ---------------------------------------------------------------------------------------------

    public function test_switching_moves_every_page_to_the_chosen_company_and_back(): void
    {
        $user = $this->groupUser($this->head, 'admin', $this->subA);
        $headCustomer = Customer::factory()->create(['company_id' => $this->head->id, 'name' => 'Head Customer']);
        $subCustomer = Customer::factory()->create(['company_id' => $this->subA->id, 'name' => 'Sub A Customer']);

        $this->actingAs($user)->get(route('admin.customers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.name', 'Head Customer')
                ->where('company.id', $this->head->public_id));
        $this->get(route('admin.customers.show', $subCustomer))->assertNotFound();

        $this->post(route('admin.company.switch'), ['company_id' => $this->subA->public_id])
            ->assertRedirect(route('admin.dashboard'));

        $this->get(route('admin.customers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.name', 'Sub A Customer')
                ->where('company.id', $this->subA->public_id));
        $this->get(route('admin.customers.show', $subCustomer))->assertOk();
        $this->get(route('admin.customers.show', $headCustomer))->assertNotFound();

        $this->post(route('admin.company.switch'), ['company_id' => $this->head->public_id])
            ->assertRedirect(route('admin.dashboard'));

        $this->get(route('admin.customers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('customers.data.0.name', 'Head Customer')
                ->where('company.id', $this->head->public_id));
    }

    public function test_rows_written_after_switching_belong_to_the_switched_company(): void
    {
        $user = $this->groupUser($this->head, 'admin', $this->subA);

        $this->actingAs($user)->post(route('admin.company.switch'), ['company_id' => $this->subA->public_id]);
        $this->post(route('admin.vehicles.store'), ['plate_number' => 'B 9999 SUB'])->assertRedirect();

        $this->assertSame(
            $this->subA->id,
            Vehicle::withoutCompany()->where('plate_number', 'B 9999 SUB')->value('company_id'),
        );
    }

    // ---------------------------------------------------------------------------------------------
    // Authorization of the switch itself
    // ---------------------------------------------------------------------------------------------

    public function test_switching_to_a_company_without_membership_is_forbidden_and_changes_nothing(): void
    {
        $user = $this->groupUser($this->head, 'admin', $this->subA);
        Customer::factory()->create(['company_id' => $this->subB->id, 'name' => 'Sub B Customer']);

        $this->actingAs($user)
            ->post(route('admin.company.switch'), ['company_id' => $this->subB->public_id])
            ->assertForbidden();

        $this->assertNull(session(CompanyService::SESSION_KEY));
        $this->get(route('admin.customers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('customers.data', 0)->where('company.id', $this->head->public_id));
        $this->assertSame(0, Activity::query()->where('description', 'company_switched')->count());
    }

    public function test_the_head_derived_view_of_subsidiaries_is_not_a_right_to_switch_into_them(): void
    {
        $headAdmin = $this->groupUser($this->head);

        $this->actingAs($headAdmin)
            ->post(route('admin.company.switch'), ['company_id' => $this->subA->public_id])
            ->assertForbidden();
    }

    public function test_a_forged_or_unknown_company_id_is_forbidden_without_telling_them_apart(): void
    {
        $user = $this->groupUser($this->head, 'admin', $this->subA);
        $unknown = PublicId::new();

        $this->actingAs($user);
        foreach ([$unknown, $this->outsider->public_id, $this->subA->id, $this->outsider->id, 0, 'abc'] as $forged) {
            $this->post(route('admin.company.switch'), ['company_id' => $forged])->assertForbidden();
        }
        $this->post(route('admin.company.switch'), [])->assertForbidden();
        $this->assertNull(session(CompanyService::SESSION_KEY));
    }

    public function test_switching_needs_the_company_switch_permission_on_top_of_membership(): void
    {
        $staff = $this->groupUser($this->head, 'staff', $this->subA);

        $this->actingAs($staff)
            ->post(route('admin.company.switch'), ['company_id' => $this->subA->public_id])
            ->assertForbidden();

        $manager = $this->groupUser($this->head, 'manager', $this->subA);

        $this->actingAs($manager)
            ->post(route('admin.company.switch'), ['company_id' => $this->subA->public_id])
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_switching_to_an_inactive_company_is_forbidden(): void
    {
        $user = $this->groupUser($this->head, 'admin', $this->subA);
        $this->subA->forceFill(['is_active' => false])->save();

        $this->actingAs($user)
            ->post(route('admin.company.switch'), ['company_id' => $this->subA->public_id])
            ->assertForbidden();
    }

    public function test_a_guest_cannot_switch(): void
    {
        $this->post(route('admin.company.switch'), ['company_id' => $this->subA->public_id])
            ->assertRedirect(route('login'));
    }

    public function test_every_switch_is_audited_under_the_target_company(): void
    {
        $user = $this->groupUser($this->head, 'admin', $this->subA);

        $this->actingAs($user)->post(route('admin.company.switch'), ['company_id' => $this->subA->public_id]);

        $entry = Activity::query()->where('log_name', 'company')->where('description', 'company_switched')->sole();
        $this->assertSame($user->id, $entry->causer_id);
        $this->assertSame($this->head->id, $entry->properties['from_company_id']);
        $this->assertSame($this->subA->id, $entry->properties['to_company_id']);
        $this->assertSame($this->subA->id, (int) $entry->company_id);
    }

    // ---------------------------------------------------------------------------------------------
    // Session lifecycle
    // ---------------------------------------------------------------------------------------------

    public function test_a_membership_revoked_mid_session_falls_back_to_the_home_company(): void
    {
        $user = $this->groupUser($this->head, 'admin', $this->subA);
        $this->actingAs($user)->post(route('admin.company.switch'), ['company_id' => $this->subA->public_id]);
        $this->assertSame($this->subA->id, session(CompanyService::SESSION_KEY));

        app(CompanyMembershipService::class)->revoke($user, $this->subA);

        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('company.id', $this->head->public_id));
        $this->assertNull(session(CompanyService::SESSION_KEY));
    }

    public function test_a_company_deactivated_mid_session_falls_back_to_the_home_company(): void
    {
        $user = $this->groupUser($this->head, 'admin', $this->subA);
        $this->actingAs($user)->post(route('admin.company.switch'), ['company_id' => $this->subA->public_id]);

        $this->subA->forceFill(['is_active' => false])->save();

        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('company.id', $this->head->public_id));
    }

    public function test_logging_in_starts_in_the_default_company_not_in_a_stale_active_company(): void
    {
        $user = $this->groupUser($this->head, 'admin', $this->subA);

        $this->withSession([CompanyService::SESSION_KEY => $this->subA->id])
            ->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect();

        $this->assertNull(session(CompanyService::SESSION_KEY));
    }

    // ---------------------------------------------------------------------------------------------
    // Shared Inertia props for the switcher
    // ---------------------------------------------------------------------------------------------

    public function test_shared_props_describe_the_active_company_and_the_memberships(): void
    {
        $user = $this->groupUser($this->head, 'admin', $this->subA);

        $this->actingAs($user)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.company_id', $this->head->public_id)
                ->where('auth.user.home_company_id', $this->head->public_id)
                ->where('company.id', $this->head->public_id)
                ->where('companyHierarchy.can_consolidate', true)
                ->where('companyHierarchy.parent_company_id', null)
                ->where('canSwitch', true)
                ->where('companies', [
                    ['id' => $this->head->public_id, 'name' => 'Group Head', 'code' => 'HDQ', 'is_current' => true, 'is_default' => true],
                    ['id' => $this->subA->public_id, 'name' => 'Sub A', 'code' => 'SBA', 'is_current' => false, 'is_default' => false],
                ]));

        $this->post(route('admin.company.switch'), ['company_id' => $this->subA->public_id]);

        $this->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.company_id', $this->subA->public_id)
                ->where('auth.user.home_company_id', $this->head->public_id)
                ->where('company.id', $this->subA->public_id)
                ->where('companyHierarchy.can_consolidate', false)
                ->where('companyHierarchy.parent_company_id', $this->head->public_id)
                ->where('companies.0.is_current', false)
                ->where('companies.1.is_current', true));
    }

    public function test_a_single_company_user_sees_no_switcher(): void
    {
        $user = $this->groupUser($this->outsider);

        $this->actingAs($user)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('company.id', $this->outsider->public_id)
                ->where('companyHierarchy.can_consolidate', false)
                ->where('canSwitch', false)
                ->where('companies', []));
    }

    public function test_a_multi_member_user_without_the_switch_permission_sees_no_switcher(): void
    {
        $staff = $this->groupUser($this->head, 'staff', $this->subA);

        $this->actingAs($staff)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canSwitch', false)->where('companies', []));
    }
}
