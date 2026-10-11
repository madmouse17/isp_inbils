<?php

namespace Tests\Feature\Reporting;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
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

        foreach (['admin', 'manager', 'technician'] as $role) {
            $this->assertTrue(Role::findByName($role)->hasPermissionTo('report.technician'));
        }

        foreach (['staff', 'customer'] as $role) {
            $this->assertFalse(Role::findByName($role)->hasPermissionTo('report.technician'));
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
}
