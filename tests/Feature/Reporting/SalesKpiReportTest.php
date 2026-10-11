<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Service\Models\ServicePackage;
use Tests\TestCase;
use Tests\Traits\BuildsCustomerAcquirers;

/**
 * HTTP contract of the Sales KPI report: authorization, props, tenancy, sorting, paging and validation.
 * The exact formula figures live in SalesKpiFormulaV1Test.
 */
class SalesKpiReportTest extends TestCase
{
    use BuildsCustomerAcquirers;
    use RefreshDatabase;

    private const METRIC_KEYS = [
        'customers_acquired', 'subscriptions_activated', 'active_mrr', 'invoiced',
        'collected', 'outstanding', 'collection_rate',
    ];

    private const ROW_KEYS = ['id', 'name', 'is_active', ...self::METRIC_KEYS];

    private const ZERO_METRICS = [
        'customers_acquired' => 0,
        'subscriptions_activated' => 0,
        'active_mrr' => '0.00',
        'invoiced' => '0.00',
        'collected' => '0.00',
        'outstanding' => '0.00',
        'collection_rate' => null,
    ];

    /**
     * Twelve acquirers, created in this order. Columns: name, customers, collected.
     * Each one's first customer holds one invoice of 1000000 on which `collected` was paid, so
     * invoiced is a 12-way tie and collected, outstanding and collection_rate are all distinct.
     */
    private const ROSTER = [
        ['Lena', 3, 10000], ['Adi', 1, 80000], ['Kiki', 2, 30000], ['Bayu', 1, 100000],
        ['Jaya', 4, 50000], ['Cici', 2, 120000], ['Ines', 1, 70000], ['Dewi', 3, 20000],
        ['Hana', 2, 90000], ['Eko', 1, 40000], ['Gita', 4, 110000], ['Fajar', 2, 60000],
    ];

    private const ROSTER_BY_NAME = ['Adi', 'Bayu', 'Cici', 'Dewi', 'Eko', 'Fajar', 'Gita', 'Hana', 'Ines', 'Jaya', 'Kiki', 'Lena'];

    // 1 customer: Adi Bayu Eko Ines; 2: Cici Fajar Hana Kiki; 3: Dewi Lena; 4: Gita Jaya. Ties by name in both directions.
    private const ROSTER_BY_CUSTOMERS_ASC = ['Adi', 'Bayu', 'Eko', 'Ines', 'Cici', 'Fajar', 'Hana', 'Kiki', 'Dewi', 'Lena', 'Gita', 'Jaya'];

    private const ROSTER_BY_CUSTOMERS_DESC = ['Gita', 'Jaya', 'Dewi', 'Lena', 'Cici', 'Fajar', 'Hana', 'Kiki', 'Adi', 'Bayu', 'Eko', 'Ines'];

    // collected 10000 Lena, 20000 Dewi, 30000 Kiki, 40000 Eko, 50000 Jaya, 60000 Fajar, 70000 Ines,
    // 80000 Adi, 90000 Hana, 100000 Bayu, 110000 Gita, 120000 Cici.
    private const ROSTER_BY_COLLECTED_ASC = ['Lena', 'Dewi', 'Kiki', 'Eko', 'Jaya', 'Fajar', 'Ines', 'Adi', 'Hana', 'Bayu', 'Gita', 'Cici'];

    /**
     * Four acquirers whose eight sort keys give eight different orders.
     * Columns: customers, active subscription MRCs, invoiced, collected (one invoice on the first customer).
     * Outstanding is invoiced - collected: Pia 180000, Quin 900000, Ravi 740000, Sven 340000.
     * Collection rate: Pia 64.00, Quin 10.00, Ravi 17.78, Sven 15.00.
     */
    private const PROFILES = [
        'Pia' => [2, [200000, 100000, 100000], 500000, 320000],
        'Quin' => [4, [300000], 1000000, 100000],
        'Ravi' => [1, [50000, 50000, 50000, 50000], 900000, 160000],
        'Sven' => [3, [50000, 50000], 400000, 60000],
    ];

