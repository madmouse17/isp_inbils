<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Company;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TechnicianReportCapabilityRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
    }

    public function test_technician_report_capability_and_route_use_the_dedicated_permission(): void
    {
        $permission = Permission::query()->where('name', 'report.technician')->first();

        $this->assertNotNull($permission);

        $roles = $this->companyRoles(Company::factory()->create());

        foreach (['admin', 'manager', 'technician'] as $key) {
            $this->assertTrue($roles[$key]->hasPermissionTo('report.technician'), $key);
        }

        foreach (['staff', 'customer'] as $key) {
            $this->assertFalse($roles[$key]->hasPermissionTo('report.technician'), $key);
        }

        $technicianRoute = app('router')->getRoutes()->getByName('admin.reports.technician');
        $this->assertNotNull($technicianRoute);
        $technicianMiddleware = $technicianRoute->gatherMiddleware();

        $this->assertContains('can:report.technician', $technicianMiddleware);
        $this->assertNotContains('can:report.view', $technicianMiddleware);

        $businessRoute = app('router')->getRoutes()->getByName('admin.reports.business');
        $this->assertNotNull($businessRoute);
        $this->assertContains('can:report.business.view', $businessRoute->gatherMiddleware());
        $this->assertNotContains('can:report.view', $businessRoute->gatherMiddleware());
    }

    public function test_the_scope_markers_sit_on_the_company_copies_of_the_roles_that_had_the_scope(): void
    {
        $roles = collect($this->companyRoles(Company::factory()->create()));
        $holders = fn (string $permission): array => $roles
            ->filter(fn ($role): bool => $role->hasPermissionTo($permission))
            ->keys()
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['admin', 'manager'], $holders('report.technician.team'));
        $this->assertSame(['technician'], $holders('app.technician'));
    }
}
