<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Reporting\Http\Requests\ReportFilterRequest;
use Modules\Reporting\Registry\ReportType;
use Modules\Service\Models\ServicePackage;
use Tests\TestCase;
use Tests\Traits\CreatesReportingUsers;

class ReportCapabilityTest extends TestCase
{
    use CreatesReportingUsers;
    use RefreshDatabase;

    /** Route name => Inertia component of every report URL, hub included. */
    private const COMPONENTS = [
        'admin.reports.index' => 'Admin/Reports/Index',
        'admin.reports.business' => 'Admin/Reports/Business',
        'admin.reports.sales' => 'Admin/Reports/Sales',
        'admin.reports.asset' => 'Admin/Reports/Asset',
        'admin.reports.sla' => 'Admin/Reports/Sla',
        'admin.reports.stock-card' => 'Admin/Reports/StockCard',
        'admin.reports.dictionary' => 'Admin/Reports/Dictionary',
        'admin.reports.audit-log' => 'Admin/Reports/AuditLog',
        'admin.reports.technician' => 'Admin/Reports/Technician',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
    }

    // (a) seeded role matrix over HTTP

    public function test_seeded_roles_open_exactly_the_report_urls_they_are_granted(): void
    {
        $matrix = [
            'admin' => [
                'allowed' => ['index', 'business', 'sales', 'asset', 'sla', 'stock-card', 'dictionary', 'audit-log', 'technician'],
                'denied' => [],
            ],
            'manager' => [
                'allowed' => ['index', 'business', 'sales', 'asset', 'sla', 'stock-card', 'dictionary'],
                'denied' => ['audit-log'],
            ],
            'staff' => [
                'allowed' => ['index', 'business', 'asset', 'sla', 'stock-card', 'dictionary'],
                'denied' => ['sales', 'audit-log', 'technician'],
            ],
            'technician' => [
                'allowed' => [],
                'denied' => ['index', 'business', 'sales', 'asset', 'sla', 'stock-card', 'dictionary', 'audit-log'],
            ],
            'customer' => [
                'allowed' => [],
                'denied' => ['index', 'business', 'sales', 'asset', 'sla', 'stock-card', 'dictionary', 'audit-log', 'technician'],
            ],
        ];

        foreach ($matrix as $role => $expectation) {
            $this->actingAs($this->userWithRole($role));

            foreach ($expectation['allowed'] as $report) {
                $routeName = 'admin.reports.'.$report;
                $this->get(route($routeName))
                    ->assertOk()
                    ->assertInertia(fn (Assert $page) => $page->component(self::COMPONENTS[$routeName]));
            }

            foreach ($expectation['denied'] as $report) {
                $this->get(route('admin.reports.'.$report))->assertForbidden();
            }
        }
    }

    // (b) hub cards

    public function test_hub_lists_only_the_reports_each_seeded_role_may_open(): void
    {
        $expected = [
            'admin' => ['business', 'sales', 'technician', 'asset', 'sla', 'stock_card', 'audit_log', 'dictionary'],
            'manager' => ['business', 'sales', 'technician', 'asset', 'sla', 'stock_card', 'dictionary'],
            'staff' => ['business', 'asset', 'sla', 'stock_card', 'dictionary'],
        ];

        foreach ($expected as $role => $keys) {
            $this->actingAs($this->userWithRole($role))
                ->get(route('admin.reports.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Admin/Reports/Index')
                    ->where('reports', fn ($reports) => collect($reports)->pluck('key')->all() === $keys));
        }
    }

