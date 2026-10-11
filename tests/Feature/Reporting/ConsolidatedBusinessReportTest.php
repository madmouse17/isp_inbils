<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Company;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Reporting\Queries\BusinessMetricsQuery;
use Modules\Reporting\Registry\MetricRegistry;
use Modules\Reporting\Registry\ReportType;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;
use Tests\Traits\BuildsReportingWorld;
use Tests\Traits\ParsesCsvDownloads;

/**
 * Consolidated Business metrics of a Head company over itself and its active subsidiaries.
 * Figures are worked out by hand from the fixtures of seedGroupWorld(), never read back from the
 * query under test: the per-company rows must equal each company's own Business report, and the
 * total must be the sum of those rows.
 */
class ConsolidatedBusinessReportTest extends TestCase
{
    use BuildsCompanyGroup;
    use BuildsReportingWorld;
    use ParsesCsvDownloads;
    use RefreshDatabase;

    private const JANUARY = ['date_from' => '2026-01-01', 'date_to' => '2026-01-31'];

    private User $headAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        // The Consolidated page component belongs to the UI card; no Vite entry exists for it yet.
        $this->withoutVite();
        $this->buildGroup();
        $this->headAdmin = $this->groupUser($this->head);
    }

    // ---------------------------------------------------------------------------------------------
    // Reconciliation
    // ---------------------------------------------------------------------------------------------

    public function test_the_total_is_the_sum_of_the_per_company_figures(): void
    {
        $this->seedGroupWorld();

        $data = $this->page(self::JANUARY)->assertOk()->inertiaProps('data');

        $this->assertSame([$this->head->id, $this->subA->id, $this->subB->id], array_column($data['rows'], 'company_id'));

        foreach (['mrr', 'revenue_paid', 'recurring_revenue', 'one_time_revenue', 'outstanding'] as $money) {
            $sum = '0';
            foreach ($data['rows'] as $row) {
                $sum = Money::add($sum, $row['metrics'][$money]);
            }
            $this->assertSame(Money::round($sum), Money::round($data['total'][$money]), "{$money}: total is not the sum of the rows");
        }

        foreach (['active_subscriptions', 'suspended_subscriptions', 'terminated_subscriptions', 'new_subscriptions', 'churn', 'installation_count'] as $count) {
            $this->assertSame(
                array_sum(array_column(array_column($data['rows'], 'metrics'), $count)),
                $data['total'][$count],
                "{$count}: total is not the sum of the rows",
            );
        }

        $this->assertSame(
            ['available' => 3, 'installed' => 1],
            $data['total']['asset_distribution'],
        );

        // Worked out by hand from seedGroupWorld().
        $this->assertEquals(370000, $data['total']['mrr']);               // active 100000 + 200000 + 70000
        $this->assertEquals(165000, $data['total']['revenue_paid']);      // 100000 + 30000 + 35000
        $this->assertEquals(40000, $data['total']['outstanding']);
        $this->assertSame(3, $data['total']['active_subscriptions']);
        $this->assertSame(1, $data['total']['terminated_subscriptions']);
        $this->assertSame(1, $data['total']['churn']);
    }

    public function test_every_company_row_is_that_companys_own_business_report(): void
    {
        $this->seedGroupWorld();

        $rows = $this->page(self::JANUARY)->assertOk()->inertiaProps('data.rows');

        foreach ($rows as $row) {
            $own = BusinessMetricsQuery::execute('2026-01-01', '2026-01-31', $row['company_id']);
            $this->assertEquals($own, $row['metrics'], 'company '.$row['company_id'].' differs from its own Business report');
        }
    }

    public function test_sla_compliance_is_pooled_from_ticket_counts_not_averaged(): void
    {
        $this->seedGroupWorld();

        $data = $this->page(self::JANUARY)->assertOk()->inertiaProps('data');
        $rates = array_column(array_column($data['rows'], 'metrics'), 'sla_compliance_pct');

        // Head has no resolved tickets, A resolved 2 and met 1, B resolved 1 and met it.
        $this->assertEquals([0, 50.0, 100.0], $rates);
        $this->assertSame(['resolved' => 3, 'on_time' => 2], $data['sla']);
        $this->assertEquals(66.7, $data['total']['sla_compliance_pct']);
    }

    public function test_the_period_filter_reaches_every_company(): void
    {
        $this->seedGroupWorld();

        $none = $this->page(['date_from' => '2024-01-01', 'date_to' => '2024-01-31'])->assertOk()->inertiaProps('data');

        $this->assertSame(0, $none['total']['new_subscriptions']);
        $this->assertEquals(0, $none['total']['revenue_paid']);
        // Snapshot figures ignore the period, like the single-company report.
        $this->assertEquals(370000, $none['total']['mrr']);
    }

    // ---------------------------------------------------------------------------------------------
    // Scope: who is in the report
    // ---------------------------------------------------------------------------------------------

    public function test_companies_outside_the_group_never_appear(): void
    {
        $this->seedGroupWorld();
        $this->worldSubscription($this->outsider->id, 'active', 9999000, '2026-01-10 03:00:00');
        $this->worldInvoice($this->outsider->id, 'recurring', 'paid', 8888000, 8888000, '2026-01-15');

        $props = $this->page(self::JANUARY)->assertOk()->inertiaProps();

        $this->assertNotContains($this->outsider->id, array_column($props['data']['rows'], 'company_id'));
        $this->assertNotContains($this->outsider->id, array_column($props['context']['companies'], 'id'));
        $this->assertEquals(370000, $props['data']['total']['mrr']);
        $this->assertEquals(165000, $props['data']['total']['revenue_paid']);
    }

    public function test_a_head_member_sees_the_subsidiaries_without_being_a_member_of_them(): void
    {
        $this->seedGroupWorld();

        $context = $this->page(self::JANUARY)->assertOk()->inertiaProps('context');

        $this->assertSame('consolidated', $context['mode']);
        $this->assertSame(
            ['id' => $this->head->id, 'name' => 'Group Head', 'code' => 'HDQ', 'currency' => 'IDR'],
            $context['head'],
        );
        $this->assertSame(
            [$this->head->id, $this->subA->id, $this->subB->id],
            array_column($context['companies'], 'id'),
        );
        $this->assertSame(['id', 'name', 'code', 'currency', 'timezone'], array_keys($context['companies'][1]));
    }

    public function test_inactive_subsidiaries_are_left_out(): void
    {
        $this->seedGroupWorld();
        $this->subB->forceFill(['is_active' => false])->save();

        $props = $this->page(self::JANUARY)->assertOk()->inertiaProps();

        $this->assertSame([$this->head->id, $this->subA->id], array_column($props['data']['rows'], 'company_id'));
        $this->assertEquals(300000, $props['data']['total']['mrr']);   // B's 70000 is out
    }

    public function test_a_switched_head_user_gets_the_report_of_the_head_they_are_acting_in(): void
    {
        $this->seedGroupWorld();
        $user = $this->groupUser($this->head, 'admin', $this->subA);

        $this->actingAs($user)->post(route('admin.company.switch'), ['company_id' => $this->subA->id]);
        $this->get(route('admin.reports.consolidated'))->assertForbidden();

        $this->post(route('admin.company.switch'), ['company_id' => $this->head->id]);
        $this->get(route('admin.reports.consolidated', self::JANUARY))->assertOk();
    }

    // ---------------------------------------------------------------------------------------------
    // Currency and timezone
    // ---------------------------------------------------------------------------------------------

    public function test_mixed_currencies_are_refused_instead_of_summed(): void
    {
        $this->seedGroupWorld();
        $this->subB->forceFill(['currency' => 'USD'])->save();

        $props = $this->page(self::JANUARY)->assertOk()->inertiaProps();

        $this->assertNull($props['data']);
        $this->assertSame('currency_mismatch', $props['refusal']['code']);
        $this->assertEqualsCanonicalizing(['IDR', 'USD'], $props['refusal']['currencies']);
        $this->assertStringContainsString('currenc', strtolower($props['refusal']['message']));
        $this->assertSame([$this->head->id, $this->subA->id, $this->subB->id], array_column($props['context']['companies'], 'id'));
    }

    public function test_the_file_is_refused_too_when_currencies_differ(): void
    {
        $this->seedGroupWorld();
        $this->subB->forceFill(['currency' => 'USD'])->save();

        $this->actingAs($this->headAdmin)
            ->get(route('admin.reports.consolidated.export', self::JANUARY))
            ->assertStatus(422);
    }

    public function test_a_consistent_group_reports_its_one_currency(): void
    {
        $this->seedGroupWorld();

        $props = $this->page(self::JANUARY)->assertOk()->inertiaProps();

        $this->assertNull($props['refusal']);
        $this->assertSame('IDR', $props['context']['head']['currency']);
    }

    public function test_date_boundaries_follow_each_companys_timezone(): void
    {
        $this->subB->forceFill(['timezone' => 'America/New_York'])->save();
        $oneDay = ['date_from' => '2026-01-01', 'date_to' => '2026-01-01'];

        // Head, Asia/Jakarta (UTC+7): local 2026-01-01 is 2025-12-31 17:00 .. 2026-01-01 16:59:59 UTC.
        $this->worldSubscription($this->head->id, 'active', 1000, '2025-12-31 18:00:00');   // in: local 01:00 on Jan 1 (UTC says Dec 31)
        $this->worldSubscription($this->head->id, 'active', 1000, '2026-01-01 17:30:00');   // out: local 00:30 on Jan 2 (UTC says Jan 1)

        // Sub B, America/New_York (UTC-5): local 2026-01-01 is 2026-01-01 05:00 .. 2026-01-02 04:59:59 UTC.
        $this->worldSubscription($this->subB->id, 'active', 1000, '2026-01-01 03:00:00');   // out: local 22:00 on Dec 31 (UTC says Jan 1)
        $this->worldSubscription($this->subB->id, 'active', 1000, '2026-01-01 12:00:00');   // in
        $this->worldSubscription($this->subB->id, 'active', 1000, '2026-01-02 02:00:00');   // in: local 21:00 on Jan 1 (UTC says Jan 2)
        $this->worldSubscription($this->subB->id, 'active', 1000, '2026-01-02 06:00:00');   // out: local 01:00 on Jan 2
        // An invoice date is a calendar date: UTC-5 must not shift it out of its own day.
        $this->worldInvoice($this->subB->id, 'one_time', 'paid', 500, 500, '2026-01-01');

        $data = $this->page($oneDay)->assertOk()->inertiaProps('data');
        $byCompany = array_column($data['rows'], 'metrics', 'company_id');

        $this->assertSame(1, $byCompany[$this->head->id]['new_subscriptions']);
        $this->assertSame(0, $byCompany[$this->subA->id]['new_subscriptions']);
        $this->assertSame(2, $byCompany[$this->subB->id]['new_subscriptions']);
        $this->assertSame(3, $data['total']['new_subscriptions']);
        $this->assertEquals(500, $byCompany[$this->subB->id]['revenue_paid']);
    }

    // ---------------------------------------------------------------------------------------------
    // Authorization
    // ---------------------------------------------------------------------------------------------

    public function test_a_subsidiary_user_cannot_open_the_consolidated_report_even_with_the_permission(): void
    {
        $subAdmin = $this->groupUser($this->subA);
        $this->assertTrue($subAdmin->can('report.consolidated.view'));

        $this->actingAs($subAdmin);
        $this->get(route('admin.reports.consolidated'))->assertForbidden();
        $this->get(route('admin.reports.consolidated.export'))->assertForbidden();
    }

    public function test_a_standalone_company_and_a_head_without_subsidiaries_are_not_heads(): void
    {
        $standalone = $this->groupUser($this->outsider);
        $this->actingAs($standalone)->get(route('admin.reports.consolidated'))->assertForbidden();

        $lonelyHead = Company::factory()->create(['code' => 'LON']);
        $lonelyAdmin = $this->groupUser($lonelyHead);
        $this->actingAs($lonelyAdmin)->get(route('admin.reports.consolidated'))->assertForbidden();
    }

    public function test_the_consolidated_permission_is_required_and_is_admin_only(): void
    {
        $manager = $this->groupUser($this->head, 'manager');
        $this->assertFalse($manager->can('report.consolidated.view'));

        $this->actingAs($manager)->get(route('admin.reports.consolidated'))->assertForbidden();
        $this->assertTrue($this->headAdmin->can('report.consolidated.view'));
        $this->assertSame(
            ['admin'],
            Permission::findByName('report.consolidated.view')->roles->pluck('name')->all(),
        );
    }

    public function test_the_file_needs_report_export_on_top_of_the_consolidated_permission(): void
    {
        $this->seedGroupWorld();
        $viewer = User::factory()->create(['company_id' => $this->head->id, 'email_verified_at' => now(), 'is_active' => true]);
        $viewer->givePermissionTo('report.consolidated.view');

        $this->actingAs($viewer);
        $this->get(route('admin.reports.consolidated', self::JANUARY))->assertOk();
        $this->get(route('admin.reports.consolidated.export', self::JANUARY))->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('admin.reports.consolidated'))->assertRedirect(route('login'));
        $this->get(route('admin.reports.consolidated.export'))->assertRedirect(route('login'));
    }

    public function test_dates_are_validated(): void
    {
        $this->actingAs($this->headAdmin)
            ->get(route('admin.reports.consolidated', ['date_from' => '2026-02-01', 'date_to' => '2026-01-01']))
            ->assertSessionHasErrors('date_to');
    }

    // ---------------------------------------------------------------------------------------------
    // Export parity
    // ---------------------------------------------------------------------------------------------

    public function test_the_file_carries_exactly_the_figures_the_screen_shows(): void
    {
        $this->seedGroupWorld();
        $this->actingAs($this->headAdmin);

        $screen = $this->get(route('admin.reports.consolidated', self::JANUARY))->assertOk();
        $records = $this->csvRecords($this->get(route('admin.reports.consolidated.export', self::JANUARY)));

        $byCompany = [];
        foreach ($records as $record) {
            $byCompany[$record['Company']][$record['Metric'].($record['Breakdown'] === '' ? '' : ' / '.$record['Breakdown'])] = $record['Value'];
        }

        $this->assertSame(['Group Head', 'Sub A', 'Sub B', 'Consolidated'], array_keys($byCompany));

        $labels = [];
        foreach (MetricRegistry::forReport(ReportType::Business) as $metric) {
            $labels[$metric->key] = $metric->label;
        }

        $total = $screen->inertiaProps('data.total');
        foreach (['mrr', 'revenue_paid', 'recurring_revenue', 'one_time_revenue', 'outstanding', 'active_subscriptions', 'churn', 'sla_compliance_pct', 'installation_count'] as $key) {
            $this->assertEquals($total[$key], (float) $byCompany['Consolidated'][$labels[$key]], "{$key} in the file differs from the screen");
        }

        foreach ($screen->inertiaProps('data.rows') as $index => $row) {
            $name = ['Group Head', 'Sub A', 'Sub B'][$index];
            foreach (['mrr', 'revenue_paid', 'new_subscriptions'] as $key) {
                $this->assertEquals($row['metrics'][$key], (float) $byCompany[$name][$labels[$key]], "{$key} of {$name} in the file differs from the screen");
            }
        }
    }

    public function test_the_file_labels_scope_filters_unit_and_version_like_every_other_report(): void
    {
        $this->seedGroupWorld();

        $records = collect($this->csvRecords(
            $this->actingAs($this->headAdmin)->get(route('admin.reports.consolidated.export', self::JANUARY))
        ))->where('Company', 'Consolidated')->keyBy(fn (array $record): string => $record['Metric'].'|'.$record['Breakdown']);

        $this->assertSame('Snapshot: period filter not applied', $records['MRR|']['Scope']);
        $this->assertSame('Period: 2026-01-01 to 2026-01-31', $records['Revenue (Paid)|']['Scope']);
        $this->assertSame('date_from=2026-01-01; date_to=2026-01-31', $records['MRR|']['Filters']);
        $this->assertSame('v1', $records['MRR|']['Version']);
        $this->assertSame('currency', $records['MRR|']['Unit']);
    }

    public function test_the_file_is_a_csv_download_named_for_the_report(): void
    {
        $this->seedGroupWorld();

        $response = $this->actingAs($this->headAdmin)->get(route('admin.reports.consolidated.export', self::JANUARY));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/business-consolidated-\d{8}-\d{6}\.csv/', (string) $response->headers->get('Content-Disposition'));
    }

    // ---------------------------------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------------------------------

    private function page(array $query = []): TestResponse
    {
        return $this->actingAs($this->headAdmin)->get(route('admin.reports.consolidated', $query));
    }

    /**
     * January 2026 for the group. Head: MRR 100000, paid 100000, 40000 outstanding. A: MRR 200000,
     * one terminated subscription (churn), paid 30000, tickets T1 on time and T2 late, 1 installation.
     * B: MRR 70000 (created in February, a snapshot figure), paid 35000, ticket T3 on time. Asset
     * statuses: Head 1 available, A 1 installed and 1 available, B 1 available.
     */
    private function seedGroupWorld(): void
    {
        $head = $this->head->id;
        $a = $this->subA->id;
        $b = $this->subB->id;

        $this->worldSubscription($head, 'active', 100000, '2026-01-10 03:00:00');
        $this->worldInvoice($head, 'recurring', 'paid', 100000, 100000, '2026-01-15');
        $this->worldInvoice($head, 'one_time', 'sent', 40000, 0, '2026-01-20');
        $this->worldAsset($head, 'router', 'available');

        $this->worldSubscription($a, 'active', 200000, '2026-01-05 03:00:00');
        $this->worldSubscription($a, 'terminated', 50000, '2025-12-01 03:00:00', '2026-01-20 03:00:00');
        $this->worldInvoice($a, 'one_time', 'paid', 30000, 30000, '2026-01-12');
        $this->worldWorkOrder($a, 'installation', 'completed', '2026-01-12 04:00:00');
        $this->worldAsset($a, 'router', 'installed');
        $this->worldAsset($a, 'router', 'available');
        $category = $this->worldCategory($a, 'Support', 'SUP-A');
        $this->worldTicket($a, $category, '2026-01-10 08:00:00', '2026-01-10 08:30:00', '2026-01-10 10:00:00', '2026-01-10 12:00:00');
        $this->worldTicket($a, $category, '2026-01-11 08:00:00', '2026-01-11 08:30:00', '2026-01-11 14:00:00', '2026-01-11 12:00:00');

        $this->worldSubscription($b, 'active', 70000, '2026-02-01 03:00:00');
        $this->worldInvoice($b, 'recurring', 'paid', 35000, 35000, '2026-01-31');
        $this->worldAsset($b, 'router', 'available');
        $categoryB = $this->worldCategory($b, 'Support', 'SUP-B');
        $this->worldTicket($b, $categoryB, '2026-01-12 07:00:00', '2026-01-12 07:30:00', '2026-01-12 09:00:00', '2026-01-12 12:00:00');
    }
}
