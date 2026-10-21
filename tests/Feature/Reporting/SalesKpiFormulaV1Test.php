<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Services\BillingService;
use Modules\Reporting\Queries\SalesKpiQuery;
use Modules\Reporting\Registry\MetricRegistry;
use Modules\Reporting\Registry\ReportType;
use Modules\Service\Models\ServicePackage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\BuildsCustomerAcquirers;

/**
 * Characterization of the v1 formulas of the Sales KPI report.
 *
 * One small world is built for a stated reason per row (every fixture row below says what it proves) and the
 * exact figures of every acquirer, of the Unassigned bucket and of the total are pinned. Expected values are
 * worked out by hand from the formulas documented in MetricRegistry, never copied from query output. Company B
 * owns rows that must never reach company A, and the reverse.
 *
 * VERSION CONTRACT: these expectations ARE v1 of every Sales metric. A change to what a metric computes MUST bump
 * its version in MetricRegistry (v2, ...) and update this test together with it. Every payload assertion first
 * checks that the metrics it asserts are still registered as v1.
 *
 * The world (company A). Period used by most tests: 2026-03-01 .. 2026-03-31, To inclusive.
 *   Acquirers: Ayu Active, Budi Both (eligible), Citra Former (inactive user, credited), Dewi Idle (eligible, no
 *   customers), Eko Technician (no customer.create: never listed), Bayu (company B).
 */
class SalesKpiFormulaV1Test extends TestCase
{
    use BuildsCustomerAcquirers;
    use RefreshDatabase;

    /** Registry key order, which is also the key order of every figures array. */
    private const METRICS = [
        'customers_acquired', 'subscriptions_activated', 'active_mrr', 'invoiced',
        'collected', 'outstanding', 'collection_rate',
    ];

    private Company $companyB;

    private User $admin;

    private User $ayu;

    private User $budi;

    private User $citra;

    private User $dewi;

    private User $eko;

    private User $bayu;

    private ?int $packageId = null;

    /** @var array<int, int> customer id => installation address id */
    private array $addressIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndCompany();
        // The edge fixtures are pinned to calendar days of the storage timezone (UTC).
        $this->company->update(['timezone' => 'UTC']);
        $this->companyB = Company::factory()->create(['is_active' => true, 'timezone' => 'UTC']);

