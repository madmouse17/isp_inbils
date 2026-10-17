<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\EmployeeProfile;
use App\Models\Core\Location;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Unit;
use Modules\Reporting\Registry\ReportType;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesReportingUsers;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * Who may take a report out as a file. Export needs `report.export` AND the report's own
 * capability: report access alone (the hub, a figure, a source list) never grants a file.
 */
class ReportExportAccessTest extends TestCase
{
    use CreatesReportingUsers;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private const SLUGS = ['business', 'sla', 'asset', 'technician', 'stock-card', 'audit-log'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
    }

    public function test_export_routes_repeat_the_catalog_capability_and_report_export(): void
    {
        foreach (ReportType::exportable() as $report) {
            $route = app('router')->getRoutes()->getByName($report->exportRouteName());

            $this->assertNotNull($route, $report->exportRouteName().' is not registered.');
            $this->assertSame('admin/reports/'.$report->slug().'/export', $route->uri());
            $this->assertContains('can:'.$report->permission(), $route->gatherMiddleware());
            $this->assertContains('can:report.export', $route->gatherMiddleware());
        }

        foreach ([ReportType::Sales, ReportType::Dictionary] as $outside) {
            $this->assertNull(app('router')->getRoutes()->getByName($outside->exportRouteName()), $outside->value.' has no export');
        }
    }

    public function test_seeded_roles_export_exactly_the_reports_they_hold_both_gates_for(): void
    {
        $matrix = [
            'admin' => ['allowed' => self::SLUGS, 'denied' => []],
            'manager' => ['allowed' => ['business', 'sla', 'asset', 'stock-card'], 'denied' => ['audit-log']],
            'staff' => ['allowed' => [], 'denied' => self::SLUGS],
            'technician' => ['allowed' => [], 'denied' => self::SLUGS],
            'customer' => ['allowed' => [], 'denied' => self::SLUGS],
        ];

        foreach ($matrix as $role => $expectation) {
            $user = $this->userWithRole($role);
            $this->actingAs($user);

            foreach ($expectation['allowed'] as $slug) {
                $this->get(route("admin.reports.$slug.export", $this->queryFor($slug, $user)))->assertOk();
            }

            foreach ($expectation['denied'] as $slug) {
                $this->get(route("admin.reports.$slug.export", $this->queryFor($slug, $user)))->assertForbidden();
            }
        }
    }

    public function test_report_view_alone_never_grants_an_export(): void
    {
        $this->actingAs($user = $this->userWithPermissions(['report.view']));

        foreach (self::SLUGS as $slug) {
            $this->get(route("admin.reports.$slug.export", $this->queryFor($slug, $user)))->assertForbidden();
        }
    }

    public function test_the_report_capability_without_report_export_gets_no_file(): void
    {
        $this->actingAs($this->userWithPermissions(['report.business.view']));

        $this->get(route('admin.reports.business'))->assertOk();
        $this->get(route('admin.reports.business.export'))->assertForbidden();
    }

    public function test_report_export_without_the_report_capability_gets_no_file(): void
    {
        $this->actingAs($this->userWithPermissions(['report.export', 'report.sla.view']));

        $this->get(route('admin.reports.sla.export'))->assertOk();
        $this->get(route('admin.reports.business.export'))->assertForbidden();
        $this->get(route('admin.reports.audit-log.export'))->assertForbidden();
    }

    public function test_both_gates_together_open_the_audit_log_export(): void
    {
        $this->actingAs($this->userWithPermissions(['report.audit.view', 'report.export']));

        $this->get(route('admin.reports.audit-log.export'))->assertOk();
    }

    public function test_an_anonymous_visitor_is_sent_to_login(): void
    {
        $this->get(route('admin.reports.business.export'))->assertRedirect(route('login'));
    }

    public function test_only_csv_and_pdf_are_offered(): void
    {
        $this->actingAs($this->userWithRole('admin'));

        $this->get(route('admin.reports.business.export', ['format' => 'csv']))->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->get(route('admin.reports.business.export', ['format' => 'pdf']))->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->get(route('admin.reports.business.export', ['format' => 'xlsx']))->assertSessionHasErrors('format');
    }

    public function test_the_stock_card_export_needs_a_product(): void
    {
        $this->actingAs($this->userWithRole('admin'));

        $this->get(route('admin.reports.stock-card.export'))->assertSessionHasErrors('product_id');
    }

    public function test_the_technician_export_needs_a_technician_unless_the_actor_is_one(): void
    {
        $this->actingAs($admin = $this->userWithRole('admin'));
        $this->get(route('admin.reports.technician.export'))->assertNotFound();

        // A technician holding export reaches only their own performance, without naming it.
        $technician = $this->technicianInCompany((int) $admin->company_id);
        $technician->assignRole($this->roleWith(['report.technician', 'report.export']));

        $this->actingAs($technician)->get(route('admin.reports.technician.export'))->assertOk();
    }

    /** @return array<string, string> */
    private function queryFor(string $slug, User $user): array
    {
        return match ($slug) {
            'stock-card' => ['product_id' => $this->productIn($user)->public_id],
            'technician' => ['technician_id' => $this->technicianInCompany((int) $user->company_id)->public_id],
            default => [],
        };
    }

    private function technicianInCompany(int $companyId): User
    {
        $technician = User::factory()->create(['company_id' => $companyId, 'is_active' => true, 'email_verified_at' => now()]);
        $technician->assignRole(Role::findByName('technician'));
        EmployeeProfile::factory()->create(['company_id' => $companyId, 'user_id' => $technician->id, 'status' => 'active']);

        return $technician;
    }

    private function productIn(User $user): Product
    {
        $companyId = (int) $user->company_id;
        $unit = Unit::factory()->create(['company_id' => $companyId]);
        $category = Category::factory()->create(['company_id' => $companyId, 'unit_id' => $unit->id]);
        $product = Product::factory()->create(['company_id' => $companyId, 'category_id' => $category->id, 'unit_id' => $unit->id]);
        $location = Location::factory()->create(['company_id' => $companyId]);

        StockMovement::withoutCompany()->forceCreate([
            'company_id' => $companyId,
            'product_id' => $product->id,
            'to_location_id' => $location->id,
            'movement_type' => 'receive',
            'quantity' => 5,
            'balance_after' => 5,
            'reserved_after' => 0,
            'created_by' => $user->id,
            'note' => 'Access fixture',
        ]);

        return $product;
    }

    /** @param  list<string>  $permissions */
    private function roleWith(array $permissions): Role
    {
        $role = Role::firstOrCreate(['name' => 'tech-with-export', 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);

        return $role;
    }
}
