<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Reporting\Drill\DrillRegistry;
use Modules\Reporting\Registry\MetricRegistry;
use Modules\Reporting\Registry\ReportType;
use Tests\TestCase;
use Tests\Traits\BuildsReportingWorld;
use Tests\Traits\CreatesReportingUsers;
use Tests\Traits\ParsesCsvDownloads;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * Source rows behind the Business, SLA and Asset figures. Each list is built from the same
 * predicate as its aggregate, so rows, sums and the figure on the report tie out, v1 quirks
 * included. Expected counts are worked out by hand from the fixtures, not read back from queries.
 */
class ReportDrillTest extends TestCase
{
    use BuildsReportingWorld;
    use CreatesReportingUsers;
    use ParsesCsvDownloads;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private const JANUARY = ['date_from' => '2026-01-01', 'date_to' => '2026-01-31'];

    private User $admin;

    private User $otherAdmin;

    private int $companyId;

    private int $otherCompanyId;

    /** @var array<string, mixed> fixture rows by the name used in the comments */
    private array $w = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The lists are asserted through their Inertia props; the page bundle is built separately.
        $this->withoutVite();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->admin = $this->userWithRole('admin');
        $this->otherAdmin = $this->userWithRole('admin');
        $this->companyId = (int) $this->admin->company_id;
        $this->otherCompanyId = (int) $this->otherAdmin->company_id;
        // The edge fixtures are pinned to calendar days of the storage timezone (UTC).
        Company::query()->whereKey([$this->companyId, $this->otherCompanyId])->update(['timezone' => 'UTC']);
    }

    // ---------------------------------------------------------------------------------------------
    // Routes and access
    // ---------------------------------------------------------------------------------------------

    public function test_drill_routes_repeat_the_catalog_capability_and_export_adds_report_export(): void
    {
        foreach (ReportType::drillable() as $report) {
            $list = app('router')->getRoutes()->getByName($report->drillRouteName());
            $file = app('router')->getRoutes()->getByName($report->drillRouteName().'.export');

            $this->assertNotNull($list, $report->drillRouteName());
            $this->assertNotNull($file, $report->drillRouteName().'.export');
            $this->assertContains('can:'.$report->permission(), $list->gatherMiddleware());
            $this->assertNotContains('can:report.export', $list->gatherMiddleware(), 'reading rows needs no export permission');
            $this->assertContains('can:'.$report->permission(), $file->gatherMiddleware());
            $this->assertContains('can:report.export', $file->gatherMiddleware());
        }

        foreach ([ReportType::StockCard, ReportType::AuditLog, ReportType::Sales] as $rowLevel) {
            $this->assertNull(app('router')->getRoutes()->getByName($rowLevel->drillRouteName()), $rowLevel->value.' has no drill');
        }
    }

    public function test_every_registered_drill_list_names_a_registry_figure(): void
    {
        foreach (DrillRegistry::all() as $source) {
            $keys = array_map(fn ($metric) => $metric->key, MetricRegistry::forReport($source->report));

            $this->assertContains($source->metric(), $keys, "{$source->report->value}.{$source->key} drills a figure the registry does not define");
        }
    }

    public function test_report_view_alone_opens_no_source_rows(): void
    {
        $this->seedBusinessWorld();
        $this->actingAs($this->userWithPermissions(['report.view']));

        $this->get(route('admin.reports.business.drill', ['metric' => 'mrr']))->assertForbidden();
        $this->get(route('admin.reports.sla.drill', ['metric' => 'total_resolved']))->assertForbidden();
    }

    public function test_the_report_capability_needs_the_source_module_permission_on_top(): void
    {
        $this->seedBusinessWorld();

        $this->actingAs($this->userWithPermissions(['report.business.view']));
        $this->get(route('admin.reports.business.drill', ['metric' => 'revenue_paid', ...self::JANUARY]))->assertForbidden();
        $this->get(route('admin.reports.business.drill', ['metric' => 'mrr']))->assertForbidden();

        // billing.view opens invoices, not subscriptions: each list asks its own module.
        $this->actingAs($this->userWithPermissions(['report.business.view', 'billing.view']));
        $this->get(route('admin.reports.business.drill', ['metric' => 'revenue_paid', ...self::JANUARY]))->assertOk();
        $this->get(route('admin.reports.business.drill', ['metric' => 'mrr']))->assertForbidden();

        $this->actingAs($this->userWithPermissions(['report.business.view', 'customer.subscription.view']));
        $this->get(route('admin.reports.business.drill', ['metric' => 'mrr']))->assertOk();
        $this->get(route('admin.reports.business.drill', ['metric' => 'revenue_paid', ...self::JANUARY]))->assertForbidden();
    }

    public function test_source_permission_without_the_report_capability_is_refused(): void
    {
        $this->actingAs($this->userWithPermissions(['billing.view', 'report.sla.view']));

        $this->get(route('admin.reports.business.drill', ['metric' => 'revenue_paid']))->assertForbidden();
    }

    public function test_seeded_roles_open_the_lists_they_hold_both_permissions_for(): void
    {
        $this->seedBusinessWorld();

        foreach (['admin', 'manager', 'staff'] as $role) {
            $this->actingAs($this->userWithRole($role));

            foreach (['mrr', 'revenue_paid', 'installation_count', 'sla_compliance_pct'] as $metric) {
                $this->get(route('admin.reports.business.drill', ['metric' => $metric, ...self::JANUARY]))->assertOk();
            }
        }

        foreach (['technician', 'customer'] as $role) {
            $this->actingAs($this->userWithRole($role));
            $this->get(route('admin.reports.business.drill', ['metric' => 'mrr']))->assertForbidden();
        }
    }

    public function test_an_unknown_figure_is_not_found_and_a_breakdown_row_needs_its_group(): void
    {
        $this->actingAs($this->admin);

        $this->get(route('admin.reports.business.drill', ['metric' => 'no_such_figure']))->assertNotFound();
        $this->get(route('admin.reports.business.drill', ['metric' => 'asset_distribution']))->assertSessionHasErrors('group');
        $this->get(route('admin.reports.business.drill', ['metric' => 'mrr', 'sort_by' => 'password']))->assertSessionHasErrors('sort_by');
    }

    // ---------------------------------------------------------------------------------------------
    // Business: rows tie out to every figure
    // ---------------------------------------------------------------------------------------------

    public function test_business_lists_tie_out_to_the_figure_and_hold_exactly_the_expected_rows(): void
    {
        $this->seedBusinessWorld();
        $this->actingAs($this->admin);

        $cases = [
            // metric => [rows, sum or null, expected row ids by fixture name]
            'mrr' => [3, '700000.00', ['s_active_1', 's_active_2', 's_active_old']],
            'active_subscriptions' => [3, null, ['s_active_1', 's_active_2', 's_active_old']],
            'suspended_subscriptions' => [1, null, ['s_suspended']],
            'terminated_subscriptions' => [2, null, ['s_churn', 's_terminated_early']],
            'new_subscriptions' => [3, null, ['s_active_1', 's_active_2', 's_suspended']],
            'churn' => [1, null, ['s_churn']],
            'revenue_paid' => [3, '496000.00', ['i_first_day', 'i_last_day', 'i_overpaid']],
            'recurring_revenue' => [2, '450000.00', ['i_first_day', 'i_last_day']],
            'one_time_revenue' => [1, '46000.00', ['i_overpaid']],
            'outstanding' => [4, '465000.00', ['i_partial', 'i_sent', 'i_overdue', 'i_overpaid_open']],
            'installation_count' => [2, null, ['w_first', 'w_last']],
        ];

        foreach ($cases as $metric => [$rows, $sum, $ids]) {
            $response = $this->drill('business', $metric, self::JANUARY);

            $response->assertOk();
            $this->assertSame($rows, $response->inertiaProps('tie_out.rows'), "{$metric} rows");
            $this->assertTrue($response->inertiaProps('tie_out.matches'), "{$metric} ties out to the figure");
            $this->assertSame($rows, $response->inertiaProps('rows.total'));
            $this->assertEqualsCanonicalizing(
                array_map(fn (string $name): int => $this->w[$name]->id, $ids),
                array_column($response->inertiaProps('rows.data'), 'id'),
                "{$metric} rows",
            );
            if ($sum !== null) {
                $this->assertSame($sum, $response->inertiaProps('tie_out.sum'), "{$metric} sum");
            }
        }
    }

    public function test_business_sla_share_ties_out_all_time_and_ignores_the_period(): void
    {
        $this->seedBusinessWorld();
        $this->actingAs($this->admin);

        // Business SLA is an all-time figure: 5 resolved tickets, 3 on time, whatever the period says.
        foreach ([self::JANUARY, ['date_from' => '2030-01-01', 'date_to' => '2030-01-31'], []] as $period) {
            $response = $this->drill('business', 'sla_compliance_pct', $period);

            $this->assertSame(5, $response->inertiaProps('tie_out.rows'));
            $this->assertSame(3, $response->inertiaProps('tie_out.compliant'));
            $this->assertEquals(60.0, $response->inertiaProps('tie_out.figure'));
            $this->assertTrue($response->inertiaProps('tie_out.matches'));
        }
    }

    public function test_period_lists_follow_a_half_range_and_are_empty_without_any_range_exactly_like_their_figure(): void
    {
        $this->seedBusinessWorld();
        $this->actingAs($this->admin);

        // v1: new subscriptions and churn are 0 without a range; the source is empty too. A half range applies as set.
        $expected = [
            'new_subscriptions' => [0, 3, 6],   // none / From 2026-01-01: s_active_1, s_active_2, s_suspended / To 2026-01-31: all six live subscriptions
            'churn' => [0, 1, 2],               // none / From: s_churn / To: s_churn + s_terminated_early
        ];
        foreach ($expected as $metric => [$none, $from, $to]) {
            foreach ([[[], $none], [['date_from' => '2026-01-01'], $from], [['date_to' => '2026-01-31'], $to]] as [$period, $rows]) {
                $response = $this->drill('business', $metric, $period);

                $this->assertSame($rows, $response->inertiaProps('tie_out.rows'), $metric.' '.json_encode($period));
                $this->assertTrue($response->inertiaProps('tie_out.matches'), $metric);
            }
        }

        // Revenue without a range covers every paid invoice, the one of December included.
        $all = $this->drill('business', 'revenue_paid', []);
        $this->assertSame(4, $all->inertiaProps('tie_out.rows'));
        $this->assertSame('7496000.00', $all->inertiaProps('tie_out.sum'));
        $this->assertTrue($all->inertiaProps('tie_out.matches'));
    }

    public function test_the_last_day_of_the_period_is_inclusive(): void
    {
        $this->seedBusinessWorld();
        $this->actingAs($this->admin);

        $lastDay = $this->drill('business', 'revenue_paid', ['date_from' => '2026-01-31', 'date_to' => '2026-01-31']);

        $this->assertSame(1, $lastDay->inertiaProps('tie_out.rows'));
        $this->assertSame((int) $this->w['i_last_day']->id, $lastDay->inertiaProps('rows.data.0.id'));
        $this->assertTrue($lastDay->inertiaProps('tie_out.matches'));
    }

    public function test_outstanding_calls_out_the_difference_to_receivables(): void
    {
        $this->seedBusinessWorld();
        $this->actingAs($this->admin);

        $response = $this->drill('business', 'outstanding');

        // Report: partial 300000 + sent 120000 + overdue 75000 + overpaid -30000 = 465000.
        // Receivables lists only invoices still unpaid, so it holds 495000: the gap is shown.
        $this->assertSame('465000.00', $response->inertiaProps('tie_out.sum'));
        $this->assertSame('Receivables', $response->inertiaProps('reconciliation.label'));
        $this->assertSame('/admin/billing/receivables', $response->inertiaProps('reconciliation.href'));
        $this->assertSame('495000.00', $response->inertiaProps('reconciliation.amount'));
        $this->assertSame('-30000.00', $response->inertiaProps('reconciliation.difference'));
    }

    public function test_breakdown_rows_and_the_asset_distribution_tie_out_per_group(): void
    {
        $this->seedAssetWorld();
        $this->actingAs($this->admin);

        foreach (['available' => 1, 'installed' => 2, 'retired' => 1] as $status => $count) {
            $response = $this->drill('business', 'asset_distribution', ['group' => $status]);

            $this->assertSame($count, $response->inertiaProps('tie_out.rows'), $status);
            $this->assertTrue($response->inertiaProps('tie_out.matches'), $status);
            $this->assertSame([$status], array_values(array_unique(array_column($response->inertiaProps('rows.data'), 'status'))));
        }
    }

    public function test_a_company_never_sees_the_rows_of_another_company(): void
    {
        $this->seedBusinessWorld();

        $mine = $this->actingAs($this->admin)->get(route('admin.reports.business.drill', ['metric' => 'revenue_paid', ...self::JANUARY]));
        $this->assertSame(3, $mine->inertiaProps('tie_out.rows'));

        $theirs = $this->actingAs($this->otherAdmin)->get(route('admin.reports.business.drill', ['metric' => 'revenue_paid', ...self::JANUARY]));
        $this->assertSame(1, $theirs->inertiaProps('tie_out.rows'));
        $this->assertSame('9000000.00', $theirs->inertiaProps('tie_out.sum'));
        $this->assertTrue($theirs->inertiaProps('tie_out.matches'));
    }

    // ---------------------------------------------------------------------------------------------
    // SLA
    // ---------------------------------------------------------------------------------------------

    public function test_sla_lists_tie_out_and_keep_the_category_breakdown_unfiltered(): void
    {
        $this->seedSlaWorld();
        $this->actingAs($this->admin);

        $cases = [
            'total_resolved' => [4, ['t_ontime', 't_equal', 't_breach', 't_nodeadline']],
            'sla_compliant' => [2, ['t_ontime', 't_equal']],
            'breach_count' => [1, ['t_breach']],
            'avg_frt_minutes' => [3, ['t_ontime', 't_breach', 't_nodeadline']],
        ];
        foreach ($cases as $metric => [$rows, $ids]) {
            $response = $this->drill('sla', $metric, self::JANUARY);

            $this->assertSame($rows, $response->inertiaProps('tie_out.rows'), $metric);
            $this->assertEqualsCanonicalizing(
                array_map(fn (string $name): int => $this->w[$name]->id, $ids),
                array_column($response->inertiaProps('rows.data'), 'id'),
                $metric,
            );
            if ($metric !== 'avg_frt_minutes') {
                $this->assertTrue($response->inertiaProps('tie_out.matches'), $metric);
            }
        }

        $rate = $this->drill('sla', 'sla_rate', self::JANUARY);
        $this->assertSame(4, $rate->inertiaProps('tie_out.rows'));
        $this->assertSame(2, $rate->inertiaProps('tie_out.compliant'));
        $this->assertEquals(50.0, $rate->inertiaProps('tie_out.figure'));
        $this->assertTrue($rate->inertiaProps('tie_out.matches'));

        // v1: by_category ignores the period and the category filter of the report.
        $filtered = [...self::JANUARY, 'category_id' => $this->w['support']->id];
        foreach (['Support' => 3, 'Outage' => 2] as $category => $count) {
            $response = $this->drill('sla', 'by_category', [...$filtered, 'group' => $category]);

            $this->assertSame($count, $response->inertiaProps('tie_out.rows'), $category);
            $this->assertTrue($response->inertiaProps('tie_out.matches'), $category);
        }

        // The category filter does narrow the top counters.
        $narrow = $this->drill('sla', 'total_resolved', $filtered);
        $this->assertSame(2, $narrow->inertiaProps('tie_out.rows'));
        $this->assertTrue($narrow->inertiaProps('tie_out.matches'));
    }

    public function test_sla_rows_show_the_deadline_outcome_and_the_minutes_the_averages_use(): void
    {
        $this->seedSlaWorld();
        $this->actingAs($this->admin);

        $rows = collect($this->drill('sla', 'total_resolved', self::JANUARY)->inertiaProps('rows.data'))->keyBy('id');

        $this->assertSame('On time', $rows[$this->w['t_ontime']->id]['sla']);
        $this->assertSame('On time', $rows[$this->w['t_equal']->id]['sla']);
        $this->assertSame('Breached', $rows[$this->w['t_breach']->id]['sla']);
        $this->assertSame('No deadline', $rows[$this->w['t_nodeadline']->id]['sla']);
        $this->assertSame(60, $rows[$this->w['t_ontime']->id]['resolution_minutes']);
        $this->assertSame(240, $rows[$this->w['t_breach']->id]['resolution_minutes']);
        $this->assertSame(10, $rows[$this->w['t_ontime']->id]['first_response_minutes']);
    }

    // ---------------------------------------------------------------------------------------------
    // Asset
    // ---------------------------------------------------------------------------------------------

    public function test_asset_lists_tie_out_and_reproduce_which_figures_honour_the_filters(): void
    {
        $this->seedAssetWorld();
        $this->actingAs($this->admin);

        $status = $this->drill('asset', 'status_distribution', ['group' => 'installed']);
        $this->assertSame(2, $status->inertiaProps('tie_out.rows'));
        $this->assertTrue($status->inertiaProps('tie_out.matches'));

        // v1: the type filter narrows the status distribution (installed: router A2 only) ...
        $routers = $this->drill('asset', 'status_distribution', ['group' => 'installed', 'asset_type' => 'router']);
        $this->assertSame(1, $routers->inertiaProps('tie_out.rows'));
        $this->assertTrue($routers->inertiaProps('tie_out.matches'));

        // ... and nothing else: customer-linked and by-location ignore it.
        $linked = $this->drill('asset', 'customer_linked_count', ['asset_type' => 'router']);
        $this->assertSame(2, $linked->inertiaProps('tie_out.rows'));
        $this->assertTrue($linked->inertiaProps('tie_out.matches'));

        // by_location groups by name: another company's "POP Central" never merges in.
        $central = $this->drill('asset', 'by_location', ['group' => 'POP Central']);
        $this->assertSame(3, $central->inertiaProps('tie_out.rows'));
        $this->assertTrue($central->inertiaProps('tie_out.matches'));

        $retired = $this->drill('asset', 'retired_count');
        $this->assertSame(1, $retired->inertiaProps('tie_out.rows'));
        $this->assertTrue($retired->inertiaProps('tie_out.matches'));
    }

    public function test_installation_records_are_listed_for_the_period(): void
    {
        $this->seedAssetWorld();
        $this->actingAs($this->admin);

        $response = $this->drill('asset', 'installation_count', self::JANUARY);

        $this->assertSame(1, $response->inertiaProps('tie_out.rows'));
        $this->assertTrue($response->inertiaProps('tie_out.matches'));
        $this->assertSame((int) $this->w['n_inside']->id, $response->inertiaProps('rows.data.0.id'));
        $this->assertSame('/admin/network-assets/'.$this->w['n_inside']->network_asset_id, $response->inertiaProps('rows.data.0.url'));
    }

    // ---------------------------------------------------------------------------------------------
    // Paging, sorting, files
    // ---------------------------------------------------------------------------------------------

    public function test_lists_page_and_sort_on_the_server_with_an_allowlist(): void
    {
        $this->seedBusinessWorld();
        $this->actingAs($this->admin);

        $asc = $this->drill('business', 'mrr', ['sort_by' => 'mrc_amount', 'sort_dir' => 'asc', 'per_page' => 10]);
        $this->assertSame(['100000.00', '200000.00', '400000.00'], array_column($asc->inertiaProps('rows.data'), 'mrc_amount'));

        $desc = $this->drill('business', 'mrr', ['sort_by' => 'mrc_amount', 'sort_dir' => 'desc']);
        $this->assertSame(['400000.00', '200000.00', '100000.00'], array_column($desc->inertiaProps('rows.data'), 'mrc_amount'));

        $paged = $this->drill('business', 'mrr', ['sort_by' => 'mrc_amount', 'sort_dir' => 'asc', 'per_page' => 25]);
        $this->assertSame(25, $paged->inertiaProps('rows.per_page'));
        $this->assertSame(3, $paged->inertiaProps('rows.total'));

        $this->assertSame(
            ['code', 'customer', 'package', 'status', 'mrc_amount', 'created_at', 'terminated_at'],
            array_column($asc->inertiaProps('columns'), 'key'),
        );
    }

    public function test_a_list_can_be_taken_out_as_a_file_with_the_same_rows(): void
    {
        $this->seedBusinessWorld();
        $this->actingAs($this->admin);

        $records = $this->csvRecords($this->get(route('admin.reports.business.drill.export', ['metric' => 'revenue_paid', ...self::JANUARY, 'sort_by' => 'paid_amount', 'sort_dir' => 'asc'])));

        // Sorted by paid amount: the overpaid one-time 46000, then 150000 and 300000.
        $this->assertSame(
            [$this->w['i_overpaid']->number, $this->w['i_last_day']->number, $this->w['i_first_day']->number],
            array_column($records, 'Invoice'),
        );
        $this->assertEquals(496000, array_sum(array_map('floatval', array_column($records, 'Paid'))));
        $this->assertCount(3, $records);

        $this->get(route('admin.reports.business.drill.export', ['metric' => 'revenue_paid', 'format' => 'pdf', ...self::JANUARY]))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_a_list_file_needs_report_export_on_top_of_the_drill_permissions(): void
    {
        $this->seedBusinessWorld();

        $this->actingAs($this->userWithPermissions(['report.business.view', 'billing.view']));
        $this->get(route('admin.reports.business.drill', ['metric' => 'revenue_paid', ...self::JANUARY]))->assertOk();
        $this->get(route('admin.reports.business.drill.export', ['metric' => 'revenue_paid', ...self::JANUARY]))->assertForbidden();

        $this->actingAs($this->userWithPermissions(['report.business.view', 'report.export']));
        $this->get(route('admin.reports.business.drill.export', ['metric' => 'revenue_paid', ...self::JANUARY]))->assertForbidden();

        $this->actingAs($this->userWithPermissions(['report.business.view', 'billing.view', 'report.export']));
        $this->get(route('admin.reports.business.drill.export', ['metric' => 'revenue_paid', ...self::JANUARY]))->assertOk();
    }

    public function test_report_pages_offer_a_drill_link_only_for_lists_the_actor_may_open(): void
    {
        $this->actingAs($this->userWithPermissions(['report.business.view', 'billing.view']));

        $response = $this->get(route('admin.reports.business', self::JANUARY))->assertOk();

        $this->assertSame(
            ['revenue_paid', 'recurring_revenue', 'one_time_revenue', 'outstanding'],
            array_keys($response->inertiaProps('drill')),
        );
        $this->assertStringStartsWith('/admin/reports/business/drill/revenue_paid?', $response->inertiaProps('drill.revenue_paid'));
        $this->assertStringContainsString('date_from=2026-01-01', $response->inertiaProps('drill.revenue_paid'));
        $this->assertFalse($response->inertiaProps('can.export'));

        $this->actingAs($this->admin);
        $this->assertTrue($this->get(route('admin.reports.business'))->inertiaProps('can.export'));
    }

    // ---------------------------------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------------------------------

    /** @param  array<string, mixed>  $query */
    private function drill(string $report, string $metric, array $query = []): TestResponse
    {
        return $this->get(route("admin.reports.$report.drill", ['metric' => $metric, ...$query]));
    }

    private function seedBusinessWorld(): void
    {
        $a = $this->companyId;
        $b = $this->otherCompanyId;

        $this->w['s_active_1'] = $this->worldSubscription($a, 'active', 100000, '2026-01-01 00:00:00');
        $this->w['s_active_2'] = $this->worldSubscription($a, 'active', 200000, '2026-01-31 23:59:59');
        $this->w['s_suspended'] = $this->worldSubscription($a, 'suspended', 50000, '2026-01-15 10:00:00');
        $this->w['s_churn'] = $this->worldSubscription($a, 'terminated', 70000, '2025-06-01 00:00:00', '2026-01-31 23:59:59');
        $this->w['s_terminated_early'] = $this->worldSubscription($a, 'terminated', 80000, '2025-06-01 00:00:00', '2025-12-31 23:59:59');
        $this->w['s_active_old'] = $this->worldSubscription($a, 'active', 400000, '2025-12-31 23:59:59');
        $this->worldSubscription($a, 'active', 900000, '2026-01-20 00:00:00')->delete();
        $this->worldSubscription($b, 'active', 5000000, '2026-01-10 08:00:00');

        $this->w['i_first_day'] = $this->worldInvoice($a, 'recurring', 'paid', 300000, 300000, '2026-01-01');
        $this->w['i_last_day'] = $this->worldInvoice($a, 'recurring', 'paid', 150000, 150000, '2026-01-31');
        $this->w['i_overpaid'] = $this->worldInvoice($a, 'one_time', 'paid', 40000, 46000, '2026-01-15');
        $this->worldInvoice($a, 'recurring', 'paid', 7000000, 7000000, '2025-12-31');
        $this->w['i_partial'] = $this->worldInvoice($a, 'recurring', 'partial', 500000, 200000, '2026-01-10');
        $this->w['i_sent'] = $this->worldInvoice($a, 'recurring', 'sent', 120000, 0, '2026-01-12');
        $this->w['i_overdue'] = $this->worldInvoice($a, 'one_time', 'overdue', 80000, 5000, '2025-11-05');
        $this->worldInvoice($a, 'one_time', 'draft', 999000, 0, '2026-01-20');
        $this->w['i_overpaid_open'] = $this->worldInvoice($a, 'recurring', 'sent', 100000, 130000, '2026-01-13');
        $this->worldInvoice($b, 'recurring', 'paid', 9000000, 9000000, '2026-01-10');

        $this->w['w_first'] = $this->worldWorkOrder($a, 'installation', 'completed', '2026-01-01 00:00:00');
        $this->w['w_last'] = $this->worldWorkOrder($a, 'installation', 'completed', '2026-01-31 23:59:59');
        $this->worldWorkOrder($a, 'installation', 'completed', '2025-12-31 23:59:59');
        $this->worldWorkOrder($a, 'maintenance', 'completed', '2026-01-15 09:00:00');
        $this->worldWorkOrder($a, 'installation', 'in_progress', null);
        $this->worldWorkOrder($b, 'installation', 'completed', '2026-01-15 10:00:00');

        $this->seedSlaWorld();
        $this->seedAssetWorld();
    }

    private function seedSlaWorld(): void
    {
        $a = $this->companyId;
        $this->w['support'] = $this->worldCategory($a, 'Support', 'SUP-1');
        $outage = $this->worldCategory($a, 'Outage', 'OUT');

        $this->w['t_ontime'] = $this->worldTicket($a, $this->w['support'], '2026-01-10 08:00:00', '2026-01-10 08:10:00', '2026-01-10 09:00:00', '2026-01-10 10:00:00');
        $this->w['t_equal'] = $this->worldTicket($a, $this->w['support'], '2026-01-11 08:00:00', null, '2026-01-11 10:00:00', '2026-01-11 10:00:00', 'closed');
        $this->w['t_breach'] = $this->worldTicket($a, $outage, '2026-01-12 08:00:00', '2026-01-12 08:30:00', '2026-01-12 12:00:00', '2026-01-12 11:59:59');
        $this->w['t_nodeadline'] = $this->worldTicket($a, $outage, '2026-01-13 08:00:00', '2026-01-13 08:20:00', '2026-01-13 08:30:00', null);
        $this->worldTicket($a, $this->w['support'], '2026-01-15 08:00:00', null, null, '2026-01-15 12:00:00');
        $this->w['t_december'] = $this->worldTicket($a, $this->w['support'], '2025-12-31 20:00:00', null, '2025-12-31 23:59:59', '2026-01-01 00:00:00');

        $supportB = $this->worldCategory($this->otherCompanyId, 'Support', 'SUP-B');
        $this->worldTicket($this->otherCompanyId, $supportB, '2026-01-05 08:00:00', null, '2026-01-05 09:00:00', '2026-01-05 12:00:00');
    }

    private function seedAssetWorld(): void
    {
        $a = $this->companyId;
        $central = $this->worldLocation($a, 'POP-C', 'POP Central');
        $east = $this->worldLocation($a, 'POP-E', 'POP East');

        $this->worldAsset($a, 'router', 'available', $central);
        $router = $this->worldAsset($a, 'router', 'installed', $central, true);
        $this->worldAsset($a, 'router', 'retired', $central);
        $switch = $this->worldAsset($a, 'switch', 'installed', $east, true);

        $this->w['n_inside'] = $this->worldInstallation($a, $switch, $east, '2026-01-01 00:00:00');
        $this->worldInstallation($a, $router, $central, '2025-12-31 23:59:59');

        $centralB = $this->worldLocation($this->otherCompanyId, 'POP-C', 'POP Central');
        $this->worldAsset($this->otherCompanyId, 'switch', 'available', $centralB);
    }
}