    /** Ascending order of each sort key over PROFILES (customers 2/4/1/3, subscriptions 3/1/4/2, MRR 400000/300000/200000/100000). */
    private const PROFILE_ORDER_ASC = [
        'name' => ['Pia', 'Quin', 'Ravi', 'Sven'],
        'customers_acquired' => ['Ravi', 'Pia', 'Sven', 'Quin'],
        'subscriptions_activated' => ['Quin', 'Sven', 'Pia', 'Ravi'],
        'active_mrr' => ['Sven', 'Ravi', 'Quin', 'Pia'],
        'invoiced' => ['Sven', 'Pia', 'Ravi', 'Quin'],
        'collected' => ['Sven', 'Quin', 'Ravi', 'Pia'],
        'outstanding' => ['Pia', 'Sven', 'Ravi', 'Quin'],
        'collection_rate' => ['Quin', 'Sven', 'Ravi', 'Pia'],
    ];

    private Company $otherCompany;

    /** Holds only report.sales.view, has no employee profile and no customers: never a row of its own. */
    private User $viewer;

    /** @var array<string, User> */
    private array $roster = [];

    private ?int $packageId = null;

    /** @var array<int, int> customer id => installation address id (a customer may only hold one address per label) */
    private array $addressIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndCompany();
        $this->otherCompany = Company::factory()->create(['is_active' => true]);
        $this->viewer = $this->employeeWithPermissions(['report.sales.view'], profile: false);
    }

    // (a) authorization

    public function test_admin_and_manager_open_the_sales_report(): void
    {
        foreach (['admin', 'manager'] as $role) {
            $this->visit(actor: $this->employee($role))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Admin/Reports/Sales'));
        }
    }

    public function test_staff_technician_and_customer_cannot_open_the_sales_report(): void
    {
        foreach (['staff', 'technician', 'customer'] as $role) {
            $this->visit(actor: $this->employee($role))->assertForbidden();
        }
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.reports.sales'))->assertRedirect(route('login'));
    }

    public function test_sales_capability_alone_opens_the_page_but_not_the_hub(): void
    {
        $this->visit()
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Reports/Sales'));

        $this->get(route('admin.reports.index'))->assertForbidden();
    }

    public function test_hub_capability_alone_cannot_open_the_sales_report(): void
    {
        $this->visit(actor: $this->employeeWithPermissions(['report.view']))->assertForbidden();
    }

    // (b) props shape

    public function test_page_exposes_component_meta_and_default_filters(): void
    {
        $this->visit()
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Reports/Sales')
                ->where('meta.report', 'sales')
                ->where('meta.formula_version', 'sales.v1')
                ->where('filters', [
                    'date_from' => null,
                    'date_to' => null,
                    'acquirer_id' => null,
                    'sort_by' => 'name',
                    'sort_dir' => 'asc',
                    'per_page' => 10,
                ]));
    }

    public function test_rows_are_a_paginator_and_every_object_carries_exactly_the_documented_keys(): void
    {
        $this->bill($this->customerAcquiredBy($this->acquirer('Ayu Seller')), 500000, 200000);

        $data = $this->props()['data'];

        $this->assertEqualsCanonicalizing(['data', 'links', 'meta'], array_keys($data['rows']));
        foreach (['current_page', 'last_page', 'per_page', 'total', 'from', 'to', 'path'] as $key) {
            $this->assertArrayHasKey($key, $data['rows']['meta']);
        }
        $this->assertCount(1, $data['rows']['data']);
        $this->assertEqualsCanonicalizing(self::ROW_KEYS, array_keys($data['rows']['data'][0]));
        $this->assertEqualsCanonicalizing(self::METRIC_KEYS, array_keys($data['unassigned']));
        $this->assertEqualsCanonicalizing(self::METRIC_KEYS, array_keys($data['totals']));
    }

    public function test_money_is_a_two_decimal_string_and_counts_are_integers(): void
    {
        // Customer: one active subscription of 150000 activated 2026-01-10, one sent invoice of 500000
        // issued 2026-02-01 with 200000 paid on 2026-02-05.
        $ayu = $this->acquirer('Ayu Seller');
        $customer = $this->customerAcquiredBy($ayu);
        $this->subscription($customer, 150000);
        $this->bill($customer, 500000, 200000, '2026-02-01', '2026-02-05 10:00:00');

        $data = $this->props()['data'];
        $expected = [
            'customers_acquired' => 1,
            'subscriptions_activated' => 1,
            'active_mrr' => '150000.00',
            'invoiced' => '500000.00',
            'collected' => '200000.00',
            'outstanding' => '300000.00',
            'collection_rate' => '40.00',
        ];

        $this->assertSameIgnoringKeyOrder(
            ['id' => $ayu->id, 'name' => 'Ayu Seller', 'is_active' => true, ...$expected],
            $data['rows']['data'][0],
        );
        $this->assertSameIgnoringKeyOrder($expected, $data['totals']);
        $this->assertSameIgnoringKeyOrder(self::ZERO_METRICS, $data['unassigned']);
    }

    public function test_acquirer_options_are_sorted_by_name_with_the_active_flag(): void
    {
        $zed = $this->acquirer('Zed Seller');
        $amy = $this->acquirer('Amy Seller');
        $mia = $this->employee('staff', attributes: ['name' => 'Mia Former', 'is_active' => false]);
        $this->customerAcquiredBy($mia);
        $this->employee('technician');

        $options = array_map(
            fn (array $option): array => [$option['id'], $option['name'], $option['is_active']],
            $this->props()['acquirers'],
        );

        $this->assertSame([
            [$amy->id, 'Amy Seller', true],
            [$mia->id, 'Mia Former', false],
            [$zed->id, 'Zed Seller', true],
        ], $options);
    }

    public function test_eligible_acquirer_without_customers_is_listed_with_zeros_and_a_null_rate(): void
    {
        $idle = $this->acquirer('Idle Seller');

        $rows = $this->props()['data']['rows']['data'];

        $this->assertCount(1, $rows);
        $this->assertSameIgnoringKeyOrder(
            ['id' => $idle->id, 'name' => 'Idle Seller', 'is_active' => true, ...self::ZERO_METRICS],
            $rows[0],
        );
    }

    public function test_former_employee_credited_on_a_customer_is_listed_as_inactive(): void
    {
        $former = $this->employee('staff', attributes: ['name' => 'Mia Former', 'is_active' => false]);
        $this->customerAcquiredBy($former);

        $rows = $this->props()['data']['rows']['data'];

        $this->assertCount(1, $rows);
        $this->assertSame($former->id, $rows[0]['id']);
        $this->assertFalse($rows[0]['is_active']);
        $this->assertSame(1, $rows[0]['customers_acquired']);
    }

    public function test_user_without_customer_create_is_not_listed(): void
    {
        $seller = $this->acquirer('Ayu Seller');
        $this->employee('technician');

        $props = $this->props();

        $this->assertSame([$seller->id], $this->rowIds($props));
        $this->assertSame([$seller->id], array_column($props['acquirers'], 'id'));
    }

    // (c) tenancy

    public function test_response_lists_only_the_acquirers_customers_and_money_of_the_company(): void
    {
        $ids = $this->seedTwoCompanies();

        $response = $this->visit()->assertOk();
        $props = $response->inertiaProps();

        $this->assertSame([$ids['ayu'], $ids['bima']], $this->rowIds($props));
        $this->assertSame([$ids['ayu'], $ids['bima']], array_column($props['acquirers'], 'id'));
        // Ayu 2 customers + Bima 1 + 1 unassigned; invoiced 1000000 + 500000 + 250000; collected 400000 + 500000;
        // outstanding 600000 + 0 + 250000.
        $this->assertSame(4, $props['data']['totals']['customers_acquired']);
        $this->assertSame('1750000.00', $props['data']['totals']['invoiced']);
        $this->assertSame('900000.00', $props['data']['totals']['collected']);
        $this->assertSame('850000.00', $props['data']['totals']['outstanding']);
        $this->assertSame(1, $props['data']['unassigned']['customers_acquired']);
        $this->assertSame('250000.00', $props['data']['unassigned']['invoiced']);

        $json = json_encode($props);
        $this->assertStringNotContainsString('Zed Foreign', $json);
        $this->assertStringNotContainsString('9000000.00', $json);
    }

    public function test_the_other_company_sees_only_its_own_figures(): void
    {
        $ids = $this->seedTwoCompanies();
        $foreignViewer = $this->employeeWithPermissions(['report.sales.view'], $this->otherCompany, profile: false);

        $props = $this->props(actor: $foreignViewer);

        $this->assertSame([$ids['zed']], $this->rowIds($props));
        $this->assertSame([$ids['zed']], array_column($props['acquirers'], 'id'));
        // Zed 1 customer + 1 unassigned; invoiced 9000000 + 3000000; collected 7000000; outstanding 2000000 + 3000000.
        $this->assertSame(2, $props['data']['totals']['customers_acquired']);
        $this->assertSame('12000000.00', $props['data']['totals']['invoiced']);
        $this->assertSame('7000000.00', $props['data']['totals']['collected']);
        $this->assertSame('5000000.00', $props['data']['totals']['outstanding']);
        $this->assertStringNotContainsString('Ayu Seller', json_encode($props));
    }

    public function test_acquirer_of_another_company_is_forbidden(): void
    {
        $ids = $this->seedTwoCompanies();

        $this->visit(['acquirer_id' => $ids['zed']])->assertForbidden();
    }

    public function test_user_that_is_neither_eligible_nor_credited_is_forbidden(): void
    {
        $technician = $this->employee('technician');
        $this->acquirer('Ayu Seller');

        foreach ([$technician->id, $this->viewer->id] as $userId) {
            $this->visit(['acquirer_id' => $userId])->assertForbidden();
        }
    }

    public function test_zero_and_unknown_acquirer_ids_are_forbidden(): void
    {
        $this->acquirer('Ayu Seller');

        foreach ([0, 999999] as $acquirerId) {
            $this->visit(['acquirer_id' => $acquirerId])->assertForbidden();
        }
    }

    public function test_non_integer_acquirer_id_is_a_validation_error(): void
    {
        $this->actingAs($this->viewer)
            ->getJson(route('admin.reports.sales', ['acquirer_id' => 'abc']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['acquirer_id']);
    }

    public function test_valid_acquirer_filters_to_exactly_that_row_and_keeps_every_option(): void
    {
        $ids = $this->seedTwoCompanies();

        $props = $this->props(['acquirer_id' => $ids['ayu']]);

        $this->assertSame([$ids['ayu']], $this->rowIds($props));
        $this->assertSame(1, $props['data']['rows']['meta']['total']);
        $this->assertNull($props['data']['unassigned']);
        $this->assertSameIgnoringKeyOrder($this->metricsOf($props['data']['rows']['data'][0]), $props['data']['totals']);
        // Ayu: 2 customers, invoiced 1000000, collected 400000.
        $this->assertSame(2, $props['data']['totals']['customers_acquired']);
        $this->assertSame('1000000.00', $props['data']['totals']['invoiced']);
        $this->assertSame('400000.00', $props['data']['totals']['collected']);
        $this->assertSame([$ids['ayu'], $ids['bima']], array_column($props['acquirers'], 'id'));
        $this->assertSame((string) $ids['ayu'], $props['filters']['acquirer_id']);
    }

    // (d) sorting and paging

    public function test_first_page_holds_ten_rows_and_the_second_page_the_rest(): void
    {
        $this->seedRoster();

        $first = $this->props();
        $second = $this->props(['page' => 2]);

        $this->assertSame($this->idsOf(array_slice(self::ROSTER_BY_NAME, 0, 10)), $this->rowIds($first));
        $this->assertSame($this->idsOf(['Kiki', 'Lena']), $this->rowIds($second));
        $this->assertSame(12, $first['data']['rows']['meta']['total']);
        $this->assertSame(12, $second['data']['rows']['meta']['total']);
        $this->assertSame(2, $first['data']['rows']['meta']['last_page']);
        $this->assertSame(2, $second['data']['rows']['meta']['current_page']);
    }

    public function test_per_page_25_returns_every_row_on_one_page(): void
    {
        $this->seedRoster();

        $props = $this->props(['per_page' => 25]);

        $this->assertCount(12, $props['data']['rows']['data']);
        $this->assertSame(1, $props['data']['rows']['meta']['last_page']);
        $this->assertSame(25, $props['data']['rows']['meta']['per_page']);
        $this->assertSame(25, $props['filters']['per_page']);
    }

    public function test_unsupported_per_page_falls_back_to_ten(): void
    {
        $this->seedRoster();

        $props = $this->props(['per_page' => 7]);

        $this->assertCount(10, $props['data']['rows']['data']);
        $this->assertSame(10, $props['data']['rows']['meta']['per_page']);
        $this->assertSame(10, $props['filters']['per_page']);
    }

    public function test_totals_cover_the_whole_dataset_on_every_page(): void
    {
        $this->seedRoster();

        $first = $this->props();
        $second = $this->props(['page' => 2]);

        $this->assertSame($first['data']['totals'], $second['data']['totals']);
        $this->assertSame($first['data']['unassigned'], $second['data']['unassigned']);

        // Customers 26 acquirer customers + 1 unassigned; invoiced 12 x 1000000 + 250000; collected 780000 + 100000;
        // outstanding 11220000 + 150000.
        $totals = $first['data']['totals'];
        $this->assertSame(27, $totals['customers_acquired']);
        $this->assertSame('12250000.00', $totals['invoiced']);
        $this->assertSame('880000.00', $totals['collected']);
        $this->assertSame('11370000.00', $totals['outstanding']);

        $rows = [...$first['data']['rows']['data'], ...$second['data']['rows']['data'], $first['data']['unassigned']];
        $this->assertSame($totals['customers_acquired'], array_sum(array_column($rows, 'customers_acquired')));
        foreach (['active_mrr', 'invoiced', 'collected', 'outstanding'] as $money) {
            $this->assertSame($totals[$money], array_reduce(
                array_column($rows, $money),
                fn (string $sum, string $value): string => bcadd($sum, $value, 2),
                '0.00',
            ), $money);
        }
    }

    public function test_name_sort_orders_rows_both_ways(): void
    {
        $this->seedRoster();

        $this->assertSame($this->idsOf(self::ROSTER_BY_NAME), $this->rowIds($this->props(['per_page' => 25])));
        $this->assertSame(
            $this->idsOf(self::ROSTER_BY_NAME),
            $this->rowIds($this->props(['per_page' => 25, 'sort' => 'name', 'direction' => 'asc'])),
        );
        $this->assertSame(
            $this->idsOf(array_reverse(self::ROSTER_BY_NAME)),
            $this->rowIds($this->props(['per_page' => 25, 'sort' => 'name', 'direction' => 'desc'])),
        );
    }

    public function test_customers_acquired_sort_orders_rows_both_ways(): void
    {
        $this->seedRoster();

        $this->assertSame(
            $this->idsOf(self::ROSTER_BY_CUSTOMERS_ASC),
            $this->rowIds($this->props(['per_page' => 25, 'sort' => 'customers_acquired', 'direction' => 'asc'])),
        );
        $this->assertSame(
            $this->idsOf(self::ROSTER_BY_CUSTOMERS_DESC),
            $this->rowIds($this->props(['per_page' => 25, 'sort' => 'customers_acquired', 'direction' => 'desc'])),
        );
    }

    public function test_collected_sort_orders_rows_both_ways(): void
    {
        $this->seedRoster();

        $this->assertSame(
            $this->idsOf(self::ROSTER_BY_COLLECTED_ASC),
            $this->rowIds($this->props(['per_page' => 25, 'sort' => 'collected', 'direction' => 'asc'])),
        );
        $this->assertSame(
            $this->idsOf(array_reverse(self::ROSTER_BY_COLLECTED_ASC)),
            $this->rowIds($this->props(['per_page' => 25, 'sort' => 'collected', 'direction' => 'desc'])),
        );
    }

    public function test_ties_break_by_name_in_both_directions(): void
    {
        $this->seedRoster();

        foreach (['asc', 'desc'] as $direction) {
            $this->assertSame(
                $this->idsOf(self::ROSTER_BY_NAME),
                $this->rowIds($this->props(['per_page' => 25, 'sort' => 'invoiced', 'direction' => $direction])),
                "invoiced {$direction}",
            );
        }
    }

    public function test_equal_names_break_by_id(): void
    {
        $first = $this->acquirer('Twin Seller');
        $second = $this->acquirer('Twin Seller');
        $other = $this->acquirer('Aaron Seller');

        $this->assertSame([$other->id, $first->id, $second->id], $this->rowIds($this->props()));
    }

    public function test_names_sort_case_insensitively(): void
    {
        $this->acquirer('bravo');
        $this->acquirer('Alpha');
        $this->acquirer('charlie');

        $names = array_column($this->props()['data']['rows']['data'], 'name');

        $this->assertSame(['Alpha', 'bravo', 'charlie'], $names);
    }

    public function test_every_sort_key_orders_rows_ascending(): void
    {
        $this->seedProfiles();

        foreach (self::PROFILE_ORDER_ASC as $sort => $names) {
            $this->assertSame(
                $names,
                $this->rowNames($this->props(['sort' => $sort, 'direction' => 'asc'])),
                "{$sort} asc",
            );
        }
    }

    public function test_every_sort_key_orders_rows_descending(): void
    {
        $this->seedProfiles();

        foreach (self::PROFILE_ORDER_ASC as $sort => $names) {
            $this->assertSame(
                array_reverse($names),
                $this->rowNames($this->props(['sort' => $sort, 'direction' => 'desc'])),
                "{$sort} desc",
            );
        }
    }

    public function test_sort_by_and_sort_dir_are_aliases_of_sort_and_direction(): void
    {
        $this->seedRoster();
        $expected = $this->idsOf(array_reverse(self::ROSTER_BY_COLLECTED_ASC));

        foreach ([
            ['sort_by' => 'collected', 'sort_dir' => 'desc'],
            ['sort' => 'collected', 'direction' => 'desc'],
        ] as $query) {
            $props = $this->props(['per_page' => 25, ...$query]);

            $this->assertSame($expected, $this->rowIds($props), json_encode($query));
            $this->assertSame('collected', $props['filters']['sort_by']);
            $this->assertSame('desc', $props['filters']['sort_dir']);
        }
    }

    public function test_null_collection_rate_sorts_last_in_both_directions(): void
    {
        // Alma collected 50000 of 100000 (50.00), Boni has no invoice (null), Cato collected 10000 of 100000 (10.00).
        $this->bill($this->customerAcquiredBy($this->acquirer('Alma')), 100000, 50000);
        $this->acquirer('Boni');
        $this->bill($this->customerAcquiredBy($this->acquirer('Cato')), 100000, 10000);

        $this->assertSame(
            ['Cato', 'Alma', 'Boni'],
            $this->rowNames($this->props(['sort' => 'collection_rate', 'direction' => 'asc'])),
        );
        $this->assertSame(
            ['Alma', 'Cato', 'Boni'],
            $this->rowNames($this->props(['sort' => 'collection_rate', 'direction' => 'desc'])),
        );
    }

    public function test_unknown_sort_key_is_a_validation_error(): void
    {
        $this->actingAs($this->viewer)
            ->getJson(route('admin.reports.sales', ['sort' => 'bogus']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort']);
    }

    public function test_unknown_direction_is_a_validation_error(): void
    {
        $this->actingAs($this->viewer)
            ->getJson(route('admin.reports.sales', ['direction' => 'sideways']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['direction']);
    }

    // (e) period validation

    public function test_invalid_date_from_is_a_validation_error(): void
    {
        $this->actingAs($this->viewer)
            ->getJson(route('admin.reports.sales', ['date_from' => 'not-a-date']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date_from']);
    }

    public function test_date_to_before_date_from_is_a_validation_error(): void
    {
        $this->actingAs($this->viewer)
            ->getJson(route('admin.reports.sales', ['date_from' => '2026-02-01', 'date_to' => '2026-01-01']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date_to']);
    }

    public function test_a_single_date_is_ignored_and_the_report_stays_all_time(): void
    {
        $this->customerAcquiredBy($this->acquirer('Ayu Seller'), ['created_at' => '2020-05-01 09:00:00']);
        $allTime = $this->figuresOf($this->props());

        foreach ([['date_from' => '2026-01-01'], ['date_to' => '2019-12-31']] as $query) {
            $props = $this->props($query);

            $this->assertSame($allTime, $this->figuresOf($props), json_encode($query));
            $this->assertSame($query['date_from'] ?? null, $props['filters']['date_from']);
            $this->assertSame($query['date_to'] ?? null, $props['filters']['date_to']);
        }
        $this->assertSame(1, $allTime['totals']['customers_acquired']);
    }

    public function test_both_dates_limit_the_period_and_are_echoed(): void
    {
        $this->customerAcquiredBy($this->acquirer('Ayu Seller'), ['created_at' => '2020-05-01 09:00:00']);

        $outside = $this->props(['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);
        $inside = $this->props(['date_from' => '2020-05-01', 'date_to' => '2020-05-31']);

        $this->assertSame(0, $outside['data']['totals']['customers_acquired']);
        $this->assertSame('2026-01-01', $outside['filters']['date_from']);
        $this->assertSame('2026-01-31', $outside['filters']['date_to']);
        $this->assertSame(1, $inside['data']['totals']['customers_acquired']);
    }

    // (f) query count

    public function test_query_count_does_not_grow_with_the_number_of_acquirers(): void
    {
        foreach (range(1, 2) as $number) {
            $this->acquirerWithBusiness(sprintf('Seller %02d', $number));
        }
        $this->props(); // warm the permission cache so both measurements start equal
        $withTwo = $this->queryCountOfRequest();

        foreach (range(3, 8) as $number) {
            $this->acquirerWithBusiness(sprintf('Seller %02d', $number));
        }
        $withEight = $this->queryCountOfRequest();

        $this->assertCount(8, $this->props()['data']['rows']['data']);
        $this->assertSame($withTwo, $withEight);
    }

    // (g) read only

    public function test_requests_write_nothing(): void
    {
        $ayu = $this->acquirerWithBusiness('Ayu Seller');
        $tables = ['customers', 'invoices', 'payments', 'activity_log'];
        $before = array_map(fn (string $table): int => DB::table($table)->count(), $tables);

        $this->props();
        $this->props(['acquirer_id' => $ayu->id, 'sort' => 'collected', 'direction' => 'desc', 'per_page' => 25]);
        $this->props(['date_from' => '2026-01-01', 'date_to' => '2026-12-31']);

        $this->assertSame($before, array_map(fn (string $table): int => DB::table($table)->count(), $tables));
    }

    // Fixtures

    private function acquirer(string $name, ?Company $company = null): User
    {
        return $this->employee('manager', $company, ['name' => $name]);
    }

    /**
     * Two customers, the first with one active subscription, one invoice and one payment.
     */
    private function acquirerWithBusiness(string $name): User
    {
        $acquirer = $this->acquirer($name);
        $first = $this->customerAcquiredBy($acquirer);
        $this->customerAcquiredBy($acquirer);
        $this->subscription($first, 100000);
        $this->bill($first, 300000, 100000);

        return $acquirer;
    }

    /**
     * Company A: Ayu (2 customers, one invoice 1000000 with 400000 paid), Bima (1 customer, one invoice 500000 fully paid),
     * 1 unassigned customer with an unpaid invoice of 250000. Other company: Zed (1 customer, invoice 9000000 with
     * 7000000 paid) and 1 unassigned customer with an unpaid invoice of 3000000.
     *
     * @return array{ayu: int, bima: int, zed: int}
     */
    private function seedTwoCompanies(): array
    {
        $ayu = $this->acquirer('Ayu Seller');
        $bima = $this->acquirer('Bima Seller');
        $zed = $this->acquirer('Zed Foreign', $this->otherCompany);

        $this->bill($this->customerAcquiredBy($ayu), 1000000, 400000);
        $this->customerAcquiredBy($ayu);
        $this->bill($this->customerAcquiredBy($bima), 500000, 500000);
        $this->bill($this->customerAcquiredBy(null), 250000, 0);

        $this->bill($this->customerAcquiredBy($zed, company: $this->otherCompany), 9000000, 7000000);
        $this->bill($this->customerAcquiredBy(null, company: $this->otherCompany), 3000000, 0);

        return ['ayu' => $ayu->id, 'bima' => $bima->id, 'zed' => $zed->id];
    }

    /** ROSTER plus one unassigned customer with an invoice of 250000 of which 100000 is paid. */
    private function seedRoster(): void
    {
        foreach (self::ROSTER as [$name, $customers, $collected]) {
            $acquirer = $this->acquirer($name);
            $this->roster[$name] = $acquirer;

            $this->bill($this->customerAcquiredBy($acquirer), 1000000, $collected);
            for ($extra = 1; $extra < $customers; $extra++) {
                $this->customerAcquiredBy($acquirer);
            }
        }

        $this->bill($this->customerAcquiredBy(null), 250000, 100000);
    }

    private function seedProfiles(): void
    {
        foreach (self::PROFILES as $name => [$customers, $mrcs, $invoiced, $collected]) {
            $acquirer = $this->acquirer($name);
            $first = $this->customerAcquiredBy($acquirer);
            foreach ($mrcs as $mrc) {
                $this->subscription($first, $mrc);
            }
            $this->bill($first, $invoiced, $collected);
            for ($extra = 1; $extra < $customers; $extra++) {
                $this->customerAcquiredBy($acquirer);
            }
        }
    }

    /**
     * A sent, partial or paid invoice of the customer and, when anything was paid, one payment of that amount.
     */
    private function bill(Customer $customer, int $total, int $paid, string $issueDate = '2026-03-10', string $paidAt = '2026-03-12 10:00:00'): Invoice
    {
        $invoice = Invoice::factory()->create([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'created_by' => $this->viewer->id,
            'status' => $paid === 0 ? 'sent' : ($paid < $total ? 'partial' : 'paid'),
            'issue_date' => $issueDate,
            'due_date' => Carbon::parse($issueDate)->addDays(14)->toDateString(),
            'total' => $total,
            'paid_amount' => $paid,
        ]);

        if ($paid > 0) {
            Payment::query()->create([
                'company_id' => $customer->company_id,
                'invoice_id' => $invoice->id,
                'amount' => $paid,
                'method' => 'transfer',
                'paid_at' => $paidAt,
                'received_by' => $this->viewer->id,
            ]);
        }

        return $invoice;
    }

    private function subscription(Customer $customer, int $mrc): ServiceSubscription
    {
        return ServiceSubscription::factory()->create([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'service_package_id' => $this->packageId(),
            'installation_address_id' => $this->addressIds[$customer->id] ??= CustomerAddress::factory()
                ->create(['customer_id' => $customer->id])->id,
            'status' => 'active',
            'activation_date' => '2026-01-10',
            'mrc_amount' => $mrc,
        ]);
    }

    /**
     * One package shared by every tenant: its factory builds its profiles for the first company only
     * (same reason as ReportCapabilityTest). Reports never read it.
     */
    private function packageId(): int
    {
        return $this->packageId ??= ServicePackage::factory()->create()->id;
    }

    // Requests and readers

    /**
     * @param  array<string, mixed>  $query
     */
    private function visit(array $query = [], ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->viewer)->get(route('admin.reports.sales', $query));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function props(array $query = [], ?User $actor = null): array
    {
        return $this->visit($query, $actor)->assertOk()->inertiaProps();
    }

    private function queryCountOfRequest(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->visit()->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @param  list<string>  $names  roster names, in the order they should appear
     * @return list<int>
     */
    private function idsOf(array $names): array
    {
        return array_map(fn (string $name): int => $this->roster[$name]->id, $names);
    }

    /**
     * @param  array<string, mixed>  $props
     * @return list<int>
     */
    private function rowIds(array $props): array
    {
        return array_column($props['data']['rows']['data'], 'id');
    }

    /**
     * @param  array<string, mixed>  $props
     * @return list<string>
     */
    private function rowNames(array $props): array
    {
        return array_column($props['data']['rows']['data'], 'name');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function metricsOf(array $row): array
    {
        return array_diff_key($row, array_flip(['id', 'name', 'is_active']));
    }

    /**
     * Everything the period can change, without the paginator links (they echo the query string).
     *
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private function figuresOf(array $props): array
    {
        return [
            'rows' => $props['data']['rows']['data'],
            'unassigned' => $props['data']['unassigned'],
            'totals' => $props['data']['totals'],
        ];
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function assertSameIgnoringKeyOrder(array $expected, array $actual): void
    {
        ksort($expected);
        ksort($actual);

        $this->assertSame($expected, $actual);
    }
}
