<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\OrganizationUnit;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Reporting\Services\TechnicianReportAccess;
use Tests\TestCase;

/**
 * `canTechnicianReport` (shared by the Reporting module) must equal what the report route answers.
 */
class TechnicianReportFlagTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
        $this->company = Company::factory()->create(['is_active' => true]);
    }

    public function test_technician_gets_true_and_the_report_opens(): void
    {
        $technician = $this->user('technician');
        EmployeeProfile::factory()->create(['company_id' => $this->company->id, 'user_id' => $technician->id, 'status' => 'active']);

        $this->assertFlagMatchesRoute($technician, true);
    }

    public function test_admin_gets_true(): void
    {
        $this->assertFlagMatchesRoute($this->user('admin'), true);
    }

    public function test_manager_with_an_active_profile_in_an_organization_gets_true(): void
    {
        $manager = $this->user('manager');
        $unit = OrganizationUnit::factory()->create(['company_id' => $this->company->id, 'path' => 'OPS', 'is_active' => true]);
        EmployeeProfile::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $manager->id,
            'organization_id' => $unit->id,
            'status' => 'active',
        ]);

        $this->assertFlagMatchesRoute($manager, true);
    }

    public function test_manager_without_an_organization_gets_false_and_the_report_is_forbidden(): void
    {
        $this->assertFlagMatchesRoute($this->user('manager'), false);
    }

    public function test_staff_gets_false(): void
    {
        $this->assertFlagMatchesRoute($this->user('staff'), false);
    }

    public function test_user_without_a_company_gets_false(): void
    {
        $user = $this->user('technician');
        $user->forceFill(['company_id' => null])->save();

        $this->assertFalse(TechnicianReportAccess::allows($user->fresh()));
        $this->assertFalse(TechnicianReportAccess::allows(null));
    }

    public function test_guest_pages_never_report_true(): void
    {
        $this->get(route('login'))
            ->assertInertia(fn (Assert $page) => $page->where('canTechnicianReport', false));
    }

    private function user(string $role): User
    {
        $user = User::factory()->create([
            'company_id' => $this->company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function assertFlagMatchesRoute(User $user, bool $expected): void
    {
        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canTechnicianReport', $expected));

        $response = $this->get(route('admin.reports.technician'));
        $expected ? $response->assertOk() : $response->assertForbidden();
    }
}
