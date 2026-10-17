<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Reporting\Queries\AssetUtilizationQuery;
use Modules\Reporting\Queries\BusinessMetricsQuery;
use Modules\Reporting\Queries\SalesKpiQuery;
use Modules\Reporting\Queries\SlaComplianceQuery;
use Modules\Reporting\Queries\TechnicianPerformanceQuery;
use Modules\Reporting\Support\TimeBuckets;
use Tests\TestCase;
use Tests\Traits\BuildsReportingWorld;
use Tests\Traits\CreatesReportingUsers;

/**
 * What the Business, Sales, SLA, Technician and Asset pages draw besides their registered figures: the
 * time series, the invoice status split, the SLA outcome split and the acquirer bars. Expected values are
 * worked out by hand from the fixtures. Buckets are calendar days of the company timezone (Jakarta, UTC+7,
 * unless a test says otherwise); stored timestamps are UTC.
 */
class ReportSeriesTest extends TestCase
{
    use BuildsReportingWorld;
    use CreatesReportingUsers;
    use RefreshDatabase;

    private User $admin;

    private int $companyId;

    private int $otherCompanyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->admin = $this->userWithRole('admin');
        $this->companyId = (int) $this->admin->company_id;
        $this->otherCompanyId = (int) $this->userWithRole('admin')->company_id;
    }

    // ---------------------------------------------------------------------------------------------
    // Environment
    // ---------------------------------------------------------------------------------------------

    public function test_mysql_converts_named_time_zones(): void
    {
        // NULL here means the server has no time zone tables (mysql_tzinfo_to_sql): company-zone buckets would be empty.
        $this->assertSame('2026-01-01 07:00:00', DB::selectOne("SELECT CONVERT_TZ('2026-01-01 00:00:00', 'UTC', 'Asia/Jakarta') AS local")->local);
    }

    public function test_the_buckets_of_a_company_use_its_timezone(): void
    {
        $this->assertSame('Asia/Jakarta', TimeBuckets::timezoneOf($this->companyId));

        Company::query()->whereKey($this->companyId)->update(['timezone' => 'Asia/Jayapura']);

        $this->assertSame('Asia/Jayapura', TimeBuckets::timezoneOf($this->companyId));
    }

    // ---------------------------------------------------------------------------------------------
    // Business
    // ---------------------------------------------------------------------------------------------

    public function test_business_series_per_day_has_paid_revenue_and_mrr_at_the_end_of_each_day(): void
    {
        $a = $this->companyId;

        // Paid invoices dated by issue date. Columns: type, status, total, paid_amount, issue_date.
        $this->worldInvoice($a, 'recurring', 'paid', 100000, 100000, '2026-01-05');
        $this->worldInvoice($a, 'one_time', 'paid', 200000, 200000, '2026-01-07');
        $this->worldInvoice($a, 'recurring', 'paid', 40000, 50000, '2026-01-07');   // overpaid: revenue is paid_amount
        $this->worldInvoice($a, 'recurring', 'paid', 30000, 30000, '2026-01-11');   // last day of the period
        $this->worldInvoice($a, 'recurring', 'paid', 700000, 700000, '2026-01-04'); // the day before
        $this->worldInvoice($a, 'recurring', 'paid', 800000, 800000, '2026-01-12'); // the day after
        $this->worldInvoice($a, 'recurring', 'sent', 999000, 0, '2026-01-06');      // not paid: no revenue
        $this->worldInvoice($this->otherCompanyId, 'recurring', 'paid', 555000, 555000, '2026-01-05');

        // Subscriptions. Columns: status, MRC, activation date [, terminated_at (UTC)].
        $this->activated($a, 'active', 100000, '2025-12-01');                              // live all week
        $this->activated($a, 'active', 50000, '2026-01-07');                               // live from the 7th
        $this->activated($a, 'terminated', 70000, '2025-11-01', '2026-01-08 10:00:00');    // 17:00 on the 8th in Jakarta: gone at the end of the 8th
        $this->activated($a, 'terminated', 30000, '2025-11-01', '2026-01-08 17:30:00');    // 00:30 on the 9th in Jakarta: there at the end of the 8th, gone at the end of the 9th
        $this->worldSubscription($a, 'pending', 999999, '2026-01-02 00:00:00');            // never activated: never MRR
        $this->activated($this->otherCompanyId, 'active', 5000000, '2025-12-01');

        $charts = BusinessMetricsQuery::charts('2026-01-05', '2026-01-11', $a);

        $this->assertSame('day', $charts['series']['granularity']);
        $this->assertSame(
            ['2026-01-05', '2026-01-06', '2026-01-07', '2026-01-08', '2026-01-09', '2026-01-10', '2026-01-11'],
            array_column($charts['series']['points'], 'bucket'),
        );
        $this->assertSame(['5 Jan', '6 Jan', '7 Jan', '8 Jan', '9 Jan', '10 Jan', '11 Jan'], array_column($charts['series']['points'], 'label'));
        $this->assertEquals([100000, 0, 250000, 0, 0, 0, 30000], array_column($charts['series']['points'], 'revenue_paid'));
        $this->assertEquals([200000, 200000, 250000, 180000, 150000, 150000, 150000], array_column($charts['series']['points'], 'mrr'));
        $this->assertEquals(
            BusinessMetricsQuery::execute('2026-01-05', '2026-01-11', $a)['revenue_paid'],
            array_sum(array_column($charts['series']['points'], 'revenue_paid')),
            'the buckets add up to the Revenue figure',
        );
    }

    public function test_business_series_per_week_runs_monday_to_sunday_and_the_last_week_stops_at_the_period_end(): void
    {
        $a = $this->companyId;
        $this->worldInvoice($a, 'recurring', 'paid', 100000, 100000, '2026-01-06');
        $this->worldInvoice($a, 'recurring', 'paid', 20000, 20000, '2026-01-12');   // Monday
        $this->worldInvoice($a, 'recurring', 'paid', 5000, 5000, '2026-01-18');     // Sunday of the same week
        $this->worldInvoice($a, 'recurring', 'paid', 7000, 7000, '2026-02-15');     // last day of the period
        $this->activated($a, 'active', 100000, '2025-12-01');
        $this->activated($a, 'active', 40000, '2026-02-12');                        // activated inside the last week, before the period end
        $this->activated($a, 'active', 9000, '2026-02-16');                         // activated the day after the period

        $series = BusinessMetricsQuery::charts('2026-01-05', '2026-02-15', $a)['series'];

        $this->assertSame('week', $series['granularity']);
        $this->assertSame(
            ['2026-01-05', '2026-01-12', '2026-01-19', '2026-01-26', '2026-02-02', '2026-02-09'],
            array_column($series['points'], 'bucket'),
        );
        $this->assertSame(['W02 2026', 'W03 2026', 'W04 2026', 'W05 2026', 'W06 2026', 'W07 2026'], array_column($series['points'], 'label'));
        $this->assertEquals([100000, 25000, 0, 0, 0, 7000], array_column($series['points'], 'revenue_paid'));
        $this->assertEquals([100000, 100000, 100000, 100000, 100000, 140000], array_column($series['points'], 'mrr'));
    }

    public function test_business_series_per_month_for_a_long_period(): void
    {
        $a = $this->companyId;
        $this->worldInvoice($a, 'recurring', 'paid', 10000, 10000, '2025-09-15');
        $this->worldInvoice($a, 'recurring', 'paid', 20000, 20000, '2025-11-30');
        $this->worldInvoice($a, 'recurring', 'paid', 5000, 5000, '2026-03-31');
        $this->activated($a, 'active', 60000, '2025-10-10');

        $series = BusinessMetricsQuery::charts('2025-09-01', '2026-03-31', $a)['series'];

        $this->assertSame('month', $series['granularity']);
        $this->assertSame(['Sep 2025', 'Okt 2025', 'Nov 2025', 'Des 2025', 'Jan 2026', 'Feb 2026', 'Mar 2026'], array_column($series['points'], 'label'));
        $this->assertEquals([10000, 0, 20000, 0, 0, 0, 5000], array_column($series['points'], 'revenue_paid'));
        $this->assertEquals([0, 60000, 60000, 60000, 60000, 60000, 60000], array_column($series['points'], 'mrr'));
    }

    public function test_business_series_without_a_range_runs_per_month_from_the_oldest_row_to_today(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-10 05:00:00', 'UTC'));
        $a = $this->companyId;
        $this->worldInvoice($a, 'recurring', 'paid', 10000, 10000, '2025-12-20');
        $this->activated($a, 'active', 60000, '2025-11-05');   // older than any invoice
        $this->worldInvoice($this->otherCompanyId, 'recurring', 'paid', 1, 1, '2020-01-01');

        $series = BusinessMetricsQuery::charts(null, null, $a)['series'];

        $this->assertSame('month', $series['granularity']);
        $this->assertSame(['2025-11-01', '2025-12-01', '2026-01-01', '2026-02-01', '2026-03-01'], array_column($series['points'], 'bucket'));
        $this->assertEquals([0, 10000, 0, 0, 0], array_column($series['points'], 'revenue_paid'));
        $this->assertEquals([60000, 60000, 60000, 60000, 60000], array_column($series['points'], 'mrr'));
    }

    public function test_business_series_with_only_a_start_runs_to_today_and_leaves_future_dated_rows_out(): void
    {
        // 03:00 UTC on 8 January is 10:00 in Jakarta: today is the 8th.
        $this->travelTo(CarbonImmutable::parse('2026-01-08 03:00:00', 'UTC'));
        $a = $this->companyId;
        $this->worldInvoice($a, 'recurring', 'paid', 100, 100, '2026-01-05');
        $this->worldInvoice($a, 'recurring', 'paid', 50, 50, '2026-01-08');
        $this->worldInvoice($a, 'recurring', 'paid', 9000, 9000, '2026-01-20');   // future: filtered in by the open end, not drawn

        $series = BusinessMetricsQuery::charts('2026-01-05', null, $a)['series'];

        $this->assertSame(['2026-01-05', '2026-01-06', '2026-01-07', '2026-01-08'], array_column($series['points'], 'bucket'));
        $this->assertEquals([100, 0, 0, 50], array_column($series['points'], 'revenue_paid'));
    }

    public function test_business_series_with_only_an_end_starts_at_the_oldest_row_and_mrr_stops_at_that_end(): void
    {
        $a = $this->companyId;
        $this->worldInvoice($a, 'recurring', 'paid', 10000, 10000, '2025-11-20');
        $this->worldInvoice($a, 'recurring', 'paid', 7000, 7000, '2026-01-09');
        $this->worldInvoice($a, 'recurring', 'paid', 99000, 99000, '2026-01-11');   // after the end
        $this->activated($a, 'active', 60000, '2025-12-01');
        $this->activated($a, 'active', 40000, '2026-01-15');                        // after the end: not in January's MRR

        $series = BusinessMetricsQuery::charts(null, '2026-01-10', $a)['series'];

        $this->assertSame('month', $series['granularity']);
        $this->assertSame(['2025-11-01', '2025-12-01', '2026-01-01'], array_column($series['points'], 'bucket'));
        $this->assertEquals([10000, 0, 7000], array_column($series['points'], 'revenue_paid'));
        $this->assertEquals([0, 60000, 60000], array_column($series['points'], 'mrr'));
    }

    public function test_business_invoice_status_counts_the_invoices_of_the_period_in_status_order(): void
    {
        $a = $this->companyId;
        foreach ([['draft', 0], ['sent', 0], ['sent', 0], ['partial', 10], ['paid', 100], ['paid', 100], ['paid', 100], ['overdue', 0], ['cancelled', 0], ['cancelled', 0]] as [$status, $paid]) {
            $this->worldInvoice($a, 'recurring', $status, 100, $paid, '2026-01-10');
        }
        $this->worldInvoice($a, 'recurring', 'sent', 100, 0, '2026-02-03');                        // outside January
        $this->worldInvoice($a, 'recurring', 'paid', 100, 100, '2026-01-12')->delete();             // soft-deleted
        $this->worldInvoice($this->otherCompanyId, 'recurring', 'paid', 100, 100, '2026-01-10');   // another company

        $january = BusinessMetricsQuery::charts('2026-01-01', '2026-01-31', $a)['invoice_status'];
        $allTime = BusinessMetricsQuery::charts(null, null, $a)['invoice_status'];

        $this->assertSame([
            ['status' => 'draft', 'count' => 1],
            ['status' => 'sent', 'count' => 2],
            ['status' => 'partial', 'count' => 1],
            ['status' => 'paid', 'count' => 3],
            ['status' => 'overdue', 'count' => 1],
            ['status' => 'cancelled', 'count' => 2],
        ], $january);
        $this->assertSame(['sent' => 3, 'paid' => 3], collect($allTime)->whereIn('status', ['sent', 'paid'])->pluck('count', 'status')->all());
    }

    public function test_business_series_of_a_company_without_records_is_all_zero_and_its_status_list_is_empty(): void
    {
        $charts = BusinessMetricsQuery::charts('2026-01-05', '2026-01-08', $this->companyId);

        $this->assertCount(4, $charts['series']['points']);
        $this->assertSame([0, 0, 0, 0], array_column($charts['series']['points'], 'revenue_paid'));
        $this->assertEquals([0, 0, 0, 0], array_column($charts['series']['points'], 'mrr'));
        $this->assertSame([], $charts['invoice_status']);
    }

    public function test_business_page_passes_the_charts_next_to_the_registered_figures(): void
    {
        $this->worldInvoice($this->companyId, 'recurring', 'paid', 100, 100, '2026-01-06');

        $props = $this->actingAs($this->admin)
            ->get(route('admin.reports.business', ['date_from' => '2026-01-05', 'date_to' => '2026-01-11']))
            ->assertOk()->inertiaProps();

        $this->assertEqualsCanonicalizing(['series', 'invoice_status'], array_keys($props['charts']));
        $this->assertCount(7, $props['charts']['series']['points']);
        $this->assertSame([['status' => 'paid', 'count' => 1]], $props['charts']['invoice_status']);
        $this->assertArrayNotHasKey('series', $props['data'], 'every key of data stays a registered figure');
        $this->assertArrayNotHasKey('invoice_status', $props['data']);
    }

    // ---------------------------------------------------------------------------------------------
    // Technician
    // ---------------------------------------------------------------------------------------------

    public function test_technician_series_buckets_completed_work_orders_by_day_of_the_company_timezone(): void
    {
        $a = $this->companyId;
        $technician = $this->technician($a);
        $other = $this->technician($a);

        // completed_at is UTC; Jakarta is 7 hours ahead.
        $this->worldWorkOrder($a, 'installation', 'completed', '2026-01-04 17:00:00', $technician->id);   // 00:00 on the 5th: first second of the period
        $this->worldWorkOrder($a, 'maintenance', 'completed', '2026-01-05 03:00:00', $technician->id);    // the 5th
        $this->worldWorkOrder($a, 'maintenance', 'completed', '2026-01-07 05:00:00', $technician->id);    // the 7th
        $this->worldWorkOrder($a, 'maintenance', 'completed', '2026-01-11 16:59:59', $technician->id);    // 23:59:59 on the 11th: last second
        $this->worldWorkOrder($a, 'maintenance', 'completed', '2026-01-11 17:00:00', $technician->id);    // 00:00 on the 12th: out
        $this->worldWorkOrder($a, 'maintenance', 'completed', '2026-01-04 16:59:59', $technician->id);    // 23:59:59 on the 4th: out
        $this->worldWorkOrder($a, 'maintenance', 'completed', '2026-01-06 05:00:00', $other->id);         // another technician
        $this->worldWorkOrder($a, 'maintenance', 'in_progress', null, $technician->id);                   // not completed
        $this->worldWorkOrder($this->otherCompanyId, 'maintenance', 'completed', '2026-01-08 05:00:00', $technician->id);   // another company

        $series = TechnicianPerformanceQuery::charts($technician->id, '2026-01-05', '2026-01-11', $a)['series'];

        $this->assertSame('day', $series['granularity']);
        $this->assertSame(
            ['2026-01-05', '2026-01-06', '2026-01-07', '2026-01-08', '2026-01-09', '2026-01-10', '2026-01-11'],
            array_column($series['points'], 'bucket'),
        );
        $this->assertSame([2, 0, 1, 0, 0, 0, 1], array_column($series['points'], 'spk_completed'));
    }

    public function test_technician_series_follows_the_timezone_of_the_company(): void
    {
        Company::query()->whereKey($this->companyId)->update(['timezone' => 'UTC']);
        $technician = $this->technician($this->companyId);
        $this->worldWorkOrder($this->companyId, 'maintenance', 'completed', '2026-01-04 17:00:00', $technician->id);   // the 4th in UTC: out
        $this->worldWorkOrder($this->companyId, 'maintenance', 'completed', '2026-01-05 17:00:00', $technician->id);   // the 5th in UTC

        $series = TechnicianPerformanceQuery::charts($technician->id, '2026-01-05', '2026-01-06', $this->companyId)['series'];

        $this->assertSame([1, 0], array_column($series['points'], 'spk_completed'));
    }

    public function test_technician_series_adds_up_to_the_completed_figure_when_no_row_sits_near_midnight(): void
    {
        $technician = $this->technician($this->companyId);
        foreach (['2026-01-02 05:00:00', '2026-01-02 09:00:00', '2026-01-20 02:00:00', '2026-02-10 08:00:00'] as $completedAt) {
            $this->worldWorkOrder($this->companyId, 'maintenance', 'completed', $completedAt, $technician->id);
        }
        $this->actingAs($this->admin);

        $figure = TechnicianPerformanceQuery::execute($technician->id, '2026-01-01', '2026-02-28', $this->companyId)['spk_completed'];
        $series = TechnicianPerformanceQuery::charts($technician->id, '2026-01-01', '2026-02-28', $this->companyId)['series'];

        $this->assertSame(4, $figure);
        $this->assertSame('week', $series['granularity']);
        $this->assertSame($figure, array_sum(array_column($series['points'], 'spk_completed')));
    }

    public function test_technician_series_without_a_range_keeps_36_months_and_folds_the_older_ones_into_the_first(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-02-10 05:00:00', 'UTC'));
        $technician = $this->technician($this->companyId);
        foreach (['2022-01-15 05:00:00', '2022-02-10 05:00:00', '2023-03-20 05:00:00', '2026-01-05 05:00:00'] as $completedAt) {
            $this->worldWorkOrder($this->companyId, 'maintenance', 'completed', $completedAt, $technician->id);
        }

        $series = TechnicianPerformanceQuery::charts($technician->id, null, null, $this->companyId)['series'];

        $this->assertSame('month', $series['granularity']);
        $this->assertCount(36, $series['points']);
        $this->assertSame('2023-03-01', $series['points'][0]['bucket']);
        $this->assertSame('2026-02-01', $series['points'][35]['bucket']);
        $this->assertSame(3, $series['points'][0]['spk_completed'], 'two older months and March 2023 itself');
        $this->assertSame(1, $series['points'][34]['spk_completed']);
        $this->assertSame(4, array_sum(array_column($series['points'], 'spk_completed')));
    }

    public function test_technician_page_has_no_charts_without_a_technician_and_the_series_with_one(): void
    {
        $technician = $this->technician($this->companyId);
        $this->worldWorkOrder($this->companyId, 'maintenance', 'completed', '2026-01-06 05:00:00', $technician->id);

        $this->actingAs($this->admin);
        $without = $this->get(route('admin.reports.technician'))->assertOk()->inertiaProps();
        $with = $this->get(route('admin.reports.technician', ['technician_id' => $technician->public_id, 'date_from' => '2026-01-05', 'date_to' => '2026-01-11']))
            ->assertOk()->inertiaProps();

        $this->assertNull($without['charts']);
        $this->assertSame(['series'], array_keys($with['charts']));
        $this->assertSame([0, 1, 0, 0, 0, 0, 0], array_column($with['charts']['series']['points'], 'spk_completed'));
        $this->assertArrayHasKey('spk_by_type', $with['data']);
        $this->assertArrayNotHasKey('series', $with['data']);
    }

    // ---------------------------------------------------------------------------------------------
    // Sales
    // ---------------------------------------------------------------------------------------------

    public function test_sales_series_counts_new_customers_per_day_of_the_company_timezone(): void
    {
        $a = $this->companyId;
        $ayu = User::factory()->create(['company_id' => $a, 'is_active' => true, 'name' => 'Ayu']);
        $bima = User::factory()->create(['company_id' => $a, 'is_active' => true, 'name' => 'Bima']);

        $this->customerAt($a, '2026-01-04 17:00:00', $ayu);   // 00:00 on the 5th in Jakarta: first second of the period
        $this->customerAt($a, '2026-01-05 03:00:00', $ayu);
        $this->customerAt($a, '2026-01-07 05:00:00', $bima);
        $this->customerAt($a, '2026-01-09 05:00:00', null);   // unassigned still counts as a new customer
        $this->customerAt($a, '2026-01-11 17:00:00', $bima);  // 00:00 on the 12th: out
        $this->customerAt($a, '2026-01-06 05:00:00', $bima)->delete();   // soft-deleted: not a customer acquired
        $this->customerAt($this->otherCompanyId, '2026-01-05 05:00:00', null);

        $series = SalesKpiQuery::charts(['rows' => []], $a, '2026-01-05', '2026-01-11 23:59:59')['series'];
        $ayuOnly = SalesKpiQuery::charts(['rows' => []], $a, '2026-01-05', '2026-01-11 23:59:59', $ayu->id)['series'];

        $this->assertSame('day', $series['granularity']);
        $this->assertSame([2, 0, 1, 0, 1, 0, 0], array_column($series['points'], 'new_customers'));
        $this->assertSame([2, 0, 0, 0, 0, 0, 0], array_column($ayuOnly['points'], 'new_customers'));
    }

    public function test_sales_series_adds_up_to_the_total_and_the_bars_list_every_acquirer(): void
    {
        $a = $this->companyId;
        $ayu = User::factory()->create(['company_id' => $a, 'is_active' => true, 'name' => 'Ayu']);
        $bima = User::factory()->create(['company_id' => $a, 'is_active' => true, 'name' => 'Bima']);
        $this->customerAt($a, '2026-01-05 05:00:00', $ayu);
        $this->customerAt($a, '2026-01-06 05:00:00', $ayu);
        $this->customerAt($a, '2026-01-09 05:00:00', $bima);
        $this->customerAt($a, '2026-01-10 05:00:00', null);

        $report = SalesKpiQuery::execute($a, '2026-01-05', '2026-01-11 23:59:59');
        $charts = SalesKpiQuery::charts($report, $a, '2026-01-05', '2026-01-11 23:59:59');

        $this->assertSame(4, $report['totals']['customers_acquired']);
        $this->assertSame(4, array_sum(array_column($charts['series']['points'], 'new_customers')));
        $this->assertEquals([
            ['name' => 'Ayu', 'customers_acquired' => 2, 'collected' => 0],
            ['name' => 'Bima', 'customers_acquired' => 1, 'collected' => 0],
        ], $charts['acquirers']);

        $ayuOnly = SalesKpiQuery::charts(
            SalesKpiQuery::execute($a, '2026-01-05', '2026-01-11 23:59:59', $ayu->id),
            $a,
            '2026-01-05',
            '2026-01-11 23:59:59',
            $ayu->id,
        );
        $this->assertSame(['Ayu'], array_column($ayuOnly['acquirers'], 'name'));
        $this->assertSame(2, array_sum(array_column($ayuOnly['series']['points'], 'new_customers')));
    }

    public function test_sales_page_charts_every_acquirer_not_only_the_page_of_rows(): void
    {
        $a = $this->companyId;
        foreach (range(1, 12) as $number) {
            $acquirer = User::factory()->create(['company_id' => $a, 'is_active' => true, 'name' => sprintf('Seller %02d', $number)]);
            $this->customerAt($a, '2026-01-06 05:00:00', $acquirer);
        }

        $props = $this->actingAs($this->admin)
            ->get(route('admin.reports.sales', ['per_page' => 10, 'date_from' => '2026-01-05', 'date_to' => '2026-01-11']))
            ->assertOk()->inertiaProps();

        $this->assertCount(10, $props['data']['rows']['data']);
        $this->assertCount(12, $props['charts']['acquirers']);
        $this->assertSame('Seller 12', $props['charts']['acquirers'][11]['name']);
        $this->assertSame(12, array_sum(array_column($props['charts']['series']['points'], 'new_customers')));
        $this->assertEqualsCanonicalizing(['series', 'acquirers'], array_keys($props['charts']));
    }

    // ---------------------------------------------------------------------------------------------
    // SLA
    // ---------------------------------------------------------------------------------------------

    public function test_sla_compliance_splits_the_resolved_tickets_of_the_period_by_outcome(): void
    {
        $a = $this->companyId;
        $category = $this->worldCategory($a, 'Gangguan', 'GGN');
        $this->worldTicket($a, $category, '2026-01-10 01:00:00', null, '2026-01-10 05:00:00', '2026-01-10 06:00:00');   // on time
        $this->worldTicket($a, $category, '2026-01-12 01:00:00', null, '2026-01-12 05:00:00', '2026-01-12 05:00:00');   // on the deadline: on time
        $this->worldTicket($a, $category, '2026-01-15 01:00:00', null, '2026-01-15 09:00:00', '2026-01-15 06:00:00');   // late
        $this->worldTicket($a, $category, '2026-01-16 01:00:00', null, '2026-01-16 05:00:00', null);                    // no deadline: neither
        $this->worldTicket($a, $category, '2026-02-04 01:00:00', null, '2026-02-05 05:00:00', '2026-02-04 05:00:00');   // late, but in February
        $this->worldTicket($a, $category, '2026-01-14 01:00:00', null, null, '2026-01-14 05:00:00');                    // not resolved

        $payload = SlaComplianceQuery::execute('2026-01-01', '2026-01-31', null, $a);
        $charts = SlaComplianceQuery::charts($payload);

        $this->assertSame(['compliance' => ['compliant' => 2, 'breached' => 1]], $charts);
        $this->assertSame(['compliance' => ['compliant' => 0, 'breached' => 0]], SlaComplianceQuery::charts(SlaComplianceQuery::execute('2025-01-01', '2025-01-31', null, $a)));
    }

    public function test_sla_page_passes_the_compliance_next_to_the_registered_figures(): void
    {
        $a = $this->companyId;
        $category = $this->worldCategory($a, 'Gangguan', 'GGN');
        $this->worldTicket($a, $category, '2026-01-10 01:00:00', null, '2026-01-10 05:00:00', '2026-01-10 06:00:00');
        $this->worldTicket($a, $category, '2026-01-15 01:00:00', null, '2026-01-15 09:00:00', '2026-01-15 06:00:00');

        $props = $this->actingAs($this->admin)
            ->get(route('admin.reports.sla', ['date_from' => '2026-01-01', 'date_to' => '2026-01-31']))
            ->assertOk()->inertiaProps();

        $this->assertSame(['compliance' => ['compliant' => 1, 'breached' => 1]], $props['charts']);
        $this->assertSame(1, $props['data']['sla_compliant']);
        $this->assertSame(1, $props['data']['breach_count']);
        $this->assertArrayNotHasKey('compliance', $props['data']);
        $this->assertIsList($props['data']['by_category']);
    }

    // ---------------------------------------------------------------------------------------------
    // Asset
    // ---------------------------------------------------------------------------------------------

    public function test_asset_report_keeps_its_status_and_location_maps_and_adds_no_charts_prop(): void
    {
        $a = $this->companyId;
        $pusat = $this->worldLocation($a, 'LOC-1', 'Pusat');
        $cabang = $this->worldLocation($a, 'LOC-2', 'Cabang');
        $this->worldAsset($a, 'router', 'installed', $pusat);
        $this->worldAsset($a, 'router', 'available', $pusat);
        $this->worldAsset($a, 'onu_ont', 'available', $cabang);
        $this->worldAsset($this->otherCompanyId, 'router', 'retired', $this->worldLocation($this->otherCompanyId, 'LOC-9', 'Asing'));

        $payload = AssetUtilizationQuery::execute(null, null, null, null, $a);

        $this->assertEquals(['installed' => 1, 'available' => 2], $payload['status_distribution']);
        $this->assertEquals(['Pusat' => 2, 'Cabang' => 1], $payload['by_location']);
        $empty = AssetUtilizationQuery::execute(null, null, null, null, (int) Company::factory()->create()->id);
        $this->assertSame([], $empty['status_distribution']);
        $this->assertSame([], $empty['by_location']);

        $props = $this->actingAs($this->admin)->get(route('admin.reports.asset'))->assertOk()->inertiaProps();

        $this->assertArrayNotHasKey('charts', $props);
        $this->assertEquals(['installed' => 1, 'available' => 2], $props['data']['status_distribution']);
    }

    // ---------------------------------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------------------------------

    /** A subscription with its activation date set (the world builder has none). */
    private function activated(int $companyId, string $status, int $mrc, string $activationDate, ?string $terminatedAt = null): ServiceSubscription
    {
        $subscription = $this->worldSubscription($companyId, $status, $mrc, '2025-01-01 00:00:00', $terminatedAt);
        $subscription->forceFill(['activation_date' => $activationDate])->saveQuietly();

        return $subscription;
    }

    private function technician(int $companyId): User
    {
        $technician = User::factory()->create(['company_id' => $companyId, 'is_active' => true]);
        $technician->assignRole('technician');
        EmployeeProfile::factory()->create(['company_id' => $companyId, 'user_id' => $technician->id, 'status' => 'active']);

        return $technician;
    }

    private function customerAt(int $companyId, string $createdAt, ?User $acquirer): Customer
    {
        return Customer::factory()->create([
            'company_id' => $companyId,
            'acquired_by_user_id' => $acquirer?->id,
            'created_at' => $createdAt,
        ]);
    }
}
