<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\NetworkAssetInstallation;
use Modules\Reporting\Queries\AssetUtilizationQuery;
use Modules\Reporting\Queries\BusinessMetricsQuery;
use Modules\Reporting\Queries\SlaComplianceQuery;
use Modules\Reporting\Registry\MetricRegistry;
use Modules\Reporting\Registry\ReportType;
use Modules\Service\Models\ServicePackage;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Tests\TestCase;

/**
 * Characterization of the v1 formulas of the Business, SLA and Asset reports.
 *
 * Every test builds a small world in which each row exists for a stated reason, then pins the exact
 * figures the queries return TODAY, quirks included: snapshot versus period figures, the
 * both-dates-or-ignore range, revenue dated by issue date, the all-time Business SLA figure, the
 * unfiltered by_category and by_location breakdowns. Expected values are worked out by hand from the
 * formulas documented in MetricRegistry, never copied from query output. Company B owns rows that
 * must never reach company A, and the reverse.
 *
 * VERSION CONTRACT: these expectations ARE v1 of every metric they assert. A change to what a metric
 * computes MUST bump that metric's version in MetricRegistry (v2, ...) and update this test together
 * with it. Each payload assertion below first checks that the metrics it asserts are still registered
 * as v1, so a version bump fails here before any number is compared, and a payload key without a
 * characterization fails the full-payload comparison.
 */
class ReportFormulaV1Test extends TestCase
{
    use RefreshDatabase;

    /** Every metric this suite characterizes; the guard test compares it with the registry. */
    private const CHARACTERIZED = [
        'business' => [
            'mrr', 'active_subscriptions', 'suspended_subscriptions', 'terminated_subscriptions',
            'new_subscriptions', 'churn', 'revenue_paid', 'recurring_revenue', 'one_time_revenue',
            'outstanding', 'asset_distribution', 'sla_compliance_pct', 'installation_count',
        ],
        'sla' => [
            'total_resolved', 'sla_compliant', 'sla_rate', 'breach_count',
            'response_on_time', 'response_breached', 'response_without_target', 'response_rate',
            'avg_resolution_minutes', 'avg_frt_minutes', 'by_category',
        ],
        'asset' => [
            'status_distribution', 'by_location', 'installation_count', 'customer_linked_count', 'retired_count',
        ],
    ];

    private Company $companyA;

    private Company $companyB;

    /** @var array<int, User> company id => the user acting for that company */
    private array $users = [];

    /** @var array<int, int> company id => customer id */
    private array $customerIds = [];

    /** @var array<int, int> company id => installation address id */
    private array $addressIds = [];

    private ?int $packageId = null;

    private int $ticketSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // The edge fixtures below are pinned to calendar days of the storage timezone (UTC); the Jakarta case has its own test.
        $this->companyA = Company::factory()->create(['is_active' => true, 'timezone' => 'UTC']);
        $this->companyB = Company::factory()->create(['is_active' => true, 'timezone' => 'UTC']);

        foreach ([$this->companyA, $this->companyB] as $company) {
            $this->users[$company->id] = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        }

