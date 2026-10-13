<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Company;
use App\Models\Core\EmployeeEvaluation;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\OrganizationUnit;
use App\Models\User;
use App\Services\Core\CompanyService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Modules\Reporting\Queries\TechnicianPerformanceQuery;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\WithoutAppOnlyRedirect;

class TechnicianPerformanceAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
    }

    public function test_report_routes_keep_their_distinct_permissions(): void
    {
        $technicianRoute = app('router')->getRoutes()->getByName('admin.reports.technician');
        $this->assertNotNull($technicianRoute);
        $this->assertContains('can:report.technician', $technicianRoute->gatherMiddleware());
        $this->assertNotContains('can:report.view', $technicianRoute->gatherMiddleware());

        $businessRoute = app('router')->getRoutes()->getByName('admin.reports.business');
        $this->assertNotNull($businessRoute);
        $this->assertContains('can:report.business.view', $businessRoute->gatherMiddleware());
        $this->assertNotContains('can:report.view', $businessRoute->gatherMiddleware());
    }

    public function test_technician_can_view_own_report_when_target_is_omitted(): void
    {
        [$company, $technician] = $this->makeUserWithRole('technician');
        $otherTechnician = $this->makeTechnician($company);
        CompanyService::useCompanyForTests($company->id);

        $this->actingAs($technician)
            ->get(route('admin.reports.technician'))
            ->assertOk()
            ->assertInertia(fn (AssertableJson $page) => $page
                ->where('data.technician_id', $technician->id)
                ->where('technicians.data', function ($technicians) use ($technician, $otherTechnician): bool {
                    $ids = collect($technicians)->pluck('id');

                    return $ids->contains($technician->id) && ! $ids->contains($otherTechnician->id);
                }));
    }

    public function test_technician_cannot_view_another_target(): void
    {
        [$company, $technician] = $this->makeUserWithRole('technician');
        $otherTechnician = $this->makeTechnician($company);
        CompanyService::useCompanyForTests($company->id);

        $this->actingAs($technician)
            ->get(route('admin.reports.technician', ['technician_id' => $otherTechnician->id]))
            ->assertForbidden();
    }

    public function test_staff_and_customer_do_not_receive_technician_report_permission(): void
    {
        foreach (['staff', 'customer'] as $role) {
            [$company, $user] = $this->makeUserWithRole($role);
            CompanyService::useCompanyForTests($company->id);

            $this->actingAs($user)
                ->get(route('admin.reports.technician'))
                ->assertForbidden();
        }
    }

    public function test_admin_cannot_select_a_cross_company_technician(): void
    {
        [$company, $admin] = $this->makeUserWithRole('admin');
        $sameCompanyTechnician = $this->makeTechnician($company);
        [$foreignCompany, $foreignTechnician] = $this->makeUserWithRole('technician');
        CompanyService::useCompanyForTests($company->id);

        $this->actingAs($admin)
            ->get(route('admin.reports.technician', ['technician_id' => $sameCompanyTechnician->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableJson $page) => $page
                ->where('data.technician_id', $sameCompanyTechnician->id));

        $this->get(route('admin.reports.technician', ['technician_id' => $foreignTechnician->id]))
            ->assertForbidden();

        $this->get(route('admin.reports.technician'))
            ->assertOk()
            ->assertInertia(fn (AssertableJson $page) => $page
                ->where('technicians.data', function ($technicians) use ($foreignTechnician): bool {
                    return ! collect($technicians)->pluck('id')->contains($foreignTechnician->id);
                }));
    }

    public function test_manager_can_select_same_organization_and_descendant_only(): void
    {
        [$company, $manager] = $this->makeUserWithRole('manager');
        $managerOrg = OrganizationUnit::factory()->create([
            'company_id' => $company->id,
            'path' => 'OPS',
            'is_active' => true,
        ]);
        $descendantOrg = OrganizationUnit::factory()->create([
            'company_id' => $company->id,
            'parent_id' => $managerOrg->id,
            'path' => 'OPS > FIELD',
            'is_active' => true,
        ]);
        $otherOrg = OrganizationUnit::factory()->create([
            'company_id' => $company->id,
            'path' => 'SALES',
            'is_active' => true,
        ]);
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $manager->id,
            'organization_id' => $managerOrg->id,
            'status' => 'active',
        ]);
        $sameOrg = $this->makeTechnician($company, $managerOrg);
        $descendant = $this->makeTechnician($company, $descendantOrg);
        $other = $this->makeTechnician($company, $otherOrg);
        CompanyService::useCompanyForTests($company->id);

        $this->actingAs($manager)
            ->get(route('admin.reports.technician'))
            ->assertOk()
            ->assertInertia(fn (AssertableJson $page) => $page
                ->where('technicians.data', function ($technicians) use ($sameOrg, $descendant, $other): bool {
                    $ids = collect($technicians)->pluck('id');

                    return $ids->contains($sameOrg->id)
                        && $ids->contains($descendant->id)
                        && ! $ids->contains($other->id);
                }));
    }

    public function test_manager_unit_code_with_like_metacharacters_keeps_narrow_scope(): void
    {
        [$company, $manager] = $this->makeUserWithRole('manager');
        $managerOrg = OrganizationUnit::factory()->create([
            'company_id' => $company->id,
            'code' => 'FIELD_TEAM',
            'path' => 'FIELD_TEAM',
            'is_active' => true,
        ]);
        $descendantOrg = OrganizationUnit::factory()->create([
            'company_id' => $company->id,
            'parent_id' => $managerOrg->id,
            'code' => 'NIGHT',
            'path' => 'FIELD_TEAM > NIGHT',
            'is_active' => true,
        ]);
        // Sibling branch whose codes match the actor path only when `_` is treated as
        // a LIKE wildcard; it must stay out of the manager's scope.
        $lookalikeOrg = OrganizationUnit::factory()->create([
            'company_id' => $company->id,
            'code' => 'FIELDXTEAM',
            'path' => 'FIELDXTEAM',
            'is_active' => true,
        ]);
        $lookalikeDescendantOrg = OrganizationUnit::factory()->create([
            'company_id' => $company->id,
            'parent_id' => $lookalikeOrg->id,
            'code' => 'NIGHTX',
            'path' => 'FIELDXTEAM > NIGHTX',
            'is_active' => true,
        ]);
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $manager->id,
            'organization_id' => $managerOrg->id,
            'status' => 'active',
        ]);
        $sameOrg = $this->makeTechnician($company, $managerOrg);
        $descendant = $this->makeTechnician($company, $descendantOrg);
        $lookalike = $this->makeTechnician($company, $lookalikeDescendantOrg);
        CompanyService::useCompanyForTests($company->id);

        $this->actingAs($manager)
            ->get(route('admin.reports.technician'))
            ->assertOk()
            ->assertInertia(fn (AssertableJson $page) => $page
                ->where('technicians.data', function ($technicians) use ($sameOrg, $descendant, $lookalike): bool {
                    $ids = collect($technicians)->pluck('id');

                    return $ids->contains($sameOrg->id)
                        && $ids->contains($descendant->id)
                        && ! $ids->contains($lookalike->id);
                }));

        $this->actingAs($manager)
            ->get(route('admin.reports.technician', ['technician_id' => $lookalike->id]))
            ->assertForbidden();
    }

    public function test_manager_without_valid_organization_fails_closed(): void
    {
        [$company, $manager] = $this->makeUserWithRole('manager');
        $technician = $this->makeTechnician($company);
        CompanyService::useCompanyForTests($company->id);

        $this->actingAs($manager)
            ->get(route('admin.reports.technician'))
            ->assertForbidden();
    }

    public function test_manager_with_malformed_organization_path_fails_closed(): void
    {
        [$company, $manager] = $this->makeUserWithRole('manager');
        $organization = OrganizationUnit::factory()->create([
            'company_id' => $company->id,
            'path' => 'OPS >',
            'is_active' => true,
        ]);
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $manager->id,
            'organization_id' => $organization->id,
            'status' => 'active',
        ]);
        CompanyService::useCompanyForTests($company->id);

        $this->actingAs($manager)
            ->get(route('admin.reports.technician'))
            ->assertForbidden();
    }

    public function test_inactive_target_profile_is_denied_and_not_listed(): void
    {
        [$company, $admin] = $this->makeUserWithRole('admin');
        CompanyService::useCompanyForTests($company->id);
        $target = $this->makeTechnician($company);
        $target->employeeProfile()->update(['status' => 'inactive']);

        $this->actingAs($admin)
            ->get(route('admin.reports.technician', ['technician_id' => $target->id]))
            ->assertForbidden();

        $this->get(route('admin.reports.technician'))
            ->assertOk()
            ->assertInertia(fn (AssertableJson $page) => $page
                ->where('technicians.data', function ($technicians) use ($target): bool {
                    return ! collect($technicians)->pluck('id')->contains($target->id);
                }));
    }

    public function test_historical_technician_metrics_include_both_date_boundaries_but_not_active_snapshot(): void
    {
        [$company, $admin] = $this->makeUserWithRole('admin');
        $technician = $this->makeTechnician($company);
        CompanyService::useCompanyForTests($company->id);
        $category = TicketCategory::query()->create([
            'company_id' => $company->id,
            'name' => 'SLA',
            'code' => 'SLA-'.fake()->unique()->numberBetween(1, 99999),
            'default_sla_hours' => 2,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        WorkOrderFactory::new()->create([
            'company_id' => $company->id,
            'assigned_to' => $technician->id,
            'created_by' => $admin->id,
            'status' => 'completed',
            'type' => 'maintenance',
            'started_at' => '2025-12-31 23:39:59',
            'completed_at' => '2025-12-31 23:59:59',
        ]);
        foreach (['2026-01-01 00:00:00', '2026-01-31 23:59:59'] as $completedAt) {
            WorkOrderFactory::new()->create([
                'company_id' => $company->id,
                'assigned_to' => $technician->id,
                'created_by' => $admin->id,
                'status' => 'completed',
                'type' => 'installation',
                'started_at' => Carbon::parse($completedAt)->subMinutes(20),
                'completed_at' => $completedAt,
            ]);
        }
        WorkOrderFactory::new()->create([
            'company_id' => $company->id,
            'assigned_to' => $technician->id,
            'created_by' => $admin->id,
            'status' => 'completed',
            'completed_at' => '2026-02-01 00:00:00',
        ]);
        WorkOrderFactory::new()->create([
            'company_id' => $company->id,
            'assigned_to' => $technician->id,
            'created_by' => $admin->id,
            'status' => 'in_progress',
        ]);

        foreach (['2026-01-01 00:00:00', '2026-01-31 23:59:59', '2026-02-01 00:00:00'] as $resolvedAt) {
            Ticket::factory()->create([
                'company_id' => $company->id,
                'category_id' => $category->id,
                'assigned_to' => $technician->id,
                'created_by' => $admin->id,
                'status' => 'resolved',
                'resolved_at' => $resolvedAt,
                'first_response_at' => Carbon::parse($resolvedAt)->subMinutes(10),
                'sla_deadline' => Carbon::parse($resolvedAt)->addMinute(),
            ]);
        }
        Ticket::factory()->create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'assigned_to' => $technician->id,
            'created_by' => $admin->id,
            'status' => 'open',
        ]);
        EmployeeEvaluation::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $technician->id,
            'score' => 4,
            'evaluated_at' => '2026-01-31 23:59:59',
        ]);
        EmployeeEvaluation::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $technician->id,
            'score' => 1,
            'evaluated_at' => '2026-02-01 00:00:00',
        ]);

        $metrics = TechnicianPerformanceQuery::execute($technician->id, '2026-01-01', '2026-01-31', $company->id);

        $this->assertSame(2, $metrics['spk_completed']);
        $this->assertSame(2, $metrics['tickets_resolved']);
        $this->assertSame(2, $metrics['spk_by_type']['installation'] ?? 0);
        $this->assertSame(2, $metrics['tickets_by_category'][$category->name] ?? 0);
        $this->assertSame(2, $metrics['active_workload']);
        $this->assertSame(4.0, $metrics['avg_score']);
    }

    /** @return array{0: Company, 1: User} */
    private function makeUserWithRole(string $role): array
    {
        $company = Company::factory()->create(['is_active' => true, 'timezone' => 'UTC']);
        $user = User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->assignRole(Role::findByName($role));

        if ($role === 'technician') {
            EmployeeProfile::factory()->create([
                'company_id' => $company->id,
                'user_id' => $user->id,
                'status' => 'active',
            ]);
        }

        return [$company, $user];
    }

    private function makeTechnician(Company $company, ?OrganizationUnit $organization = null): User
    {
        $technician = User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $technician->assignRole(Role::findByName('technician'));
        EmployeeProfile::factory()->create([
            'company_id' => $company->id,
            'user_id' => $technician->id,
            'organization_id' => $organization?->id,
            'status' => 'active',
        ]);

        return $technician;
    }
}
