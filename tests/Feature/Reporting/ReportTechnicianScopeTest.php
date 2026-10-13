<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Company;
use App\Models\Core\EmployeeEvaluation;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\OrganizationUnit;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\BuildsReportingWorld;
use Tests\Traits\CreatesReportingUsers;
use Tests\Traits\ParsesCsvDownloads;

/**
 * Export and drill-down of Technician Performance reuse the report screen's own authorization:
 * admin any technician of the company, a manager the technicians of their unit subtree, a
 * technician only themself, nobody another company's. The lists tie out to the screen's figures.
 * Handlers of tickets and SPK only ever see the rows assigned to them.
 */
class ReportTechnicianScopeTest extends TestCase
{
    use BuildsReportingWorld;
    use CreatesReportingUsers;
    use ParsesCsvDownloads;
    use RefreshDatabase;

    private const JANUARY = ['date_from' => '2026-01-01', 'date_to' => '2026-01-31'];

    private User $admin;

    private User $manager;

    private User $inScope;

    private User $sameUnit;

    private User $outOfScope;

    private User $foreign;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->admin = $this->userWithRole('admin');
        $this->companyId = (int) $this->admin->company_id;
        // The edge fixtures are pinned to calendar days of the storage timezone (UTC).
        Company::query()->whereKey($this->companyId)->update(['timezone' => 'UTC']);

        $ops = $this->unit('OPS');
        $field = $this->unit('OPS > FIELD', $ops);
        $sales = $this->unit('SALES');

        $this->manager = $this->member('manager', $ops);
        $this->inScope = $this->member('technician', $field);
        $this->sameUnit = $this->member('technician', $ops);
        $this->outOfScope = $this->member('technician', $sales);
        $this->foreign = $this->member('technician', null, (int) $this->userWithRole('admin')->company_id);