        // Fixtures are written while acting as company A (the first company, which the package factory
        // needs); company B rows carry an explicit company_id. Queries switch with actingAsCompany().
        $this->actingAsCompany($this->companyA);
    }

    // ---------------------------------------------------------------------------------------------
    // Version guard
    // ---------------------------------------------------------------------------------------------

    /**
     * A formula change must bump the metric version in MetricRegistry and update this test. This guard
     * fails when a characterized metric leaves v1, when the registry gains a Business, SLA or Asset
     * metric this suite does not characterize, or when it loses one.
     */
    public function test_every_metric_characterized_here_is_registered_at_v1(): void
    {
        foreach (self::CHARACTERIZED as $report => $keys) {
            $type = ReportType::from($report);

            $registered = array_map(fn ($metric): string => $metric->key, MetricRegistry::forReport($type));
            $this->assertEqualsCanonicalizing($keys, $registered, "{$report}: characterized metrics differ from the registry");
            $this->assertMetricsAreV1($type, $keys);
            $this->assertSame("{$report}.v1", MetricRegistry::formulaVersion($type));
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Business
    // ---------------------------------------------------------------------------------------------

    public function test_business_figures_for_a_closed_period(): void
    {
        $this->seedBusinessWorld();

        $this->assertPayload(ReportType::Business, $this->businessFiguresOfA([
            'new_subscriptions' => 5,       // S1 and S2 sit on both edges of January; S4 suspended, S9 pending and S12 terminated count too: any status
            'churn' => 3,                   // S5 on the last second, S6 on the first second, S12 mid-month; a count, not a rate
            'revenue_paid' => 496000,       // I1 300000 + I2 150000 + I3 46000 (paid_amount, not its total of 40000)
            'recurring_revenue' => 450000,  // I1 + I2
            'one_time_revenue' => 46000,    // I3
            'installation_count' => 3,      // W1, W2 and W7: completed installation work orders
        ]), BusinessMetricsQuery::execute('2026-01-01', '2026-01-31'));
    }

    public function test_business_period_figures_cover_every_record_when_no_range_is_given(): void
    {
        $this->seedBusinessWorld();

        $this->assertPayload(
            ReportType::Business,
            $this->businessFiguresOfAWithoutRange(),
            BusinessMetricsQuery::execute(),
        );
    }

    public function test_business_applies_a_range_with_only_one_end(): void
    {
        $this->seedBusinessWorld();

        // From alone: everything created, terminated, issued or completed from 2026-01-01 on.
        $this->assertPayload(
            ReportType::Business,
            $this->businessFiguresOfA([
                'new_subscriptions' => 6,       // S1 S2 S4 S5 S9 S12
                'churn' => 4,                   // S5 S6 S8 S12
                'revenue_paid' => 1096000,      // I1 300000 + I2 150000 + I3 46000 + I5 600000
                'recurring_revenue' => 450000,  // I1 + I2
                'one_time_revenue' => 646000,   // I3 + I5
                'installation_count' => 4,      // W1 W2 W4 W7
            ]),
            BusinessMetricsQuery::execute(dateFrom: '2026-01-01'),
            'only From',
        );
        // To alone: everything up to the last second of 2026-01-31.
        $this->assertPayload(
            ReportType::Business,
            $this->businessFiguresOfA([
                'new_subscriptions' => 11,      // every subscription but S5 (created 2026-02-01)
                'churn' => 4,                   // S5 S6 S7 S12; S8 is one second late, S11 has no date
                'revenue_paid' => 7496000,      // I1 + I2 + I3 + I4 7000000
                'recurring_revenue' => 7450000, // I1 + I2 + I4
                'one_time_revenue' => 46000,    // I3
                'installation_count' => 4,      // W1 W2 W3 W7
            ]),
            BusinessMetricsQuery::execute(dateTo: '2026-01-31'),
            'only To',
        );
    }

    public function test_business_range_includes_the_first_and_last_day_up_to_23_59_59(): void
    {
        $this->seedBusinessWorld();

        // One-day ranges isolate each edge; the neighbours one second outside (S3, S5, S7, S8, I4, I5,
        // W3, W4) must stay out.
        $this->assertPayload(ReportType::Business, $this->businessFiguresOfA([
            'new_subscriptions' => 1,       // S1 at 00:00:00
            'churn' => 1,                   // S6 terminated at 00:00:00
            'revenue_paid' => 300000,       // I1 issued on the first day
            'recurring_revenue' => 300000,
            'one_time_revenue' => 0,
            'installation_count' => 1,      // W1 at 00:00:00
        ]), BusinessMetricsQuery::execute('2026-01-01', '2026-01-01'), 'first day');

        $this->assertPayload(ReportType::Business, $this->businessFiguresOfA([
            'new_subscriptions' => 1,       // S2 at 23:59:59
            'churn' => 1,                   // S5 terminated at 23:59:59
            'revenue_paid' => 150000,       // I2 issued on the last day
            'recurring_revenue' => 150000,
            'one_time_revenue' => 0,
            'installation_count' => 1,      // W2 at 23:59:59
        ]), BusinessMetricsQuery::execute('2026-01-31', '2026-01-31'), 'last day');
    }

    public function test_business_snapshot_figures_ignore_the_range(): void
    {
        $this->seedBusinessWorld();

        // A range that matches nothing zeroes the period figures only: MRR, subscription statuses,
        // outstanding, asset distribution and SLA compliance keep their snapshot values.
        $this->assertPayload(ReportType::Business, $this->businessFiguresOfA([
            'new_subscriptions' => 0,
            'churn' => 0,
            'revenue_paid' => 0,
            'recurring_revenue' => 0,
            'one_time_revenue' => 0,
            'installation_count' => 0,
        ]), BusinessMetricsQuery::execute('2030-01-01', '2030-01-31'));
    }

    public function test_business_sla_compliance_is_all_time_and_can_disagree_with_the_sla_report(): void
    {
        $this->seedBusinessWorld();

        $business = BusinessMetricsQuery::execute('2026-01-01', '2026-01-31');
        $sla = SlaComplianceQuery::execute('2026-01-01', '2026-01-31');

        // Same tickets, same month: Business counts all 9 resolved tickets (5 compliant, the one without
        // a deadline stays in the denominator), the SLA report only the 7 resolved in January (4 compliant).
        $this->assertMetrics(ReportType::Business, ['sla_compliance_pct' => 55.6], $business);
        $this->assertMetrics(ReportType::Sla, ['sla_rate' => 57.1], $sla);
    }

    public function test_business_revenue_is_dated_by_issue_date_not_by_payment_date(): void
    {
        $a = $this->companyA;

        $issuedInJanuaryPaidInFebruary = $this->invoice($a, 'recurring', 'paid', 100000, 100000, '2026-01-10');
        $this->payment($issuedInJanuaryPaidInFebruary, '2026-02-05 09:00:00');
        $issuedInDecemberPaidInJanuary = $this->invoice($a, 'one_time', 'paid', 200000, 200000, '2025-12-20');
        $this->payment($issuedInDecemberPaidInJanuary, '2026-01-05 09:00:00');

        $this->assertMetrics(ReportType::Business, [
            'revenue_paid' => 100000,       // issued in January, although the money arrived in February
            'recurring_revenue' => 100000,
            'one_time_revenue' => 0,        // paid in January, but issued in December
        ], BusinessMetricsQuery::execute('2026-01-01', '2026-01-31'), 'January');

        $this->assertMetrics(ReportType::Business, [
            'revenue_paid' => 0,            // February holds the payment, not the invoice
            'recurring_revenue' => 0,
            'one_time_revenue' => 0,
        ], BusinessMetricsQuery::execute('2026-02-01', '2026-02-28'), 'February');

        $this->assertMetrics(ReportType::Business, [
            'revenue_paid' => 200000,
            'recurring_revenue' => 0,
            'one_time_revenue' => 200000,
        ], BusinessMetricsQuery::execute('2025-12-01', '2025-12-31'), 'December');
    }

    public function test_business_and_asset_installation_counts_measure_different_records(): void
    {
        $this->seedBusinessWorld();

        // Same company, same month: Business counts completed installation work orders (W1, W2, W7), Asset counts
        // installation records of network assets (N1, N2).
        $this->assertMetrics(ReportType::Business, ['installation_count' => 3], BusinessMetricsQuery::execute('2026-01-01', '2026-01-31'));
        $this->assertMetrics(ReportType::Asset, ['installation_count' => 2], AssetUtilizationQuery::execute(dateFrom: '2026-01-01', dateTo: '2026-01-31'));
    }

    public function test_business_figures_are_scoped_to_the_company(): void
    {
        $this->seedBusinessWorld();
        $this->actingAsCompany($this->companyB);

        // Company A's side of the same world is pinned by test_business_figures_for_a_closed_period.
        $this->assertPayload(ReportType::Business, [
            'mrr' => 5000000,
            'active_subscriptions' => 1,
            'suspended_subscriptions' => 1,
            'terminated_subscriptions' => 1,
            'new_subscriptions' => 2,       // SB1 and SB3; SB2 was created in 2025
            'churn' => 1,                   // SB2
            'revenue_paid' => 900000,
            'recurring_revenue' => 900000,
            'one_time_revenue' => 0,
            'outstanding' => 300000,        // IB2: 400000 - 100000
            'asset_distribution' => ['available' => 1, 'installed' => 1, 'retired' => 1],
            'sla_compliance_pct' => 50.0,   // TB1 on time, TB2 late
            'installation_count' => 1,
        ], BusinessMetricsQuery::execute('2026-01-01', '2026-01-31'));
    }

    public function test_business_figures_of_a_company_without_records_are_zero_or_empty(): void
    {
        $this->assertPayload(ReportType::Business, [
            'mrr' => 0,
            'active_subscriptions' => 0,
            'suspended_subscriptions' => 0,
            'terminated_subscriptions' => 0,
            'new_subscriptions' => 0,
            'churn' => 0,
            'revenue_paid' => 0,
            'recurring_revenue' => 0,
            'one_time_revenue' => 0,
            'outstanding' => 0,
            'asset_distribution' => [],
            'sla_compliance_pct' => 0,
            'installation_count' => 0,
        ], BusinessMetricsQuery::execute('2026-01-01', '2026-01-31'));
    }

    // ---------------------------------------------------------------------------------------------
    // SLA
    // ---------------------------------------------------------------------------------------------

    public function test_sla_figures_for_a_closed_period(): void
    {
        $this->seedTickets();

        // Resolved inside January: T1 T2 T3 T4 T5 T8 T9. T7 and T10 are resolved one second outside, T6 is open.
        // Status is never read: T2 is closed and T5 is back on_progress, yet both count as resolved because resolved_at is set.
        $this->assertPayload(ReportType::Sla, [
            'total_resolved' => 7,
            'sla_compliant' => 4,           // T1 T2 T5 T8; T2 resolved exactly at its deadline
            'sla_rate' => 57.1,             // 4 / 7
            'breach_count' => 2,            // T3 (one second late) and T9; T4 has no deadline: neither
            'avg_resolution_minutes' => 103, // (60 + 120 + 240 + 30 + 90 + 60 + 119) / 7 = 102.7
            'avg_frt_minutes' => 16,        // (10 + 30 + 20 + 5) / 4 = 16.25 over the tickets with a first response
            'by_category' => $this->slaByCategoryOfA(),
            ...$this->withoutResponseTarget(7),
        ], SlaComplianceQuery::execute('2026-01-01', '2026-01-31'));
    }

    public function test_sla_figures_cover_every_resolved_ticket_when_no_range_is_given(): void
    {
        $this->seedTickets();

        $this->assertPayload(ReportType::Sla, $this->slaFiguresOfAWithoutRange(), SlaComplianceQuery::execute());
    }

    public function test_sla_applies_a_range_with_only_one_end(): void
    {
        $this->seedTickets();

        // From alone: T7 (resolved one second before January) drops out; by_category stays all-time.
        $this->assertPayload(
            ReportType::Sla,
            [
                'total_resolved' => 8,              // T1 T2 T3 T4 T5 T8 T9 T10
                'sla_compliant' => 4,               // T1 T2 T5 T8
                'sla_rate' => 50.0,                 // 4 / 8
                'breach_count' => 3,                // T3 T9 T10
                'avg_resolution_minutes' => 97,     // (60 + 120 + 240 + 30 + 90 + 60 + 119 + 60) / 8 = 97.4
                'avg_frt_minutes' => 16,            // T1 T3 T4 T5: 16.25
                'by_category' => $this->slaByCategoryOfA(),
                ...$this->withoutResponseTarget(8),
            ],
            SlaComplianceQuery::execute(dateFrom: '2026-01-01'),
            'only From',
        );
        // To alone: T10 (resolved one second after January) drops out.
        $this->assertPayload(
            ReportType::Sla,
            [
                'total_resolved' => 8,              // T1 T2 T3 T4 T5 T7 T8 T9
                'sla_compliant' => 5,               // T1 T2 T5 T7 T8
                'sla_rate' => 62.5,                 // 5 / 8
                'breach_count' => 2,                // T3 T9
                'avg_resolution_minutes' => 120,    // (60 + 120 + 240 + 30 + 90 + 239 + 60 + 119) / 8 = 119.75
                'avg_frt_minutes' => 16,            // T1 T3 T4 T5: 16.25
                'by_category' => $this->slaByCategoryOfA(),
                ...$this->withoutResponseTarget(8),
            ],
            SlaComplianceQuery::execute(dateTo: '2026-01-31'),
            'only To',
        );
    }

    public function test_sla_range_includes_the_first_and_last_day_up_to_23_59_59(): void
    {
        $this->seedTickets();

        // The range is on resolved_at. T8 was created in December but resolved on the first second of
        // January, T9 on the last second; T7 and T10 (one second outside) must stay out.
        $this->assertPayload(ReportType::Sla, [
            'total_resolved' => 1,
            'sla_compliant' => 1,
            'sla_rate' => 100.0,
            'breach_count' => 0,
            'avg_resolution_minutes' => 60,
            'avg_frt_minutes' => null,      // T8 has no first response
            'by_category' => $this->slaByCategoryOfA(),
            ...$this->withoutResponseTarget(1),
        ], SlaComplianceQuery::execute('2026-01-01', '2026-01-01'), 'first day');

        $this->assertPayload(ReportType::Sla, [
            'total_resolved' => 1,
            'sla_compliant' => 0,
            'sla_rate' => 0.0,
            'breach_count' => 1,            // T9: 23:59:59 against a 23:00:00 deadline
            'avg_resolution_minutes' => 119, // 1h59m59s counts as 119 whole minutes
            'avg_frt_minutes' => null,
            'by_category' => $this->slaByCategoryOfA(),
            ...$this->withoutResponseTarget(1),
        ], SlaComplianceQuery::execute('2026-01-31', '2026-01-31'), 'last day');
    }

    public function test_sla_category_filter_narrows_every_figure_except_by_category(): void
    {
        $categories = $this->seedTickets();

        // Outage in January: T3 T4 T8.
        $this->assertPayload(ReportType::Sla, [
            'total_resolved' => 3,
            'sla_compliant' => 1,           // T8
            'sla_rate' => 33.3,
            'breach_count' => 1,            // T3
            'avg_resolution_minutes' => 110, // (240 + 30 + 60) / 3
            'avg_frt_minutes' => 25,        // (30 + 20) / 2
            'by_category' => $this->slaByCategoryOfA(),
            ...$this->withoutResponseTarget(3),
        ], SlaComplianceQuery::execute('2026-01-01', '2026-01-31', $categories['outage']->id), 'Outage in January');

        // Support (SUP-1 only) in January: T1 T2 T9. The by_category row "Support" still holds 5 tickets:
        // it also merges the same-named SUP-2 category and ignores the January range.
        $this->assertPayload(ReportType::Sla, [
            'total_resolved' => 3,
            'sla_compliant' => 2,           // T1 T2
            'sla_rate' => 66.7,
            'breach_count' => 1,            // T9
            'avg_resolution_minutes' => 100, // (60 + 120 + 119) / 3 = 99.67
            'avg_frt_minutes' => 10,        // only T1 has a first response
            'by_category' => $this->slaByCategoryOfA(),
            ...$this->withoutResponseTarget(3),
        ], SlaComplianceQuery::execute('2026-01-01', '2026-01-31', $categories['support']->id), 'Support in January');

        // Outage without a range: T3 T4 T8 T10.
        $this->assertPayload(ReportType::Sla, [
            'total_resolved' => 4,
            'sla_compliant' => 1,           // T8
            'sla_rate' => 25.0,
            'breach_count' => 2,            // T3 and T10; T4 has no deadline
            'avg_resolution_minutes' => 98, // (240 + 30 + 60 + 60) / 4 = 97.5 rounds up
            'avg_frt_minutes' => 25,
            'by_category' => $this->slaByCategoryOfA(),
            ...$this->withoutResponseTarget(4),
        ], SlaComplianceQuery::execute(categoryId: $categories['outage']->id), 'Outage all time');
    }

    public function test_sla_by_category_ignores_the_range_and_the_category_filter_and_merges_equal_names(): void
    {
        $categories = $this->seedTickets();

        // "Support" = SUP-1 (T1 T2 T7 T9) + SUP-2 (T5): 5 resolved, 4 on time. "Outage" = T3 T4 T8 T10: 4 resolved,
        // 1 on time (T8; T3 and T10 are late, T4 has no deadline). "Idle" only has the open T6, so it is not listed.
        $expected = [
            ['name' => 'Outage', 'total' => 4, 'compliant' => 1, 'rate' => 25.0],
            ['name' => 'Support', 'total' => 5, 'compliant' => 4, 'rate' => 80.0],
        ];

        $this->assertMetrics(ReportType::Sla, ['by_category' => $expected], SlaComplianceQuery::execute());
        $this->assertMetrics(ReportType::Sla, ['by_category' => $expected], SlaComplianceQuery::execute('2026-01-14', '2026-01-14'), 'a one-day range');
        $this->assertMetrics(ReportType::Sla, ['by_category' => $expected], SlaComplianceQuery::execute('2030-01-01', '2030-01-31'), 'a range without tickets');
        $this->assertMetrics(ReportType::Sla, ['by_category' => $expected], SlaComplianceQuery::execute(categoryId: $categories['idle']->id), 'the category of an open ticket');
    }

    public function test_sla_without_resolved_tickets_in_the_period_has_a_zero_rate_and_empty_averages(): void
    {
        $this->seedTickets();

        $this->assertPayload(ReportType::Sla, [
            'total_resolved' => 0,
            'sla_compliant' => 0,
            'sla_rate' => 0,
            'breach_count' => 0,
            'avg_resolution_minutes' => null,
            'avg_frt_minutes' => null,
            'by_category' => $this->slaByCategoryOfA(),
            ...$this->withoutResponseTarget(0),
        ], SlaComplianceQuery::execute('2030-01-01', '2030-01-31'));
    }

    public function test_sla_deadline_comparison_is_inclusive_and_a_missing_deadline_is_neither_compliant_nor_breached(): void
    {
        $category = $this->category($this->companyA, 'Support', 'SUP');
        $a = $this->companyA;

        $this->ticket($a, $category, '2026-01-10 08:00:00', null, '2026-01-10 10:00:00', '2026-01-10 10:00:00'); // resolved at the deadline: compliant
        $this->ticket($a, $category, '2026-01-11 08:00:00', null, '2026-01-11 10:00:01', '2026-01-11 10:00:00'); // one second late: breach
        $this->ticket($a, $category, '2026-01-12 08:00:00', null, '2026-01-12 09:59:59', '2026-01-12 10:00:00'); // one second early: compliant
        $this->ticket($a, $category, '2026-01-13 08:00:00', null, '2026-01-13 09:00:00', null);                  // no deadline: neither
        $this->ticket($a, $category, '2026-01-14 08:00:00', null, null, '2026-01-14 09:00:00');                  // unresolved with a passed deadline: not counted

        $figures = SlaComplianceQuery::execute();

        $this->assertPayload(ReportType::Sla, [
            'total_resolved' => 4,
            'sla_compliant' => 2,
            'sla_rate' => 50.0,             // the ticket without a deadline stays in the denominator
            'breach_count' => 1,
            'avg_resolution_minutes' => 105, // (120 + 120 + 119 + 60) / 4 = 104.75
            'avg_frt_minutes' => null,      // no ticket has a first response
            'by_category' => [['name' => 'Support', 'total' => 4, 'compliant' => 2, 'rate' => 50.0]],
            ...$this->withoutResponseTarget(4),
        ], $figures);
        $this->assertLessThan(
            $figures['total_resolved'],
            $figures['sla_compliant'] + $figures['breach_count'],
            'compliant + breach is lower than total when a resolved ticket has no deadline',
        );
    }

    public function test_sla_response_figures_judge_the_first_response_of_resolved_tickets_against_their_response_target(): void
    {
        $a = $this->companyA;
        $support = $this->category($a, 'Support', 'SUP');
        $outage = $this->category($a, 'Outage', 'OUT');

        // Company A. Columns: category, created_at, first_response_at, resolved_at, sla_deadline, status, response_deadline.
        $this->ticket($a, $support, '2026-01-10 08:00:00', '2026-01-10 08:30:00', '2026-01-10 12:00:00', '2026-01-10 16:00:00', null, '2026-01-10 09:00:00'); // R1 answered 30 minutes before its target: on time
        $this->ticket($a, $support, '2026-01-11 08:00:00', '2026-01-11 09:00:00', '2026-01-11 12:00:00', '2026-01-11 16:00:00', null, '2026-01-11 09:00:00'); // R2 answered exactly at its target: on time
        $this->ticket($a, $outage, '2026-01-12 08:00:00', '2026-01-12 09:00:01', '2026-01-12 12:00:00', '2026-01-12 16:00:00', null, '2026-01-12 09:00:00');  // R3 one second late: breached
        $this->ticket($a, $outage, '2026-01-13 08:00:00', '2026-01-13 08:10:00', '2026-01-13 12:00:00', '2026-01-13 16:00:00');                              // R4 predates response targets: in neither figure, counted as without target
        $this->ticket($a, $support, '2026-01-14 08:00:00', null, '2026-01-14 12:00:00', '2026-01-14 16:00:00', null, '2026-01-14 09:00:00');                 // R5 has a target but no recorded response: in neither figure
        $this->ticket($a, $support, '2026-01-15 08:00:00', '2026-01-15 11:00:00', null, '2026-01-15 16:00:00', null, '2026-01-15 09:00:00');                 // R6 answered late but still open: in no figure
        $this->ticket($a, $support, '2025-12-31 08:00:00', '2025-12-31 12:00:00', '2025-12-31 23:00:00', '2026-01-01 08:00:00', null, '2025-12-31 09:00:00'); // R7 answered late, resolved in December: outside January

        // Company B: its own on-time ticket never reaches A, and A's never reach B.
        $b = $this->companyB;
        $supportB = $this->category($b, 'Support', 'SUP-B');
        $this->ticket($b, $supportB, '2026-01-10 08:00:00', '2026-01-10 08:10:00', '2026-01-10 12:00:00', '2026-01-10 16:00:00', null, '2026-01-10 09:00:00'); // RB1 on time

        $this->assertMetrics(ReportType::Sla, [
            'total_resolved' => 5,              // R1 R2 R3 R4 R5
            'response_on_time' => 2,            // R1 R2: the target is inclusive
            'response_breached' => 1,           // R3
            'response_without_target' => 1,     // R4
            'response_rate' => 66.7,            // 2 / (2 + 1): R4 and R5 are not judged
        ], SlaComplianceQuery::execute('2026-01-01', '2026-01-31'), 'January');

        $this->assertMetrics(ReportType::Sla, [
            'total_resolved' => 6,              // R7 joins when no range is given
            'response_on_time' => 2,
            'response_breached' => 2,           // R3 R7
            'response_without_target' => 1,
            'response_rate' => 50.0,
        ], SlaComplianceQuery::execute(), 'all time');

        $this->assertMetrics(ReportType::Sla, [
            'total_resolved' => 2,              // Outage in January: R3 R4
            'response_on_time' => 0,
            'response_breached' => 1,
            'response_without_target' => 1,
            'response_rate' => 0.0,
        ], SlaComplianceQuery::execute('2026-01-01', '2026-01-31', $outage->id), 'Outage in January');

        $this->actingAsCompany($b);
        $this->assertMetrics(ReportType::Sla, [
            'total_resolved' => 1,
            'response_on_time' => 1,
            'response_breached' => 0,
            'response_without_target' => 0,
            'response_rate' => 100.0,
        ], SlaComplianceQuery::execute('2026-01-01', '2026-01-31'), 'company B');
    }

    public function test_sla_average_of_zero_minutes_is_zero_and_only_missing_data_is_empty(): void
    {
        $category = $this->category($this->companyA, 'Support', 'SUP');

        // Created 08:00:00, first response 08:00:10, resolved 08:00:30: both durations are under a minute.
        $this->ticket($this->companyA, $category, '2026-01-10 08:00:00', '2026-01-10 08:00:10', '2026-01-10 08:00:30', null);

        $this->assertPayload(ReportType::Sla, [
            'total_resolved' => 1,
            'sla_compliant' => 0,
            'sla_rate' => 0.0,
            'breach_count' => 0,
            'avg_resolution_minutes' => 0,  // 0 minutes is a figure, not "empty"
            'avg_frt_minutes' => 0,
            'by_category' => [['name' => 'Support', 'total' => 1, 'compliant' => 0, 'rate' => 0.0]],
            ...$this->withoutResponseTarget(1),
        ], SlaComplianceQuery::execute());
    }

    public function test_sla_figures_are_scoped_to_the_company(): void
    {
        $this->seedTickets();
        $this->actingAsCompany($this->companyB);

        // Company B also has a category named "Support": its row holds only B's two tickets.
        $this->assertPayload(ReportType::Sla, [
            'total_resolved' => 2,
            'sla_compliant' => 1,           // TB1
            'sla_rate' => 50.0,
            'breach_count' => 1,            // TB2
            'avg_resolution_minutes' => 90, // (120 + 60) / 2
            'avg_frt_minutes' => 40,        // TB1 only
            'by_category' => [['name' => 'Support', 'total' => 2, 'compliant' => 1, 'rate' => 50.0]],
            ...$this->withoutResponseTarget(2),
        ], SlaComplianceQuery::execute('2026-01-01', '2026-01-31'));
    }

    public function test_sla_figures_of_a_company_without_records_are_zero_or_empty(): void
    {
        $this->assertPayload(ReportType::Sla, [
            'total_resolved' => 0,
            'sla_compliant' => 0,
            'sla_rate' => 0,
            'breach_count' => 0,
            'avg_resolution_minutes' => null,
            'avg_frt_minutes' => null,
            'by_category' => [],
            ...$this->withoutResponseTarget(0),
        ], SlaComplianceQuery::execute('2026-01-01', '2026-01-31'));
    }

    // ---------------------------------------------------------------------------------------------
    // Asset
    // ---------------------------------------------------------------------------------------------

    public function test_asset_figures_without_filters_count_every_installation_record(): void
    {
        $this->seedAssets();

        $this->assertPayload(ReportType::Asset, $this->assetFiguresOfA(), AssetUtilizationQuery::execute());
    }

    public function test_asset_installation_count_follows_the_range_when_both_dates_are_given(): void
    {
        $this->seedAssets();

        // N1 and N2 are installed in January (N2 was removed in February but is still a record of that
        // January installation); N3 and N4 are one second outside.
        $this->assertPayload(
            ReportType::Asset,
            $this->assetFiguresOfA(['installation_count' => 2]),
            AssetUtilizationQuery::execute(dateFrom: '2026-01-01', dateTo: '2026-01-31'),
        );
    }

    public function test_asset_applies_a_range_with_only_one_end(): void
    {
        $this->seedAssets();

        $this->assertPayload(
            ReportType::Asset,
            $this->assetFiguresOfA(['installation_count' => 3]),      // N1 N2 N4; N3 is one second before From
            AssetUtilizationQuery::execute(dateFrom: '2026-01-01'),
            'only From',
        );
        $this->assertPayload(
            ReportType::Asset,
            $this->assetFiguresOfA(['installation_count' => 3]),      // N1 N2 N3; N4 is one second after To
            AssetUtilizationQuery::execute(dateTo: '2026-01-31'),
            'only To',
        );
    }

    public function test_asset_range_includes_the_first_and_last_day_up_to_23_59_59(): void
    {
        $this->seedAssets();

        $this->assertPayload(
            ReportType::Asset,
            $this->assetFiguresOfA(['installation_count' => 1]),      // N1 at 00:00:00; N3 one second earlier stays out
            AssetUtilizationQuery::execute(dateFrom: '2026-01-01', dateTo: '2026-01-01'),
            'first day',
        );
        $this->assertPayload(
            ReportType::Asset,
            $this->assetFiguresOfA(['installation_count' => 1]),      // N2 at 23:59:59; N4 one second later stays out
            AssetUtilizationQuery::execute(dateFrom: '2026-01-31', dateTo: '2026-01-31'),
            'last day',
        );
    }

    public function test_asset_type_filter_narrows_the_status_distribution_only(): void
    {
        $this->seedAssets();

        // Routers: A1 available, A2 installed, A3 and A9 retired. Everything else keeps its company-wide value;
        // the January installation count stays 2 although only N2 belongs to a router.
        $this->assertPayload(ReportType::Asset, $this->assetFiguresOfA([
            'status_distribution' => ['available' => 1, 'installed' => 1, 'retired' => 2],
            'installation_count' => 2,
        ]), AssetUtilizationQuery::execute(assetType: 'router', dateFrom: '2026-01-01', dateTo: '2026-01-31'));
    }

    public function test_asset_location_filter_narrows_the_status_distribution_only(): void
    {
        $locations = $this->seedAssets();

        // POP East (POP-E1 only, not its same-named annex): A4 installed, A6 installed, A9 retired. The January
        // installation count stays 2 although only N1 belongs to that location.
        $this->assertPayload(ReportType::Asset, $this->assetFiguresOfA([
            'status_distribution' => ['installed' => 2, 'retired' => 1],
            'installation_count' => 2,
        ]), AssetUtilizationQuery::execute(locationId: $locations['east']->id, dateFrom: '2026-01-01', dateTo: '2026-01-31'));
    }

    public function test_asset_filters_combine_and_may_match_nothing(): void
    {
        $locations = $this->seedAssets();

        // Routers at POP East: A9 only.
        $this->assertPayload(ReportType::Asset, $this->assetFiguresOfA([
            'status_distribution' => ['retired' => 1],
        ]), AssetUtilizationQuery::execute(locationId: $locations['east']->id, assetType: 'router'), 'router at POP East');

        // No antenna exists: the distribution is empty, every other figure is untouched.
        $this->assertPayload(ReportType::Asset, $this->assetFiguresOfA([
            'status_distribution' => [],
        ]), AssetUtilizationQuery::execute(assetType: 'antenna'), 'no match');
    }

    public function test_asset_figures_are_scoped_to_the_company(): void
    {
        $this->seedAssets();
        $this->actingAsCompany($this->companyB);

        // Company B also has a location named "POP Central": it holds only B's two assets.
        $this->assertPayload(ReportType::Asset, [
            'status_distribution' => ['available' => 1, 'installed' => 1, 'retired' => 1],
            'by_location' => ['POP Central' => 2],
            'installation_count' => 2,      // NB1 and NB2
            'customer_linked_count' => 1,
            'retired_count' => 1,
        ], AssetUtilizationQuery::execute(dateFrom: '2026-01-01', dateTo: '2026-01-31'));
    }

    public function test_asset_figures_of_a_company_without_records_are_zero_or_empty(): void
    {
        $this->assertPayload(ReportType::Asset, [
            'status_distribution' => [],
            'by_location' => [],
            'installation_count' => 0,
            'customer_linked_count' => 0,
            'retired_count' => 0,
        ], AssetUtilizationQuery::execute(dateFrom: '2026-01-01', dateTo: '2026-01-31'));
    }

    // ---------------------------------------------------------------------------------------------
    // Expected figures shared by several scenarios
    // ---------------------------------------------------------------------------------------------

    /**
     * Company A's Business figures that no date range can change, plus the six period figures of the scenario.
     *
     * @param  array<string, mixed>  $period  new_subscriptions, churn, revenue_paid, recurring_revenue, one_time_revenue, installation_count
     * @return array<string, mixed>
     */
    private function businessFiguresOfA(array $period): array
    {
        return [
            'mrr' => 1500000,                   // active S1 100000 + S2 200000 + S3 400000 + S10 800000; suspended, pending and terminated excluded
            'active_subscriptions' => 4,        // S1 S2 S3 S10
            'suspended_subscriptions' => 1,     // S4
            'terminated_subscriptions' => 6,    // S5 S6 S7 S8 S11 S12, all time
            'outstanding' => 495000,            // I6 500000-200000 + I7 120000-0 + I8 80000-5000; draft, cancelled and paid excluded
            'asset_distribution' => ['available' => 2, 'damaged' => 1, 'installed' => 3, 'retired' => 3],
            'sla_compliance_pct' => 55.6,       // 5 of the 9 resolved tickets (T1 T2 T5 T7 T8), all time
            ...$period,
        ];
    }

    /** @return array<string, mixed> */
    private function businessFiguresOfAWithoutRange(): array
    {
        return $this->businessFiguresOfA([
            'new_subscriptions' => 0,           // no range: 0
            'churn' => 0,                       // no range: 0
            'revenue_paid' => 8096000,          // I1 300000 + I2 150000 + I3 46000 + I4 7000000 + I5 600000
            'recurring_revenue' => 7450000,     // I1 + I2 + I4
            'one_time_revenue' => 646000,       // I3 + I5
            'installation_count' => 5,          // W1 W2 W3 W4 W7; W5 is not completed, W6 is maintenance
        ]);
    }

    /**
     * The response figures of a world whose tickets all predate response targets: none is on time or late, every
     * resolved ticket is "without target", and the rate has nothing to judge.
     *
     * @return array<string, int|null>
     */
    private function withoutResponseTarget(int $resolved): array
    {
        return [
            'response_on_time' => 0,
            'response_breached' => 0,
            'response_without_target' => $resolved,
            'response_rate' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function slaFiguresOfAWithoutRange(): array
    {
        return [
            'total_resolved' => 9,              // T1 T2 T3 T4 T5 T7 T8 T9 T10
            'sla_compliant' => 5,               // T1 T2 T5 T7 T8
            'sla_rate' => 55.6,                 // 5 / 9
            'breach_count' => 3,                // T3 T9 T10; T4 has no deadline
            'avg_resolution_minutes' => 113,    // (60 + 120 + 240 + 30 + 90 + 239 + 60 + 119 + 60) / 9 = 113.1
            'avg_frt_minutes' => 16,            // (10 + 30 + 20 + 5) / 4 = 16.25
            'by_category' => $this->slaByCategoryOfA(),
            ...$this->withoutResponseTarget(9),
        ];
    }

    /**
     * by_category of company A: all resolved tickets, whatever the range or category filter.
     * Support = SUP-1 (T1 T2 T7 T9) + SUP-2 (T5), Outage = T3 T4 T8 T10.
     *
     * @return list<array{name: string, total: int, compliant: int, rate: float}>
     */
    private function slaByCategoryOfA(): array
    {
        return [
            ['name' => 'Outage', 'total' => 4, 'compliant' => 1, 'rate' => 25.0],
            ['name' => 'Support', 'total' => 5, 'compliant' => 4, 'rate' => 80.0],
        ];
    }

    /**
     * Company A's Asset figures without filters or range, with the given figures replaced.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function assetFiguresOfA(array $overrides = []): array
    {
        return [
            'status_distribution' => ['available' => 2, 'damaged' => 1, 'installed' => 3, 'retired' => 3], // A1 A8 / A5 / A2 A4 A6 / A3 A7 A9
            'by_location' => ['POP Central' => 3, 'POP East' => 4], // A1 A2 A3 / A4 A6 A9 + A5 in the same-named annex; A7 and A8 have no location
            'installation_count' => 4,          // N1 N2 N3 N4, removed or not
            'customer_linked_count' => 4,       // A2 A4 A6 A7, whatever their status
            'retired_count' => 3,               // A3 A7 A9
            ...$overrides,
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------------------------------

    private function seedBusinessWorld(): void
    {
        $this->seedSubscriptions();
        $this->seedInvoices();
        $this->seedWorkOrders();
        $this->seedTickets();
        $this->seedAssets();
    }

    private function seedSubscriptions(): void
    {
        $a = $this->companyA;

        // Company A. Columns: status, MRC, created_at [, terminated_at].
        $this->subscription($a, 'active', 100000, '2026-01-01 00:00:00');                                  // S1 first second of January: new
        $this->subscription($a, 'active', 200000, '2026-01-31 23:59:59');                                  // S2 last second of January: new
        $this->subscription($a, 'active', 400000, '2025-12-31 23:59:59');                                  // S3 one second early: not new
        $this->subscription($a, 'suspended', 50000, '2026-01-15 10:00:00');                                // S4 new although not active; not in MRR
        $this->subscription($a, 'terminated', 70000, '2026-02-01 00:00:00', '2026-01-31 23:59:59');        // S5 not new (one second late); churn on the last second
        $this->subscription($a, 'terminated', 80000, '2025-06-01 00:00:00', '2026-01-01 00:00:00');        // S6 churn on the first second
        $this->subscription($a, 'terminated', 90000, '2025-06-01 00:00:00', '2025-12-31 23:59:59');        // S7 terminated one second before January
        $this->subscription($a, 'terminated', 95000, '2025-06-01 00:00:00', '2026-02-01 00:00:00');        // S8 terminated one second after January
        $this->subscription($a, 'pending', 30000, '2026-01-20 12:00:00');                                  // S9 new, yet in no status figure
        $this->subscription($a, 'active', 800000, '2025-01-01 00:00:00', '2026-01-10 00:00:00');           // S10 stale terminated_at on an active row: not churn
        $this->subscription($a, 'terminated', 60000, '2025-06-01 00:00:00');                               // S11 terminated without a date: never churn
        $this->subscription($a, 'terminated', 55000, '2026-01-05 10:00:00', '2026-01-20 10:00:00');        // S12 created and terminated inside January: counts as new and as churn

        // Company B: none of this may reach A, and A's rows may not reach B.
        $b = $this->companyB;
        $this->subscription($b, 'active', 5000000, '2026-01-10 08:00:00');                                 // SB1
        $this->subscription($b, 'terminated', 7000000, '2025-06-01 00:00:00', '2026-01-20 08:00:00');      // SB2
        $this->subscription($b, 'suspended', 3000000, '2026-01-12 08:00:00');                              // SB3
    }

    private function seedInvoices(): void
    {
        $a = $this->companyA;

        // Company A. Columns: type, status, total, paid_amount, issue_date.
        $this->invoice($a, 'recurring', 'paid', 300000, 300000, '2026-01-01');                             // I1 first day
        $this->invoice($a, 'recurring', 'paid', 150000, 150000, '2026-01-31');                             // I2 last day: To is inclusive
        $this->invoice($a, 'one_time', 'paid', 40000, 46000, '2026-01-15');                                // I3 overpaid: revenue is paid_amount, not total
        $this->invoice($a, 'recurring', 'paid', 7000000, 7000000, '2025-12-31');                           // I4 issued the day before January
        $this->invoice($a, 'one_time', 'paid', 600000, 600000, '2026-02-01');                              // I5 issued the day after January
        $this->invoice($a, 'recurring', 'partial', 500000, 200000, '2026-01-10');                          // I6 not paid: no revenue; 300000 outstanding
        $this->invoice($a, 'recurring', 'sent', 120000, 0, '2026-01-12');                                  // I7 120000 outstanding
        $this->invoice($a, 'one_time', 'overdue', 80000, 5000, '2025-11-05');                              // I8 75000 outstanding although issued before the range
        $this->invoice($a, 'one_time', 'draft', 999000, 0, '2026-01-20');                                  // I9 draft: never counted
        $this->invoice($a, 'recurring', 'cancelled', 888000, 0, '2026-01-21');                             // I10 cancelled: never counted

        $b = $this->companyB;
        $this->invoice($b, 'recurring', 'paid', 900000, 900000, '2026-01-10');                             // IB1
        $this->invoice($b, 'one_time', 'sent', 400000, 100000, '2026-01-11');                              // IB2
    }

    private function seedWorkOrders(): void
    {
        $a = $this->companyA;

        // Company A. Columns: type, status, completed_at.
        $this->workOrder($a, 'installation', 'completed', '2026-01-01 00:00:00');                          // W1 first second of January
        $this->workOrder($a, 'installation', 'completed', '2026-01-31 23:59:59');                          // W2 last second of January
        $this->workOrder($a, 'installation', 'completed', '2025-12-31 23:59:59');                          // W3 one second early
        $this->workOrder($a, 'installation', 'completed', '2026-02-01 00:00:00');                          // W4 one second late
        $this->workOrder($a, 'installation', 'in_progress', null);                                         // W5 not completed
        $this->workOrder($a, 'maintenance', 'completed', '2026-01-15 09:00:00');                           // W6 other type
        $this->workOrder($a, 'installation', 'completed', '2026-01-15 12:00:00');                          // W7 mid-month: a work order without an asset installation record

        $this->workOrder($this->companyB, 'installation', 'completed', '2026-01-15 10:00:00');             // WB1
    }

    /**
     * Resolved and open tickets of both companies. Minutes in the comments run from created_at to resolved_at.
     *
     * @return array{support: TicketCategory, support_twin: TicketCategory, outage: TicketCategory, idle: TicketCategory}
     */
    private function seedTickets(): array
    {
        $a = $this->companyA;
        $support = $this->category($a, 'Support', 'SUP-1');
        $supportTwin = $this->category($a, 'Support', 'SUP-2');     // same name as SUP-1: by_category merges them
        $outage = $this->category($a, 'Outage', 'OUT');
        $idle = $this->category($a, 'Idle', 'IDLE');                // only an open ticket: never listed

        // Company A. Columns: category, created_at, first_response_at, resolved_at, sla_deadline [, status].
        $this->ticket($a, $support, '2026-01-10 08:00:00', '2026-01-10 08:10:00', '2026-01-10 09:00:00', '2026-01-10 10:00:00');           // T1 60 min, first response after 10: on time
        $this->ticket($a, $support, '2026-01-11 08:00:00', null, '2026-01-11 10:00:00', '2026-01-11 10:00:00', 'closed');                  // T2 120 min, resolved exactly at the deadline: compliant; status is irrelevant
        $this->ticket($a, $outage, '2026-01-12 08:00:00', '2026-01-12 08:30:00', '2026-01-12 12:00:00', '2026-01-12 11:59:59');            // T3 240 min, one second after the deadline: breach
        $this->ticket($a, $outage, '2026-01-13 08:00:00', '2026-01-13 08:20:00', '2026-01-13 08:30:00', null);                             // T4 30 min, no deadline: neither compliant nor breach
        $this->ticket($a, $supportTwin, '2026-01-14 08:00:00', '2026-01-14 08:05:00', '2026-01-14 09:30:00', '2026-01-14 12:00:00', 'on_progress'); // T5 90 min: on time; reopened since, yet resolved_at keeps it resolved
        $this->ticket($a, $idle, '2026-01-15 08:00:00', null, null, '2026-01-15 12:00:00');                                                // T6 open: in no figure
        $this->ticket($a, $support, '2025-12-31 20:00:00', null, '2025-12-31 23:59:59', '2026-01-01 00:00:00');                            // T7 239 min, resolved one second before January: on time
        $this->ticket($a, $outage, '2025-12-31 23:00:00', null, '2026-01-01 00:00:00', '2026-01-01 00:30:00');                             // T8 60 min, created in December, resolved on the first second of January: on time
        $this->ticket($a, $support, '2026-01-31 22:00:00', null, '2026-01-31 23:59:59', '2026-01-31 23:00:00');                            // T9 119 min, resolved on the last second of January: breach
        $this->ticket($a, $outage, '2026-01-31 23:00:00', null, '2026-02-01 00:00:00', '2026-01-31 23:30:00');                             // T10 60 min, resolved one second after January and 30 minutes late: breach

        // Company B, with a category named like A's "Support".
        $b = $this->companyB;
        $supportB = $this->category($b, 'Support', 'SUP-B');
        $this->ticket($b, $supportB, '2026-01-05 08:00:00', '2026-01-05 08:40:00', '2026-01-05 10:00:00', '2026-01-05 12:00:00');          // TB1 120 min: on time
        $this->ticket($b, $supportB, '2026-01-06 08:00:00', null, '2026-01-06 09:00:00', '2026-01-06 08:30:00');                           // TB2 60 min: breach

        return ['support' => $support, 'support_twin' => $supportTwin, 'outage' => $outage, 'idle' => $idle];
    }

    /**
     * @return array{central: Location, east: Location, east_annex: Location}
     */
    private function seedAssets(): array
    {
        $a = $this->companyA;
        $central = $this->location($a, 'POP-C', 'POP Central');
        $east = $this->location($a, 'POP-E1', 'POP East');
        $eastAnnex = $this->location($a, 'POP-E2', 'POP East');     // same name as POP-E1: by_location merges them

        // Company A. Columns: type, status, location, linked to a customer.
        $router1 = $this->asset($a, 'router', 'available', $central);                                       // A1
        $router2 = $this->asset($a, 'router', 'installed', $central, true);                                 // A2
        $this->asset($a, 'router', 'retired', $central);                                                    // A3
        $switch = $this->asset($a, 'switch', 'installed', $east, true);                                     // A4
        $this->asset($a, 'switch', 'damaged', $eastAnnex);                                                  // A5
        $olt = $this->asset($a, 'olt', 'installed', $east, true);                                           // A6
        $this->asset($a, 'olt', 'retired', null, true);                                                     // A7 retired yet linked to a customer; no location
        $this->asset($a, 'onu_ont', 'available');                                                           // A8 no location
        $this->asset($a, 'router', 'retired', $east);                                                       // A9

        // Installation records. Columns: asset, location, installed_at [, removed_at].
        $this->installation($a, $switch, $east, '2026-01-01 00:00:00');                                     // N1 first second of January, on a switch
        $this->installation($a, $router1, $central, '2026-01-31 23:59:59', '2026-02-10 09:00:00');          // N2 last second of January, on a router; removed later but still a record
        $this->installation($a, $router2, $central, '2025-12-31 23:59:59');                                 // N3 one second early
        $this->installation($a, $olt, $east, '2026-02-01 00:00:00');                                        // N4 one second late

        // Company B, with a location named like A's "POP Central".
        $b = $this->companyB;
        $centralB = $this->location($b, 'POP-C', 'POP Central');
        $switchB1 = $this->asset($b, 'switch', 'available', $centralB);                                     // AB1
        $switchB2 = $this->asset($b, 'switch', 'installed', $centralB, true);                               // AB2
        $this->asset($b, 'router', 'retired');                                                              // AB3
        $this->installation($b, $switchB2, $centralB, '2026-01-10 08:00:00');                               // NB1
        $this->installation($b, $switchB1, $centralB, '2026-01-20 08:00:00');                               // NB2

        return ['central' => $central, 'east' => $east, 'east_annex' => $eastAnnex];
    }

    private function subscription(Company $company, string $status, int $mrc, string $createdAt, ?string $terminatedAt = null): ServiceSubscription
    {
        return ServiceSubscription::factory()->create([
            'company_id' => $company->id,
            'customer_id' => $this->customerId($company),
            'service_package_id' => $this->packageId(),
            'installation_address_id' => $this->addressId($company),
            'status' => $status,
            'mrc_amount' => $mrc,
            'created_at' => $createdAt,
            'terminated_at' => $terminatedAt,
        ]);
    }

    private function invoice(Company $company, string $type, string $status, int $total, int $paid, string $issueDate): Invoice
    {
        return Invoice::factory()->create([
            'company_id' => $company->id,
            'customer_id' => $this->customerId($company),
            'created_by' => $this->users[$company->id]->id,
            'type' => $type,
            'status' => $status,
            'issue_date' => $issueDate,
            'due_date' => Carbon::parse($issueDate)->addDays(14)->toDateString(), // a figure dated by due_date would differ
            'total' => $total,
            'paid_amount' => $paid,
        ]);
    }

    private function payment(Invoice $invoice, string $paidAt): Payment
    {
        return Payment::query()->create([
            'company_id' => $invoice->company_id,
            'invoice_id' => $invoice->id,
            'amount' => $invoice->paid_amount,
            'method' => 'transfer',
            'paid_at' => $paidAt,
            'received_by' => $this->users[$invoice->company_id]->id,
        ]);
    }

    private function workOrder(Company $company, string $type, string $status, ?string $completedAt): void
    {
        WorkOrderFactory::new()->create([
            'company_id' => $company->id,
            'created_by' => $this->users[$company->id]->id,
            'type' => $type,
            'status' => $status,
            'completed_at' => $completedAt,
        ]);
    }

    private function category(Company $company, string $name, string $code): TicketCategory
    {
        return TicketCategory::query()->create([
            'company_id' => $company->id,
            'name' => $name,
            'code' => $code,
            'default_sla_hours' => 4,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);
    }

    private function ticket(
        Company $company,
        TicketCategory $category,
        string $createdAt,
        ?string $firstResponseAt,
        ?string $resolvedAt,
        ?string $slaDeadline,
        ?string $status = null,
        ?string $responseDeadline = null,
    ): Ticket {
        // forceCreate: the Ticket factory builds a new company, category and user on every call.
        return Ticket::query()->forceCreate([
            'company_id' => $company->id,
            'code' => 'TCK-'.++$this->ticketSequence,
            'title' => 'Fixture ticket',
            'source' => 'internal',
            'category_id' => $category->id,
            'status' => $status ?? ($resolvedAt === null ? 'open' : 'resolved'),
            'priority' => 'medium',
            'created_by' => $this->users[$company->id]->id,
            'created_at' => $createdAt,
            'first_response_at' => $firstResponseAt,
            'resolved_at' => $resolvedAt,
            'sla_deadline' => $slaDeadline,
            'response_deadline' => $responseDeadline,
        ]);
    }

    private function location(Company $company, string $code, string $name): Location
    {
        return Location::factory()->create(['company_id' => $company->id, 'code' => $code, 'name' => $name]);
    }

    private function asset(Company $company, string $type, string $status, ?Location $location = null, bool $linkedToCustomer = false): NetworkAsset
    {
        return NetworkAssetFactory::new()->create([
            'company_id' => $company->id,
            'asset_type' => $type,
            'status' => $status,
            'location_id' => $location?->id,
            'customer_id' => $linkedToCustomer ? $this->customerId($company) : null,
        ]);
    }

    private function installation(Company $company, NetworkAsset $asset, Location $location, string $installedAt, ?string $removedAt = null): NetworkAssetInstallation
    {
        // forceCreate: company_id is not mass assignable on installation records.
        return NetworkAssetInstallation::query()->forceCreate([
            'company_id' => $company->id,
            'network_asset_id' => $asset->id,
            'location_id' => $location->id,
            'installed_by' => $this->users[$company->id]->id,
            'installed_at' => $installedAt,
            'removed_at' => $removedAt,
        ]);
    }

    private function customerId(Company $company): int
    {
        return $this->customerIds[$company->id] ??= Customer::factory()->create(['company_id' => $company->id])->id;
    }

    private function addressId(Company $company): int
    {
        return $this->addressIds[$company->id] ??= CustomerAddress::factory()
            ->create(['customer_id' => $this->customerId($company)])->id;
    }

    /**
     * One package shared by both companies: its factory builds its profiles for the first company only,
     * so a package per tenant collides (same reason as ReportCapabilityTest). Reports never read it.
     */
    private function packageId(): int
    {
        return $this->packageId ??= ServicePackage::factory()->create()->id;
    }

    private function actingAsCompany(Company $company): void
    {
        $this->actingAs($this->users[$company->id]);
    }

    // ---------------------------------------------------------------------------------------------
    // Assertions
    // ---------------------------------------------------------------------------------------------

    /**
     * The whole payload of a report: every key is pinned, no key may be missing and none may be extra.
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function assertPayload(ReportType $report, array $expected, array $actual, string $scenario = ''): void
    {
        $this->assertMetricsAreV1($report, array_keys($expected));
        $this->assertEqualsCanonicalizing(array_keys($expected), array_keys($actual), "{$report->value} payload keys {$scenario}");
        $this->assertSame($this->canonical($expected), $this->canonical($actual), "{$report->value} {$scenario}");
    }

    /**
     * Only the listed metrics of a payload.
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function assertMetrics(ReportType $report, array $expected, array $actual, string $scenario = ''): void
    {
        $this->assertMetricsAreV1($report, array_keys($expected));
        $this->assertSame(
            $this->canonical($expected),
            $this->canonical(array_intersect_key($actual, $expected)),
            "{$report->value} {$scenario}",
        );
    }

    /**
     * Ties this suite to the formula version: every metric asserted must be registered, and still at v1.
     *
     * @param  list<string>  $keys
     */
    private function assertMetricsAreV1(ReportType $report, array $keys): void
    {
        $versions = [];
        foreach (MetricRegistry::forReport($report) as $metric) {
            $versions[$metric->key] = $metric->version;
        }

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $versions, "{$report->value}.{$key} is not a registered metric");
            $this->assertSame(
                'v1',
                $versions[$key],
                "{$report->value}.{$key} left v1: characterize the new formula and update ReportFormulaV1Test",
            );
        }
    }

    /**
     * Strict on values and on null ("no data" is never 0) but blind to how a driver spells a number
     * (DECIMAL sums arrive as strings, counts as ints, rounded averages as floats) and to the order of
     * grouped results, which MySQL does not guarantee.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function canonical(array $payload): array
    {
        $payload = $this->asFloats($payload);
        ksort($payload);

        foreach (['asset_distribution', 'status_distribution', 'by_location'] as $breakdown) {
            if (isset($payload[$breakdown])) {
                ksort($payload[$breakdown]);
            }
        }

        if (isset($payload['by_category'])) {
            usort($payload['by_category'], fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        }

        return $payload;
    }

    private function asFloats(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->asFloats($item), $value);
        }

        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float) $value : $value;
    }
}