        $this->admin = $this->employee('admin', null, ['name' => 'Admin Actor'], false);
        $this->ayu = $this->salesStaff(null, ['name' => 'Ayu Active']);
        $this->budi = $this->employee('manager', null, ['name' => 'Budi Both']);
        $this->citra = $this->salesStaff(null, ['name' => 'Citra Former', 'is_active' => false]);
        $this->dewi = $this->salesStaff(null, ['name' => 'Dewi Idle']);
        $this->eko = $this->employee('technician', null, ['name' => 'Eko Technician']);
        $this->bayu = $this->employee('manager', $this->companyB, ['name' => 'Bayu Other Company']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // Version guard
    // ---------------------------------------------------------------------------------------------

    public function test_every_metric_characterized_here_is_registered_at_v1(): void
    {
        $registered = array_map(fn ($metric): string => $metric->key, MetricRegistry::forReport(ReportType::Sales));

        $this->assertSame(self::METRICS, $registered, 'characterized metrics differ from the registry');
        $this->assertMetricsAreV1();
        $this->assertSame('sales.v1', MetricRegistry::formulaVersion(ReportType::Sales));
    }

    // ---------------------------------------------------------------------------------------------
    // Period figures and the range
    // ---------------------------------------------------------------------------------------------

    public function test_march_figures_per_acquirer_unassigned_bucket_and_total(): void
    {
        $this->seedWorld();

        $report = $this->report('2026-03-01', '2026-03-31');

        $this->assertReport([
            'rows' => [
                $this->row($this->ayu, true, 2, 2, '300000.00', '1750000.00', '1200000.00', '750000.00', '68.57'),
                $this->row($this->budi, true, 1, 2, '50000.00', '1400000.00', '600000.00', '800000.00', '42.86'),
                $this->row($this->citra, false, 1, 1, '25000.00', '150000.00', '200000.00', '0.00', '133.33'),
                $this->row($this->dewi, true, 0, 0, '0.00', '0.00', '0.00', '0.00', null),
            ],
            'unassigned' => $this->figures(2, 1, '10000.00', '150000.00', '40000.00', '110000.00', '26.67'),
            'totals' => $this->figures(6, 6, '385000.00', '3450000.00', '2040000.00', '1660000.00', '59.13'),
        ], $report, 'march');
    }

    public function test_without_a_range_every_matching_record_counts(): void
    {
        $this->seedWorld();

        $this->assertReport([
            'rows' => [
                $this->row($this->ayu, true, 4, 3, '300000.00', '2450000.00', '1700000.00', '750000.00', '69.39'),
                $this->row($this->budi, true, 1, 3, '50000.00', '1400000.00', '600000.00', '800000.00', '42.86'),
                $this->row($this->citra, false, 1, 1, '25000.00', '200000.00', '200000.00', '0.00', '100.00'),
                $this->row($this->dewi, true, 0, 0, '0.00', '0.00', '0.00', '0.00', null),
            ],
            'unassigned' => $this->figures(2, 1, '10000.00', '150000.00', '40000.00', '110000.00', '26.67'),
            'totals' => $this->figures(8, 8, '385000.00', '4200000.00', '2540000.00', '1660000.00', '60.48'),
        ], $this->report(), 'all time');
    }

    public function test_a_single_date_limits_the_period_from_or_up_to_that_day(): void
    {
        $this->seedWorld();

        // From alone: the 2026-02-28 23:59:59 rows (C3, S2, I4, P1b and Citra's February invoice) drop out; April rows stay in.
        $this->assertReport([
            'rows' => [
                $this->row($this->ayu, true, 3, 2, '300000.00', '2050000.00', '1300000.00', '750000.00', '63.41'),
                $this->row($this->budi, true, 1, 3, '50000.00', '1400000.00', '600000.00', '800000.00', '42.86'),
                $this->row($this->citra, false, 1, 1, '25000.00', '150000.00', '200000.00', '0.00', '133.33'),
                $this->row($this->dewi, true, 0, 0, '0.00', '0.00', '0.00', '0.00', null),
            ],
            'unassigned' => $this->figures(2, 1, '10000.00', '150000.00', '40000.00', '110000.00', '26.67'),
            'totals' => $this->figures(7, 7, '385000.00', '3750000.00', '2140000.00', '1660000.00', '57.07'),
        ], $this->report('2026-03-01', null), 'only From');

        // To alone: the April rows (C4, S4, I3, P2b) drop out; everything before March stays in.
        $this->assertReport([
            'rows' => [
                $this->row($this->ayu, true, 3, 3, '300000.00', '2150000.00', '1600000.00', '750000.00', '74.42'),
                $this->row($this->budi, true, 1, 2, '50000.00', '1400000.00', '600000.00', '800000.00', '42.86'),
                $this->row($this->citra, false, 1, 1, '25000.00', '200000.00', '200000.00', '0.00', '100.00'),
                $this->row($this->dewi, true, 0, 0, '0.00', '0.00', '0.00', '0.00', null),
            ],
            'unassigned' => $this->figures(2, 1, '10000.00', '150000.00', '40000.00', '110000.00', '26.67'),
            'totals' => $this->figures(7, 7, '385000.00', '3900000.00', '2440000.00', '1660000.00', '62.56'),
        ], $this->report(null, '2026-03-31'), 'only To');
    }

    public function test_the_last_day_of_the_range_is_inclusive_to_its_last_second(): void
    {
        $this->seedWorld();

        // Only rows stamped on 2026-03-31 count: customer C2 (23:59:59), subscription S3, invoice I2 (500000 issued
        // that day), payment P2a (200000 at 23:59:59). The rows one second or one day later stay out.
        foreach (['2026-03-31', '2026-03-31 23:59:59'] as $dateTo) {
            $this->assertReport([
                'rows' => [
                    $this->row($this->ayu, true, 1, 1, '300000.00', '500000.00', '200000.00', '750000.00', '40.00'),
                    $this->row($this->budi, true, 0, 0, '50000.00', '0.00', '0.00', '800000.00', null),
                    $this->row($this->citra, false, 0, 0, '25000.00', '0.00', '0.00', '0.00', null),
                    $this->row($this->dewi, true, 0, 0, '0.00', '0.00', '0.00', '0.00', null),
                ],
                'unassigned' => $this->figures(0, 0, '10000.00', '0.00', '0.00', '110000.00', null),
                'totals' => $this->figures(1, 1, '385000.00', '500000.00', '200000.00', '1660000.00', '40.00'),
            ], $this->report('2026-03-31', $dateTo), "to {$dateTo}");
        }
    }

    public function test_the_first_day_of_the_range_is_inclusive_from_its_first_second(): void
    {
        $this->seedWorld();

        // 2026-03-01 only: customer C1 (00:00:00), subscription S1, invoice I1 (1000000 issued that day) and payment
        // P1a (600000 at 00:00:00). The 2026-02-28 23:59:59 rows (C3, S2, I4, P1b) stay out.
        $this->assertReport([
            'rows' => [
                $this->row($this->ayu, true, 1, 1, '300000.00', '1000000.00', '600000.00', '750000.00', '60.00'),
                $this->row($this->budi, true, 0, 0, '50000.00', '0.00', '0.00', '800000.00', null),
                $this->row($this->citra, false, 0, 0, '25000.00', '0.00', '0.00', '0.00', null),
                $this->row($this->dewi, true, 0, 0, '0.00', '0.00', '0.00', '0.00', null),
            ],
            'unassigned' => $this->figures(0, 0, '10000.00', '0.00', '0.00', '110000.00', null),
            'totals' => $this->figures(1, 1, '385000.00', '1000000.00', '600000.00', '1660000.00', '60.00'),
        ], $this->report('2026-03-01', '2026-03-01'), 'first day');
    }

    // ---------------------------------------------------------------------------------------------
    // What never counts, and what always counts
    // ---------------------------------------------------------------------------------------------

    public function test_reversed_payments_never_count_even_when_reversed_after_the_period(): void
    {
        $this->seedWorld();

        // P12r (30000) was recorded on 2026-03-16 inside the period and reversed on 2026-04-02 outside it.
        $reversed = Payment::query()->whereNotNull('cancelled_at')->get();
        $this->assertCount(1, $reversed, 'the fixture holds exactly one reversed payment');
        $this->assertSame('2026-03-16', $reversed->first()->paid_at->toDateString());
        $this->assertSame('2026-04-02', $reversed->first()->cancelled_at->toDateString());

        $this->assertSame('40000.00', $this->report('2026-03-01', '2026-03-31')['unassigned']['collected']);
        $this->assertSame('40000.00', $this->report()['unassigned']['collected']);
    }

    public function test_soft_deleted_customers_keep_their_money_but_are_not_counted_as_customers(): void
    {
        $this->seedWorld();

        // C6 (Budi) is soft-deleted: its subscription S6, invoice I10 and payment P10 still count, the customer does not.
        $budi = $this->report('2026-03-01', '2026-03-31')['rows'][1];

        $this->assertSame('Budi Both', $budi['name']);
        $this->assertSame(1, $budi['customers_acquired'], 'C5 only');
        $this->assertSame(2, $budi['subscriptions_activated'], 'S5 and S6');
        $this->assertSame('1400000.00', $budi['invoiced'], 'I9 800000 + I10 600000');
        $this->assertSame('600000.00', $budi['collected'], 'P10');
        $this->assertSame(1, Customer::onlyTrashed()->count(), 'the fixture holds exactly one soft-deleted customer');
    }

    public function test_soft_deleted_invoices_and_the_payments_of_those_invoices_never_count(): void
    {
        $this->seedWorld();

        // I8 (600000, paid by P8 600000 on 2026-03-06) is soft-deleted: it is in no figure of Ayu.
        $this->assertSame(1, Invoice::onlyTrashed()->count());
        $this->assertSame(1, Payment::query()->where('amount', 600000)->whereDate('paid_at', '2026-03-06')->count());

        $ayu = $this->report('2026-03-01', '2026-03-31')['rows'][0];
        $this->assertSame('1750000.00', $ayu['invoiced'], 'I1 + I2 + I7, not I8');
        $this->assertSame('1200000.00', $ayu['collected'], 'P1a + P2a + P4, not P8');
        $this->assertSame('750000.00', $ayu['outstanding'], 'I2 200000 + I3 300000 + I7 250000, not I8');
    }

    public function test_draft_and_cancelled_invoices_are_never_invoiced_or_outstanding(): void
    {
        $this->seedWorld();

        // Ayu also owns I5 (draft 900000) and I6 (cancelled 700000), both dated inside March.
        $this->assertSame(1, Invoice::query()->where('status', 'draft')->count());
        $this->assertSame(1, Invoice::query()->where('status', 'cancelled')->count());

        $ayu = $this->report('2026-03-01', '2026-03-31')['rows'][0];
        $this->assertSame('1750000.00', $ayu['invoiced']);
        $this->assertSame('750000.00', $ayu['outstanding']);
    }

    public function test_deleted_and_inactive_records_never_enter_the_snapshot_mrr(): void
    {
        $this->seedWorld();

        // Ayu owns S1 100000 active, S2 200000 active, S3 400000 suspended, S9 999999 pending, S10 888888 active but
        // soft-deleted: only S1 + S2 are monthly recurring revenue.
        $this->assertSame('300000.00', $this->report()['rows'][0]['active_mrr']);
        $this->assertSame('300000.00', $this->report('2026-03-01', '2026-03-31')['rows'][0]['active_mrr'], 'a snapshot ignores the range');
    }

    public function test_a_customer_credited_to_a_user_of_another_company_is_unassigned(): void
    {
        $this->seedWorld();

        // C9 belongs to company A but names Bayu (company B) as acquirer: it lands in Unassigned with its invoice I13,
        // and Bayu is not an acquirer of company A.
        $report = $this->report('2026-03-01', '2026-03-31');

        $this->assertSame(2, $report['unassigned']['customers_acquired'], 'C8 and C9');
        $this->assertSame('150000.00', $report['unassigned']['invoiced'], 'I12 100000 + I13 50000');
        $this->assertNotContains($this->bayu->public_id, array_column($report['rows'], 'id'));
        $this->assertNotContains('Bayu Other Company', array_column($report['acquirers'], 'name'));
    }

    // ---------------------------------------------------------------------------------------------
    // Acquirer list
    // ---------------------------------------------------------------------------------------------

    public function test_acquirer_options_are_eligible_or_credited_users_of_the_company_by_name(): void
    {
        $this->seedWorld();

        $this->assertSame([
            ['id' => $this->ayu->public_id, 'name' => 'Ayu Active', 'is_active' => true],
            ['id' => $this->budi->public_id, 'name' => 'Budi Both', 'is_active' => true],
            ['id' => $this->citra->public_id, 'name' => 'Citra Former', 'is_active' => false],
            ['id' => $this->dewi->public_id, 'name' => 'Dewi Idle', 'is_active' => true],
        ], $this->report()['acquirers'], 'Eko (no customer.create), the admin (no profile) and Bayu (company B) are not acquirers');
    }

    public function test_a_user_credited_only_on_a_soft_deleted_customer_is_still_listed_with_the_money(): void
    {
        // Fiona is not eligible (inactive) and her only customer is soft-deleted, yet its paid invoice is cash that must
        // not vanish from the report.
        $fiona = $this->salesStaff(null, ['name' => 'Fiona Gone', 'is_active' => false]);
        $customer = $this->customer($fiona, '2026-05-02 09:00:00');
        $invoice = $this->invoice($customer, 'paid', 300000, 300000, '2026-05-03');
        $this->payment($invoice, 300000, '2026-05-04 10:00:00');
        $customer->delete();

        $report = $this->report('2026-05-01', '2026-05-31');
        $fionaRow = collect($report['rows'])->firstWhere('id', $fiona->public_id);

        $this->assertNotNull($fionaRow, 'Fiona is listed');
        $this->assertFalse($fionaRow['is_active']);
        $this->assertSame(0, $fionaRow['customers_acquired']);
        $this->assertSame('300000.00', $fionaRow['invoiced']);
        $this->assertSame('300000.00', $fionaRow['collected']);
        $this->assertSame('100.00', $fionaRow['collection_rate']);
        $this->assertSame('300000.00', $report['totals']['collected']);
    }

    public function test_the_acquirer_filter_narrows_rows_and_totals_but_not_the_option_list(): void
    {
        $this->seedWorld();

        $report = $this->report('2026-03-01', '2026-03-31', $this->budi->id);

        $this->assertReport([
            'rows' => [$this->row($this->budi, true, 1, 2, '50000.00', '1400000.00', '600000.00', '800000.00', '42.86')],
            'unassigned' => null,
            'totals' => $this->figures(1, 2, '50000.00', '1400000.00', '600000.00', '800000.00', '42.86'),
        ], $report, 'filtered to Budi');
        $this->assertCount(4, $report['acquirers']);
    }

    public function test_an_acquirer_of_another_company_a_non_acquirer_and_an_unknown_id_abort_with_403(): void
    {
        $this->seedWorld();

        foreach ([$this->bayu->id, $this->eko->id, $this->admin->id, 0, 987654321] as $userId) {
            try {
                $this->report('2026-03-01', '2026-03-31', $userId);
                $this->fail("Acquirer {$userId} must be refused.");
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode(), "acquirer {$userId}");
            }
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Tenancy
    // ---------------------------------------------------------------------------------------------

    public function test_company_b_rows_never_reach_company_a_and_the_reverse(): void
    {
        $this->seedWorld();

        $this->assertReport([
            'rows' => [$this->row($this->bayu, true, 1, 1, '777777.00', '5000000.00', '5000000.00', '0.00', '100.00')],
            'unassigned' => $this->figures(0, 0, '0.00', '0.00', '0.00', '0.00', null),
            'totals' => $this->figures(1, 1, '777777.00', '5000000.00', '5000000.00', '0.00', '100.00'),
        ], SalesKpiQuery::execute((int) $this->companyB->id, '2026-03-01', '2026-03-31'), 'company B');

        // Company A is exactly what the March test pins: nothing of B's 5000000 shows up.
        $this->assertSame('3450000.00', $this->report('2026-03-01', '2026-03-31')['totals']['invoiced']);
    }

    public function test_the_explicit_company_wins_over_the_acting_users_company(): void
    {
        $this->seedWorld();

        // The actor belongs to company A, the report is asked for company B: the implicit global scope must not leak A in.
        $this->actingAs($this->admin);
        $report = SalesKpiQuery::execute((int) $this->companyB->id);

        $this->assertSame([$this->bayu->public_id], array_column($report['rows'], 'id'));
        $this->assertSame('5000000.00', $report['totals']['invoiced']);
    }

    // ---------------------------------------------------------------------------------------------
    // Attribution: the current acquirer applies to all history
    // ---------------------------------------------------------------------------------------------

    public function test_correcting_the_acquirer_moves_the_whole_history_and_keeps_the_company_total(): void
    {
        $this->seedWorld();

        $before = $this->report('2026-03-01', '2026-03-31');

        // C1 carries every invoice and payment of Ayu in March (I1, I2, I7, P1a, P2a, P4) and S1, S2.
        Customer::query()
            ->where('acquired_by_user_id', $this->ayu->id)
            ->where('created_at', '2026-03-01 00:00:00')
            ->firstOrFail()
            ->forceFill(['acquired_by_user_id' => $this->budi->id])
            ->save();

        $after = $this->report('2026-03-01', '2026-03-31');

        $this->assertSame('0.00', $after['rows'][0]['invoiced'], 'Ayu lost I1 + I2 + I7');
        $this->assertSame('0.00', $after['rows'][0]['collected']);
        $this->assertSame('3150000.00', $after['rows'][1]['invoiced'], 'Budi gained them: 1400000 + 1750000');
        $this->assertSame('1800000.00', $after['rows'][1]['collected'], 'Budi: 600000 + 1200000');
        $this->assertSame($before['totals'], $after['totals'], 're-attribution never changes the company total');
        $this->assertSame($before['unassigned'], $after['unassigned']);
    }

    // ---------------------------------------------------------------------------------------------
    // Collection rate and number format
    // ---------------------------------------------------------------------------------------------

    public function test_collection_rate_rounds_half_up_and_is_empty_when_nothing_was_invoiced(): void
    {
        // Dewi: invoiced 800.00, collected 1.00 -> 0.125 percent -> "0.13" (half up).
        $dewiCustomer = $this->customer($this->dewi, '2026-05-01 08:00:00');
        $invoice = $this->invoice($dewiCustomer, 'partial', 800, 1, '2026-05-10');
        $this->payment($invoice, 1, '2026-05-11 08:00:00');

        // Ayu: nothing invoiced in May, but a payment of an April invoice arrives: collected 500.00, rate empty.
        $ayuCustomer = $this->customer($this->ayu, '2026-04-01 08:00:00');
        $older = $this->invoice($ayuCustomer, 'paid', 500, 500, '2026-04-10');
        $this->payment($older, 500, '2026-05-05 08:00:00');

        $rows = collect($this->report('2026-05-01', '2026-05-31')['rows'])->keyBy('name');

        $this->assertSame('0.13', $rows['Dewi Idle']['collection_rate']);
        $this->assertSame('1.00', $rows['Dewi Idle']['collected']);
        $this->assertSame('800.00', $rows['Dewi Idle']['invoiced']);
        $this->assertSame('799.00', $rows['Dewi Idle']['outstanding']);
        $this->assertSame('500.00', $rows['Ayu Active']['collected']);
        $this->assertSame('0.00', $rows['Ayu Active']['invoiced']);
        $this->assertNull($rows['Ayu Active']['collection_rate'], 'no division by zero');
    }

    public function test_money_is_a_two_decimal_string_and_counts_are_integers(): void
    {
        $this->seedWorld();

        $report = $this->report('2026-03-01', '2026-03-31');

        foreach ([...$report['rows'], $report['unassigned'], $report['totals']] as $figures) {
            foreach (['active_mrr', 'invoiced', 'collected', 'outstanding'] as $money) {
                $this->assertIsString($figures[$money]);
                $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $figures[$money], $money);
            }
            foreach (['customers_acquired', 'subscriptions_activated'] as $count) {
                $this->assertIsInt($figures[$count], $count);
            }
            $this->assertTrue($figures['collection_rate'] === null || preg_match('/^\d+\.\d{2}$/', $figures['collection_rate']) === 1);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Order of the rows
    // ---------------------------------------------------------------------------------------------

    public function test_rows_sort_by_every_figure_with_ties_by_name_and_an_empty_rate_always_last(): void
    {
        $this->seedWorld();

        $order = function (string $sortBy, string $sortDir): array {
            return array_column($this->report('2026-03-01', '2026-03-31', null, $sortBy, $sortDir)['rows'], 'name');
        };

        // March collected: Ayu 1200000, Budi 600000, Citra 200000, Dewi 0.
        $this->assertSame(['Dewi Idle', 'Citra Former', 'Budi Both', 'Ayu Active'], $order('collected', 'asc'));
        $this->assertSame(['Ayu Active', 'Budi Both', 'Citra Former', 'Dewi Idle'], $order('collected', 'desc'));
        // March rate: Citra 133.33, Ayu 68.57, Budi 42.86, Dewi empty.
        $this->assertSame(['Budi Both', 'Ayu Active', 'Citra Former', 'Dewi Idle'], $order('collection_rate', 'asc'));
        $this->assertSame(['Citra Former', 'Ayu Active', 'Budi Both', 'Dewi Idle'], $order('collection_rate', 'desc'));
        // March customers acquired: Ayu 2, Budi 1, Citra 1, Dewi 0; the Budi/Citra tie breaks by name.
        $this->assertSame(['Dewi Idle', 'Budi Both', 'Citra Former', 'Ayu Active'], $order('customers_acquired', 'asc'));
        $this->assertSame(['Ayu Active', 'Budi Both', 'Citra Former', 'Dewi Idle'], $order('customers_acquired', 'desc'));
        // Names, case-insensitive, both ways.
        $this->assertSame(['Dewi Idle', 'Citra Former', 'Budi Both', 'Ayu Active'], $order('name', 'desc'));
        // March invoiced: Ayu 1750000, Budi 1400000, Citra 150000, Dewi 0. MRR: Ayu 300000, Budi 50000, Citra 25000, Dewi 0.
        $this->assertSame(['Ayu Active', 'Budi Both', 'Citra Former', 'Dewi Idle'], $order('invoiced', 'desc'));
        $this->assertSame(['Ayu Active', 'Budi Both', 'Citra Former', 'Dewi Idle'], $order('active_mrr', 'desc'));
        // Subscriptions activated: Ayu 2, Budi 2, Citra 1, Dewi 0; Ayu/Budi tie by name.
        $this->assertSame(['Dewi Idle', 'Citra Former', 'Ayu Active', 'Budi Both'], $order('subscriptions_activated', 'asc'));
        // Outstanding: Ayu 750000, Budi 800000, Citra 0, Dewi 0; the Citra/Dewi tie breaks by name.
        $this->assertSame(['Citra Former', 'Dewi Idle', 'Ayu Active', 'Budi Both'], $order('outstanding', 'asc'));
    }

    // ---------------------------------------------------------------------------------------------
    // The world
    // ---------------------------------------------------------------------------------------------

    private function seedWorld(): void
    {
        // Customers. Columns: acquirer, created_at.
        $c1 = $this->customer($this->ayu, '2026-03-01 00:00:00');      // C1 first second of March: in
        $c2 = $this->customer($this->ayu, '2026-03-31 23:59:59');      // C2 last second of March: in
        $this->customer($this->ayu, '2026-02-28 23:59:59');            // C3 one second before: out of March
        $this->customer($this->ayu, '2026-04-01 00:00:00');            // C4 one second after: out of March
        $c5 = $this->customer($this->budi, '2026-03-15 10:00:00');     // C5 in
        $c6 = $this->customer($this->budi, '2026-03-20 10:00:00');     // C6 in, then soft-deleted: its money still counts
        $c7 = $this->customer($this->citra, '2026-03-10 10:00:00');    // C7 inactive acquirer, still credited
        $c8 = $this->customer(null, '2026-03-12 10:00:00');            // C8 no acquirer: Unassigned
        $c9 = $this->customer($this->bayu, '2026-03-05 10:00:00');     // C9 acquirer of ANOTHER company: Unassigned

        // Subscriptions. Columns: customer, status, mrc, activation date.
        $this->subscription($c1, 'active', 100000, '2026-03-01');      // S1 activated on the first day
        $this->subscription($c1, 'active', 200000, '2026-02-28');      // S2 activated the day before: MRR only
        $this->subscription($c2, 'suspended', 400000, '2026-03-31');   // S3 activated on the last day, suspended since: counts as activated, not MRR
        $this->subscription($c5, 'active', 50000, '2026-04-01');       // S4 activated the day after: MRR only
        $this->subscription($c5, 'terminated', 70000, '2026-03-10');   // S5 activated and terminated: counts as activated, not MRR
        $this->subscription($c6, 'terminated', 30000, '2026-03-22');   // S6 customer soft-deleted below: still counts as activated
        $this->subscription($c7, 'active', 25000, '2026-03-11');       // S7 inactive acquirer
        $this->subscription($c8, 'active', 10000, '2026-03-13');       // S8 Unassigned
        $this->subscription($c1, 'pending', 999999, null);             // S9 never activated: in nothing
        $this->subscription($c1, 'active', 888888, '2026-03-05')->delete(); // S10 soft-deleted: in nothing

        // Invoices. Columns: customer, status, total, paid (sum of its non-reversed payments), issue date.
        $i1 = $this->invoice($c1, 'paid', 1000000, 1000000, '2026-03-01');     // I1 first day
        $i2 = $this->invoice($c1, 'partial', 500000, 300000, '2026-03-31');    // I2 last day, 200000 still owed
        $this->invoice($c1, 'sent', 300000, 0, '2026-04-01');                  // I3 one day after: outstanding only
        $i4 = $this->invoice($c1, 'paid', 400000, 400000, '2026-02-28');       // I4 one day before: its payment lands in March
        $this->invoice($c1, 'draft', 900000, 0, '2026-03-10');                 // I5 draft: in nothing
        $this->invoice($c1, 'cancelled', 700000, 0, '2026-03-10');             // I6 cancelled: in nothing
        $this->invoice($c1, 'overdue', 250000, 0, '2026-03-05');               // I7 overdue: invoiced and outstanding
        $i8 = $this->invoice($c1, 'paid', 600000, 600000, '2026-03-05');       // I8 soft-deleted below, with its payment P8: in nothing
        $this->invoice($c5, 'sent', 800000, 0, '2026-03-15');                  // I9
        $i10 = $this->invoice($c6, 'paid', 600000, 600000, '2026-03-21');      // I10 customer soft-deleted below
        $i11 = $this->invoice($c7, 'paid', 150000, 150000, '2026-03-12');      // I11
        $i12 = $this->invoice($c8, 'partial', 100000, 40000, '2026-03-14');    // I12 Unassigned, 60000 owed
        $this->invoice($c9, 'sent', 50000, 0, '2026-03-06');                   // I13 customer of a foreign acquirer: Unassigned
        $i14 = $this->invoice($c7, 'paid', 50000, 50000, '2026-02-20');        // I14 older invoice paid in March: rate above 100

        // Payments. Columns: invoice, amount, paid_at.
        $this->payment($i1, 600000, '2026-03-01 00:00:00');   // P1a first second of March
        $this->payment($i1, 400000, '2026-02-28 23:59:59');   // P1b one second before
        $this->payment($i2, 200000, '2026-03-31 23:59:59');   // P2a last second of March
        $this->payment($i2, 100000, '2026-04-01 00:00:00');   // P2b one second after
        $this->payment($i4, 400000, '2026-03-02 09:00:00');   // P4 pays a February invoice in March
        $this->payment($i8, 600000, '2026-03-06 09:00:00');   // P8 pays the soft-deleted I8
        $this->payment($i10, 600000, '2026-03-22 12:00:00');  // P10 customer soft-deleted
        $this->payment($i11, 150000, '2026-03-13 12:00:00');  // P11
        $this->payment($i12, 40000, '2026-03-15 12:00:00');   // P12
        $this->payment($i14, 50000, '2026-03-14 08:00:00');   // P14 pays the February invoice I14

        // P12r: 30000 recorded on I12 on 2026-03-16 and reversed on 2026-04-02 by another user: out of every figure.
        Carbon::setTestNow('2026-03-16 10:00:00');
        $this->actingAs($this->admin);
        $reversed = BillingService::recordPayment($i12->fresh(), 30000, 'transfer', null, 'recorded then reversed');
        Carbon::setTestNow('2026-04-02 09:00:00');
        $this->actingAs($this->dewi);
        BillingService::reversePayment($reversed, 'Customer disputed the transfer');
        Carbon::setTestNow();

        $i8->delete();
        $c6->delete();

        // Company B: figures that would show up in company A's report if tenancy leaked.
        $cb1 = $this->customer($this->bayu, '2026-03-10 09:00:00', $this->companyB);
        $this->subscription($cb1, 'active', 777777, '2026-03-05');
        $ib1 = $this->invoice($cb1, 'paid', 5000000, 5000000, '2026-03-10');
        $this->payment($ib1, 5000000, '2026-03-11 09:00:00');
    }

    private function customer(?User $acquirer, string $createdAt, ?Company $company = null): Customer
    {
        return $this->customerAcquiredBy($acquirer, ['created_at' => $createdAt], $company);
    }

    private function subscription(Customer $customer, string $status, int $mrc, ?string $activationDate): ServiceSubscription
    {
        return ServiceSubscription::factory()->create([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'service_package_id' => $this->packageId(),
            'installation_address_id' => $this->addressIds[$customer->id] ??= CustomerAddress::factory()
                ->create(['customer_id' => $customer->id])->id,
            'status' => $status,
            'mrc_amount' => $mrc,
            'activation_date' => $activationDate,
        ]);
    }

    private function invoice(Customer $customer, string $status, int $total, int $paid, string $issueDate): Invoice
    {
        return InvoiceFactory::new()->create([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'created_by' => $this->actorOf((int) $customer->company_id)->id,
            'type' => 'one_time',
            'status' => $status,
            'issue_date' => $issueDate,
            'due_date' => Carbon::parse($issueDate)->addDays(14)->toDateString(),
            'total' => $total,
            'paid_amount' => $paid,
        ]);
    }

    private function payment(Invoice $invoice, int $amount, string $paidAt): Payment
    {
        return Payment::query()->create([
            'company_id' => $invoice->company_id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'method' => 'transfer',
            'paid_at' => $paidAt,
            'received_by' => $this->actorOf((int) $invoice->company_id)->id,
        ]);
    }

    private function actorOf(int $companyId): User
    {
        return $companyId === (int) $this->companyB->id ? $this->bayu : $this->admin;
    }

    private function packageId(): int
    {
        // One shared package: the package factory builds its profiles for the first company.
        return $this->packageId ??= ServicePackage::factory()->create()->id;
    }

    // ---------------------------------------------------------------------------------------------
    // Assertions
    // ---------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function report(
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $acquirerId = null,
        string $sortBy = 'name',
        string $sortDir = 'asc',
    ): array {
        return SalesKpiQuery::execute((int) $this->company->id, $dateFrom, $dateTo, $acquirerId, $sortBy, $sortDir);
    }

    /**
     * @param  array<string, mixed>  $expected  rows, unassigned and totals, key order irrelevant
     * @param  array<string, mixed>  $actual
     */
    private function assertReport(array $expected, array $actual, string $scenario): void
    {
        $this->assertMetricsAreV1();
        $this->assertSame(
            $this->normalized($expected),
            $this->normalized($actual),
            "sales {$scenario}",
        );
    }

    /**
     * Strict on values, on null ("no rate" is never 0) and on string/int types, blind to key order.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function normalized(array $report): array
    {
        return [
            'rows' => array_map($this->sorted(...), $report['rows']),
            'unassigned' => $report['unassigned'] === null ? null : $this->sorted($report['unassigned']),
            'totals' => $this->sorted($report['totals']),
        ];
    }

    /**
     * @param  array<string, mixed>  $figures
     * @return array<string, mixed>
     */
    private function sorted(array $figures): array
    {
        ksort($figures);

        return $figures;
    }

    /** @return array<string, mixed> */
    private function figures(int $customers, int $subscriptions, string $mrr, string $invoiced, string $collected, string $outstanding, ?string $rate): array
    {
        return array_combine(self::METRICS, [$customers, $subscriptions, $mrr, $invoiced, $collected, $outstanding, $rate]);
    }

    /** @return array<string, mixed> */
    private function row(User $acquirer, bool $active, int $customers, int $subscriptions, string $mrr, string $invoiced, string $collected, string $outstanding, ?string $rate): array
    {
        return [
            'id' => $acquirer->public_id,
            'name' => $acquirer->name,
            'is_active' => $active,
            ...$this->figures($customers, $subscriptions, $mrr, $invoiced, $collected, $outstanding, $rate),
        ];
    }

    /** Ties this suite to the formula version: a bumped metric fails here before any number is compared. */
    private function assertMetricsAreV1(): void
    {
        $versions = [];
        foreach (MetricRegistry::forReport(ReportType::Sales) as $metric) {
            $versions[$metric->key] = $metric->version;
        }

        foreach (self::METRICS as $key) {
            $this->assertArrayHasKey($key, $versions, "sales.{$key} is not a registered metric");
            $this->assertSame('v1', $versions[$key], "sales.{$key} left v1: characterize the new formula and update SalesKpiFormulaV1Test");
        }
    }
}