        $this->seedTechnicianWorld();
    }

    // ---------------------------------------------------------------------------------------------
    // Scope: the screen's rule, for the export and every list
    // ---------------------------------------------------------------------------------------------

    public function test_a_manager_exports_and_drills_only_technicians_of_their_unit_subtree(): void
    {
        $this->actingAs($this->manager);

        foreach ([$this->inScope, $this->sameUnit] as $technician) {
            $this->get(route('admin.reports.technician.export', ['technician_id' => $technician->id]))->assertOk();
            $this->drill($technician, 'spk_completed')->assertOk();
        }

        foreach ([$this->outOfScope, $this->foreign] as $technician) {
            $this->get(route('admin.reports.technician.export', ['technician_id' => $technician->id]))->assertForbidden();
            $this->drill($technician, 'spk_completed')->assertForbidden();
            $this->get(route('admin.reports.technician.drill.export', ['metric' => 'spk_completed', 'technician_id' => $technician->id]))->assertForbidden();
        }
    }

    public function test_like_wildcards_in_a_unit_code_never_widen_the_managers_subtree(): void
    {
        // A manager whose unit is literally "OP_": as a LIKE pattern "_" would match the "S" of "OPS",
        // pulling the technicians of "OPS > FIELD" into the subtree.
        $wildManager = $this->member('manager', $this->unit('OP_'));
        $this->actingAs($wildManager);

        foreach ([$this->inScope, $this->sameUnit] as $technician) {
            $this->get(route('admin.reports.technician.export', ['technician_id' => $technician->id]))->assertForbidden();
            $this->drill($technician, 'spk_completed')->assertForbidden();
        }
    }

    public function test_a_technician_with_export_reaches_only_their_own_rows(): void
    {
        $role = Role::firstOrCreate(['name' => 'tech-with-export', 'guard_name' => 'web']);
        $role->givePermissionTo(['report.export']);
        $this->inScope->assignRole($role);
        $this->actingAs($this->inScope);

        // No target named: the technician themself, on the report, the lists and the files.
        $this->get(route('admin.reports.technician.export'))->assertOk();
        $own = $this->get(route('admin.reports.technician.drill', ['metric' => 'spk_completed', ...self::JANUARY]));
        $this->assertSame(2, $own->inertiaProps('tie_out.rows'));
        $this->assertTrue($own->inertiaProps('tie_out.matches'));

        foreach ([$this->sameUnit, $this->outOfScope, $this->foreign] as $other) {
            $this->get(route('admin.reports.technician.export', ['technician_id' => $other->id]))->assertForbidden();
            $this->drill($other, 'spk_completed')->assertForbidden();
        }
    }

    public function test_the_seeded_technician_role_drills_but_cannot_export(): void
    {
        $this->actingAs($this->inScope);

        $this->drill($this->inScope, 'spk_completed')->assertOk();
        $this->get(route('admin.reports.technician.export'))->assertForbidden();
    }

    public function test_an_admin_names_a_technician_of_the_company_and_never_a_foreign_one(): void
    {
        $this->actingAs($this->admin);

        $this->drill($this->inScope, 'spk_completed')->assertOk();
        $this->drill($this->foreign, 'spk_completed')->assertForbidden();
        $this->get(route('admin.reports.technician.drill', ['metric' => 'spk_completed']))->assertNotFound();
    }

    // ---------------------------------------------------------------------------------------------
    // Lists tie out to the figures of the technician
    // ---------------------------------------------------------------------------------------------

    public function test_technician_lists_tie_out_to_the_screen(): void
    {
        $this->actingAs($this->admin);
        $screen = $this->get(route('admin.reports.technician', ['technician_id' => $this->inScope->id, ...self::JANUARY]))->assertOk();

        $this->assertSame(2, $screen->inertiaProps('data.spk_completed'));
        $this->assertSame(2, $screen->inertiaProps('data.tickets_resolved'));
        $this->assertSame(2, $screen->inertiaProps('data.active_workload'));

        $counts = [
            'spk_completed' => 2,
            'tickets_resolved' => 2,
            'avg_spk_minutes' => 1,         // only the SPK that was started counts
            'avg_ticket_minutes' => 2,
            'sla_compliance_pct' => 2,
            'avg_frt_minutes' => 2,
            'avg_score' => 2,
            'avg_customer_rating' => 1,
        ];
        foreach ($counts as $metric => $rows) {
            $response = $this->drill($this->inScope, $metric, self::JANUARY);

            $this->assertSame($rows, $response->inertiaProps('tie_out.rows'), $metric);
        }

        $share = $this->drill($this->inScope, 'sla_compliance_pct', self::JANUARY);
        $this->assertSame(1, $share->inertiaProps('tie_out.compliant'));
        $this->assertEquals(50.0, $share->inertiaProps('tie_out.figure'));
        $this->assertTrue($share->inertiaProps('tie_out.matches'));

        foreach (['spk_completed', 'tickets_resolved'] as $metric) {
            $this->assertTrue($this->drill($this->inScope, $metric, self::JANUARY)->inertiaProps('tie_out.matches'), $metric);
        }

        $byType = $this->drill($this->inScope, 'spk_by_type', [...self::JANUARY, 'group' => 'installation']);
        $this->assertSame(2, $byType->inertiaProps('tie_out.rows'));
        $this->assertTrue($byType->inertiaProps('tie_out.matches'));

        $byCategory = $this->drill($this->inScope, 'tickets_by_category', [...self::JANUARY, 'group' => 'Support']);
        $this->assertSame(2, $byCategory->inertiaProps('tie_out.rows'));
        $this->assertTrue($byCategory->inertiaProps('tie_out.matches'));
    }

    public function test_active_workload_ties_out_across_its_spk_and_ticket_lists(): void
    {
        $this->actingAs($this->admin);

        $spk = $this->drill($this->inScope, 'active_workload_spk');
        $tickets = $this->drill($this->inScope, 'active_workload_tickets');

        $this->assertSame(1, $spk->inertiaProps('tie_out.rows'));
        $this->assertSame(1, $tickets->inertiaProps('tie_out.rows'));
        $this->assertSame(2, $spk->inertiaProps('tie_out.figure'), 'the workload is SPK and tickets together');
        $this->assertSame(['label' => 'tickets', 'rows' => 1], $spk->inertiaProps('tie_out.companion'));
        $this->assertTrue($spk->inertiaProps('tie_out.matches'));
        $this->assertTrue($tickets->inertiaProps('tie_out.matches'));
    }

    public function test_the_technician_summary_file_matches_the_screen(): void
    {
        $this->actingAs($this->admin);
        $query = ['technician_id' => $this->inScope->id, ...self::JANUARY];

        $screen = $this->get(route('admin.reports.technician', $query))->assertOk();
        $figures = $this->csvFigures($this->get(route('admin.reports.technician.export', $query)));

        $this->assertEquals(2, (float) $figures['SPK Completed']);
        $this->assertEquals(2, (float) $figures['Tickets Resolved']);
        $this->assertEquals(2, (float) $figures['Active Workload']);
        $this->assertEquals(3.0, (float) $figures['Avg Evaluation Score']);     // scores 4 and 2 in January; the 1 of February is out
        $this->assertEquals(2, (float) $figures['SPK by Type / installation']);
        $this->assertEquals(2, (float) $figures['Tickets by Category / Support']);

        $this->assertEquals($screen->inertiaProps('data.avg_score'), (float) $figures['Avg Evaluation Score']);
        $this->assertEquals($screen->inertiaProps('data.sla_compliance_pct'), (float) $figures['SLA Compliance']);
    }

    // ---------------------------------------------------------------------------------------------
    // Handlers see only their own rows
    // ---------------------------------------------------------------------------------------------

    public function test_a_ticket_handler_only_lists_tickets_assigned_to_them(): void
    {
        $handler = $this->userIn(['report.sla.view', 'ticket.view', 'ticket.resolve']);
        $support = $this->worldCategory($this->companyId, 'Handled', 'HND');
        $mine = $this->worldTicket($this->companyId, $support, '2026-01-20 08:00:00', null, '2026-01-20 09:00:00', '2026-01-20 10:00:00', null, $handler->id);

        $this->actingAs($handler);
        $response = $this->get(route('admin.reports.sla.drill', ['metric' => 'total_resolved', ...self::JANUARY]));

        $this->assertSame(1, $response->inertiaProps('tie_out.rows'));
        $this->assertSame($mine->id, $response->inertiaProps('rows.data.0.id'));
        $this->assertTrue($response->inertiaProps('tie_out.restricted'));
        $this->assertNull($response->inertiaProps('tie_out.matches'), 'a handler sees a part of the figure, so it cannot be proven');

        // Somebody who may assign sees the whole figure.
        $this->actingAs($this->userIn(['report.sla.view', 'ticket.view', 'ticket.assign']));
        $all = $this->get(route('admin.reports.sla.drill', ['metric' => 'total_resolved', ...self::JANUARY]));
        $this->assertFalse($all->inertiaProps('tie_out.restricted'));
        $this->assertGreaterThan(1, $all->inertiaProps('tie_out.rows'));
        $this->assertTrue($all->inertiaProps('tie_out.matches'));
    }

    public function test_an_spk_handler_only_lists_work_orders_assigned_to_them(): void
    {
        $handler = $this->userIn(['report.business.view', 'spk.view', 'spk.start']);
        $mine = $this->worldWorkOrder($this->companyId, 'installation', 'completed', '2026-01-20 12:00:00', $handler->id);

        $this->actingAs($handler);
        $response = $this->get(route('admin.reports.business.drill', ['metric' => 'installation_count', ...self::JANUARY]));

        $this->assertSame(1, $response->inertiaProps('tie_out.rows'));
        $this->assertSame($mine->id, $response->inertiaProps('rows.data.0.id'));
        $this->assertTrue($response->inertiaProps('tie_out.restricted'));
    }

    public function test_an_inactive_handler_lists_nothing(): void
    {
        $handler = $this->userIn(['report.sla.view', 'ticket.view', 'ticket.resolve']);
        $category = $this->worldCategory($this->companyId, 'Handled', 'HND');
        $this->worldTicket($this->companyId, $category, '2026-01-20 08:00:00', null, '2026-01-20 09:00:00', '2026-01-20 10:00:00', null, $handler->id);
        $handler->forceFill(['is_active' => false])->save();

        $this->actingAs($handler);
        $response = $this->get(route('admin.reports.sla.drill', ['metric' => 'total_resolved', ...self::JANUARY]));

        $this->assertSame(0, $response->inertiaProps('tie_out.rows'));
    }

    // ---------------------------------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------------------------------

    /** @param  array<string, mixed>  $query */
    private function drill(User $technician, string $metric, array $query = []): TestResponse
    {
        return $this->get(route('admin.reports.technician.drill', ['metric' => $metric, 'technician_id' => $technician->id, ...$query]));
    }

    private function unit(string $path, ?OrganizationUnit $parent = null, ?int $companyId = null): OrganizationUnit
    {
        return OrganizationUnit::factory()->create([
            'company_id' => $companyId ?? $this->companyId,
            'parent_id' => $parent?->id,
            'path' => $path,
            'is_active' => true,
        ]);
    }

    private function member(string $role, ?OrganizationUnit $unit, ?int $companyId = null): User
    {
        $companyId ??= $this->companyId;
        $user = User::factory()->create(['company_id' => $companyId, 'is_active' => true, 'email_verified_at' => now()]);
        $user->assignRole(Role::findByName($role));
        EmployeeProfile::factory()->create([
            'company_id' => $companyId,
            'user_id' => $user->id,
            'organization_id' => $unit?->id,
            'status' => 'active',
        ]);

        return $user;
    }

    /** @param  list<string>  $permissions */
    private function userIn(array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => 'custom-'.md5(implode('|', $permissions)), 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        $user = User::factory()->create(['company_id' => $this->companyId, 'is_active' => true, 'email_verified_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * $inScope in January 2026: two completed installation SPK (one started), two resolved tickets of
     * "Support" (one on time, both with a first response), two evaluations (4 and 2, one rated 5); outside
     * January a completed SPK, a resolved ticket and an evaluation; open now: one assigned SPK and one open ticket.
     */
    private function seedTechnicianWorld(): void
    {
        $c = $this->companyId;
        $t = $this->inScope->id;
        $support = $this->worldCategory($c, 'Support', 'SUP');

        $this->worldWorkOrder($c, 'installation', 'completed', '2026-01-10 10:00:00', $t, '2026-01-10 09:00:00');
        $this->worldWorkOrder($c, 'installation', 'completed', '2026-01-31 23:59:59', $t);
        $this->worldWorkOrder($c, 'installation', 'completed', '2026-02-01 00:00:00', $t, '2026-01-31 20:00:00');
        $this->worldWorkOrder($c, 'maintenance', 'assigned', null, $t);
        $this->worldWorkOrder($c, 'installation', 'completed', '2026-01-12 10:00:00', $this->outOfScope->id);

        $this->worldTicket($c, $support, '2026-01-11 08:00:00', '2026-01-11 08:05:00', '2026-01-11 09:00:00', '2026-01-11 10:00:00', 'resolved', $t);
        $this->worldTicket($c, $support, '2026-01-12 08:00:00', '2026-01-12 08:30:00', '2026-01-12 13:00:00', '2026-01-12 12:00:00', 'closed', $t);
        $this->worldTicket($c, $support, '2026-02-02 08:00:00', null, '2026-02-02 09:00:00', '2026-02-02 10:00:00', 'resolved', $t);
        $this->worldTicket($c, $support, '2026-01-20 08:00:00', null, null, '2026-01-21 08:00:00', 'open', $t);
        $this->worldTicket($c, $support, '2026-01-13 08:00:00', null, '2026-01-13 09:00:00', '2026-01-13 10:00:00', 'resolved', $this->outOfScope->id);

        EmployeeEvaluation::factory()->create(['company_id' => $c, 'employee_id' => $t, 'score' => 4, 'customer_rating' => 5, 'evaluated_at' => '2026-01-15 10:00:00']);
        EmployeeEvaluation::factory()->create(['company_id' => $c, 'employee_id' => $t, 'score' => 2, 'customer_rating' => null, 'evaluated_at' => '2026-01-31 23:59:59']);
        EmployeeEvaluation::factory()->create(['company_id' => $c, 'employee_id' => $t, 'score' => 1, 'customer_rating' => 1, 'evaluated_at' => '2026-02-01 00:00:00']);
    }
}