    public function test_hub_cards_carry_title_description_and_relative_href(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('reports.0.key', 'business')
                ->where('reports.0.title', 'Business Metrics')
                ->where('reports.0.href', '/admin/reports/business')
                ->where('reports.1.key', 'sales')
                ->where('reports.1.title', 'Sales KPI')
                ->where('reports.1.description', 'Customers acquired, invoiced, collected and outstanding per acquirer')
                ->where('reports.1.href', '/admin/reports/sales')
                ->where('reports.7.key', 'dictionary')
                ->where('reports.7.href', '/admin/reports/dictionary')
                ->where('reports', fn ($reports) => collect($reports)->every(
                    fn (array $card): bool => array_keys($card) === ['key', 'title', 'description', 'href']
                        && $card['description'] !== ''
                )));
    }

    // (c) custom Spatie roles

    public function test_hub_capability_plus_one_report_capability_lists_only_that_card(): void
    {
        $this->actingAs($this->userWithPermissions(['report.view', 'report.sla.view']));

        $this->get(route('admin.reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Reports/Index')
                ->where('reports', fn ($reports) => collect($reports)->pluck('key')->all() === ['sla']));

        $this->get(route('admin.reports.sla'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Reports/Sla'));

        foreach (['business', 'sales', 'asset', 'stock-card', 'dictionary', 'audit-log'] as $report) {
            $this->get(route('admin.reports.'.$report))->assertForbidden();
        }
    }

    public function test_report_capability_without_hub_capability_opens_the_report_but_not_the_hub(): void
    {
        $this->actingAs($this->userWithPermissions(['report.sla.view']));

        $this->get(route('admin.reports.sla'))->assertOk();
        $this->get(route('admin.reports.index'))->assertForbidden();
    }

    public function test_hub_capability_without_any_report_capability_shows_an_empty_hub(): void
    {
        $this->actingAs($this->userWithPermissions(['report.view']));

        $this->get(route('admin.reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Reports/Index')
                ->where('reports', fn ($reports) => collect($reports)->isEmpty()));

        foreach (['business', 'sales', 'asset', 'sla', 'stock-card', 'dictionary', 'audit-log', 'technician'] as $report) {
            $this->get(route('admin.reports.'.$report))->assertForbidden();
        }
    }

    public function test_dictionary_capability_alone_opens_only_the_dictionary(): void
    {
        $this->actingAs($this->userWithPermissions(['report.dictionary.view']));

        $this->get(route('admin.reports.dictionary'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Reports/Dictionary'));

        foreach (['index', 'business', 'sales', 'asset', 'sla', 'stock-card', 'audit-log', 'technician'] as $report) {
            $this->get(route('admin.reports.'.$report))->assertForbidden();
        }
    }

    public function test_audit_capability_alone_opens_the_audit_log(): void
    {
        $this->actingAs($this->userWithPermissions(['report.audit.view']));

        $this->get(route('admin.reports.audit-log'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Reports/AuditLog'));

        foreach (['index', 'business', 'sales', 'asset', 'sla', 'stock-card', 'dictionary'] as $report) {
            $this->get(route('admin.reports.'.$report))->assertForbidden();
        }
    }

    public function test_sales_capability_alone_opens_only_the_sales_report(): void
    {
        $this->actingAs($this->userWithPermissions(['report.sales.view']));

        $this->get(route('admin.reports.sales'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Reports/Sales'));

        foreach (['index', 'business', 'asset', 'sla', 'stock-card', 'dictionary', 'audit-log', 'technician'] as $report) {
            $this->get(route('admin.reports.'.$report))->assertForbidden();
        }
    }

    public function test_report_view_alone_does_not_open_sales(): void
    {
        $this->actingAs($this->userWithPermissions(['report.view']));

        $this->get(route('admin.reports.index'))->assertOk();
        $this->get(route('admin.reports.sales'))->assertForbidden();
    }

    public function test_each_per_report_capability_opens_only_its_own_report(): void
    {
        $capabilities = [
            'report.business.view' => 'business',
            'report.asset.view' => 'asset',
            'report.sla.view' => 'sla',
            'report.stock_card.view' => 'stock-card',
            'report.dictionary.view' => 'dictionary',
            'report.sales.view' => 'sales',
        ];

        foreach ($capabilities as $permission => $own) {
            $this->actingAs($this->userWithPermissions([$permission]));

            $this->get(route('admin.reports.'.$own))->assertOk();

            foreach (array_diff($capabilities, [$own]) as $other) {
                $this->get(route('admin.reports.'.$other))->assertForbidden();
            }
        }
    }

    // (d) alignment between the catalog, the routes and ReportFilterRequest

    public function test_every_report_route_is_gated_by_the_capability_the_catalog_assigns(): void
    {
        foreach (ReportType::cases() as $report) {
            $route = app('router')->getRoutes()->getByName($report->routeName());

            $this->assertNotNull($route, $report->routeName().' is not registered.');
            $this->assertContains('can:'.$report->permission(), $route->gatherMiddleware());
        }
    }

    public function test_hub_route_is_gated_by_the_hub_capability_only(): void
    {
        $route = app('router')->getRoutes()->getByName('admin.reports.index');
        $this->assertNotNull($route);

        $capabilityMiddleware = array_values(array_filter(
            $route->gatherMiddleware(),
            fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'can:'),
        ));

        $this->assertSame(['can:report.view'], $capabilityMiddleware);
    }

    public function test_filter_request_authorizes_the_capability_of_the_matched_report_only(): void
    {
        $filtered = array_filter(ReportType::cases(), fn (ReportType $report): bool => $report !== ReportType::Dictionary);

        foreach ($filtered as $report) {
            $holder = $this->userWithPermissions([$report->permission()]);
            $hubOnly = $this->userWithPermissions(['report.view']);

            $this->assertTrue(
                $this->filterRequestFor($report->routeName(), $holder)->authorize(),
                $report->routeName().' must authorize the holder of '.$report->permission(),
            );
            $this->assertFalse(
                $this->filterRequestFor($report->routeName(), $hubOnly)->authorize(),
                $report->routeName().' must not authorize a user holding only report.view',
            );
        }
    }

    // (e) tenant scope is unaffected by capabilities and versioning

    public function test_business_report_data_stays_scoped_to_the_company_while_meta_is_shared(): void
    {
        $adminA = $this->userWithRole('admin');
        $adminB = $this->userWithRole('admin');

        CompanyService::resetCache();
        CompanyService::useCompanyForTests((int) $adminA->company_id);
        // One shared package: the package factory always builds its profiles for the first company,
        // so creating packages per tenant collides on the unique tier names.
        $packageId = ServicePackage::factory()->create()->id;
        ServiceSubscription::factory()->create(['status' => 'active', 'mrc_amount' => 100000, 'service_package_id' => $packageId]);
        CompanyService::useCompanyForTests((int) $adminB->company_id);
        foreach (range(1, 2) as $ignored) {
            ServiceSubscription::factory()->create(['status' => 'active', 'mrc_amount' => 250000, 'service_package_id' => $packageId]);
        }
        CompanyService::useCompanyForTests((int) $adminA->company_id);

        $responseA = $this->actingAs($adminA)->get(route('admin.reports.business'))->assertOk();
        $this->assertEquals(100000, $responseA->inertiaProps('data.mrr'));
        $this->assertSame(1, $responseA->inertiaProps('data.active_subscriptions'));

        CompanyService::useCompanyForTests((int) $adminB->company_id);
        $responseB = $this->actingAs($adminB)->get(route('admin.reports.business'))->assertOk();
        $this->assertEquals(500000, $responseB->inertiaProps('data.mrr'));
        $this->assertSame(2, $responseB->inertiaProps('data.active_subscriptions'));

        $this->assertSame('business.v1', $responseA->inertiaProps('meta.formula_version'));
        $this->assertSame($responseA->inertiaProps('meta'), $responseB->inertiaProps('meta'));
    }

    private function filterRequestFor(string $routeName, User $user): ReportFilterRequest
    {
        $uri = '/'.str_replace('.', '/', $routeName);
        $route = (new Route('GET', $uri, []))->name($routeName);

        $request = ReportFilterRequest::createFromBase(Request::create($uri, 'GET'));
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}
