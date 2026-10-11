<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Company;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Reporting\Registry\MetricRegistry;
use Modules\Reporting\Registry\ReportType;
use Tests\TestCase;
use Tests\Traits\CreatesReportingUsers;

class ReportDictionaryTest extends TestCase
{
    use CreatesReportingUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
    }

    public function test_admin_manager_and_staff_open_the_dictionary(): void
    {
        foreach (['admin', 'manager', 'staff'] as $role) {
            $this->actingAs($this->userWithRole($role))
                ->get(route('admin.reports.dictionary'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Admin/Reports/Dictionary'));
        }
    }

    public function test_technician_customer_and_hub_only_role_cannot_open_the_dictionary(): void
    {
        foreach ([
            $this->userWithRole('technician'),
            $this->userWithRole('customer'),
            $this->userWithPermissions(['report.view', 'report.business.view']),
        ] as $user) {
            $this->actingAs($user)->get(route('admin.reports.dictionary'))->assertForbidden();
        }
    }

    public function test_dictionary_lists_the_six_reports_with_their_capability_and_version(): void
    {
        $expected = [
            'business' => ['permission' => 'report.business.view', 'count' => 13],
            'sales' => ['permission' => 'report.sales.view', 'count' => 7],
            'technician' => ['permission' => 'report.technician', 'count' => 11],
            'asset' => ['permission' => 'report.asset.view', 'count' => 5],
            'sla' => ['permission' => 'report.sla.view', 'count' => 7],
            'stock_card' => ['permission' => 'report.stock_card.view', 'count' => 3],
        ];

        $reports = $this->actingAs($this->userWithRole('admin'))
            ->get(route('admin.reports.dictionary'))
            ->assertOk()
            ->inertiaProps('reports');

        $this->assertSame(array_keys($expected), array_column($reports, 'key'));

        foreach ($reports as $report) {
            $key = $report['key'];
            $this->assertSame($key.'.v1', $report['formula_version']);
            $this->assertSame($expected[$key]['permission'], $report['permission']);
            $this->assertCount($expected[$key]['count'], $report['metrics']);

            $metricKeys = array_column($report['metrics'], 'key');
            $this->assertSame($metricKeys, array_values(array_unique($metricKeys)), "{$key} lists a metric twice");

            foreach ($report['metrics'] as $metric) {
                $this->assertSame('v1', $metric['version']);
                $this->assertSame($key, $metric['report']);
            }
        }

        $this->assertSame(46, array_sum(array_map(fn (array $report): int => count($report['metrics']), $reports)));
    }

    public function test_dictionary_exposes_every_registry_definition_in_full(): void
    {
        $reports = $this->actingAs($this->userWithRole('admin'))
            ->get(route('admin.reports.dictionary'))
            ->inertiaProps('reports');

        foreach ($reports as $report) {
            $registry = MetricRegistry::forReport(ReportType::from($report['key']));
            $this->assertSame(
                array_map(fn ($metric): array => $metric->toArray(), $registry),
                $report['metrics'],
            );
        }
    }

    public function test_dictionary_does_not_depend_on_the_company(): void
    {
        $adminA = $this->userWithRole('admin');
        $adminB = $this->userWithRole('admin');
        $companyNames = Company::query()->pluck('name')->all();

        $reportsA = $this->actingAs($adminA)->get(route('admin.reports.dictionary'))->assertOk()->inertiaProps('reports');
        $reportsB = $this->actingAs($adminB)->get(route('admin.reports.dictionary'))->assertOk()->inertiaProps('reports');

        $this->assertSame(json_encode($reportsA), json_encode($reportsB));

        $json = json_encode($reportsA);
        foreach ($companyNames as $name) {
            $this->assertStringNotContainsString($name, $json);
        }
        foreach ([$adminA, $adminB] as $admin) {
            $this->assertStringNotContainsString($admin->email, $json);
        }
    }
}
