<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\BuildsCustomerAcquirers;
use Tests\Traits\WithoutAppOnlyRedirect;

class CustomerSalesAttributionVisibilityTest extends TestCase
{
    use BuildsCustomerAcquirers;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private Company $otherCompany;

    private User $manager;

    private User $staff;

    private User $adminWithoutProfile;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndCompany();
        $this->otherCompany = Company::factory()->create(['is_active' => true]);
        $this->manager = $this->employee('manager', attributes: ['name' => 'Manny Manager']);
        $this->staff = $this->employee('staff', attributes: ['name' => 'Stacy Staff']);
        $this->adminWithoutProfile = $this->employee('admin', profile: false);
        $this->technician = $this->employee('technician');
    }

    public function test_seeded_roles_get_the_documented_attribution_capabilities_on_every_customer_page(): void
    {
        $customer = $this->customerAcquiredBy($this->staff);

        // role => [view, assign, correct]
        $expected = [
            'admin' => [$this->adminWithoutProfile, true, true, true],
            'manager' => [$this->manager, true, true, true],
            'staff' => [$this->staff, true, true, false],
        ];

        foreach ($expected as $role => [$user, $view, $assign, $correct]) {
            $edit = $this->props($user, 'admin.customers.edit', ['customer' => $customer]);
            $this->assertSame($view, $edit['can']['viewAcquirer'], "{$role} edit viewAcquirer");
            $this->assertSame($assign, $edit['can']['assignAcquirer'], "{$role} edit assignAcquirer");
            $this->assertSame($correct, $edit['can']['correctAcquirer'], "{$role} edit correctAcquirer");
            $this->assertSame($view, $this->props($user, 'admin.customers.show', ['customer' => $customer])['can']['viewAcquirer'], "{$role} show");
            $this->assertSame($view, $this->props($user, 'admin.customers.index')['can']['viewAcquirer'], "{$role} index");
        }

        foreach (['admin' => $this->adminWithoutProfile, 'manager' => $this->manager] as $role => $user) {
            $this->assertTrue($this->props($user, 'admin.customers.create')['can']['assignAcquirer'], "{$role} create");
        }
        $this->actingAs($this->staff)->get(route('admin.customers.create'))->assertForbidden();

        $this->assertFalse($this->props($this->technician, 'admin.customers.index')['can']['viewAcquirer']);
        $this->assertFalse($this->props($this->technician, 'admin.customers.show', ['customer' => $customer])['can']['viewAcquirer']);
        $this->actingAs($this->technician)->get(route('admin.customers.edit', $customer))->assertForbidden();
    }

    public function test_correct_only_role_can_see_attribution_but_not_assign(): void
    {
        $corrector = $this->employeeWithPermissions(['customer.view', 'customer.update', 'customer.sales.correct']);
        $customer = $this->customerAcquiredBy($this->staff);

        $edit = $this->props($corrector, 'admin.customers.edit', ['customer' => $customer]);

        $this->assertTrue($edit['can']['viewAcquirer']);
        $this->assertFalse($edit['can']['assignAcquirer']);
        $this->assertTrue($edit['can']['correctAcquirer']);
        $this->assertSame($this->staff->public_id, $edit['customer']['data']['acquirer']['id']);
    }

    public function test_attribution_is_hidden_from_viewers_without_a_sales_capability_and_the_filter_cannot_probe_it(): void
    {
        $viewerOnly = $this->employeeWithPermissions(['customer.view']);
        $acquired = $this->customerAcquiredBy($this->staff);
        $this->customerAcquiredBy(null);

        foreach ([$this->technician, $viewerOnly] as $viewer) {
            $index = $this->props($viewer, 'admin.customers.index', ['acquired_by' => 'unassigned']);

            $this->assertCount(2, $index['customers']['data'], 'The acquired_by filter must be ignored, otherwise it leaks attribution.');
            foreach ($index['customers']['data'] as $row) {
                $this->assertArrayNotHasKey('acquirer', $row);
                $this->assertArrayNotHasKey('acquired_by_user_id', $row);
            }
            $this->assertSame([], $index['acquirerOptions']);
            $this->assertArrayNotHasKey('acquired_by', $index['filters']);

            $show = $this->props($viewer, 'admin.customers.show', ['customer' => $acquired])['customer']['data'];
            $this->assertArrayNotHasKey('acquirer', $show);
            $this->assertArrayNotHasKey('acquired_by_user_id', $show);
        }
    }

    public function test_visible_viewers_get_the_acquirer_summary_on_index_and_show(): void
    {
        $acquired = $this->customerAcquiredBy($this->staff, ['code' => 'CUS-A']);
        $this->customerAcquiredBy(null, ['code' => 'CUS-B']);

        $rows = collect($this->props($this->manager, 'admin.customers.index')['customers']['data'])->keyBy('code');

        $this->assertSame($this->staff->public_id, $rows['CUS-A']['acquired_by_user_id']);
        $this->assertSame(
            ['id' => $this->staff->public_id, 'name' => $this->staff->name, 'is_active' => true],
            $rows['CUS-A']['acquirer'],
        );
        $this->assertArrayHasKey('acquirer', $rows['CUS-B']);
        $this->assertNull($rows['CUS-B']['acquirer']);
        $this->assertNull($rows['CUS-B']['acquired_by_user_id']);

        $show = $this->props($this->manager, 'admin.customers.show', ['customer' => $acquired])['customer']['data'];
        $this->assertSame($this->staff->public_id, $show['acquirer']['id']);
    }

    public function test_an_inactive_historical_acquirer_shows_by_name_but_is_never_offered_as_a_choice(): void
    {
        $former = $this->employee('staff', attributes: ['name' => 'Former Seller', 'is_active' => false]);
        $customer = $this->customerAcquiredBy($former);

        $edit = $this->props($this->manager, 'admin.customers.edit', ['customer' => $customer]);
        $this->assertSame(['id' => $former->public_id, 'name' => 'Former Seller', 'is_active' => false], $edit['customer']['data']['acquirer']);
        $this->assertSame($former->public_id, $edit['customer']['data']['acquired_by_user_id']);
        $this->assertNotContains($former->public_id, array_column($edit['acquirerOptions'], 'id'));

        $this->assertNotContains($former->public_id, array_column($this->props($this->manager, 'admin.customers.create')['acquirerOptions'], 'id'));

        $filterOptions = collect($this->props($this->manager, 'admin.customers.index')['acquirerOptions'])->keyBy('id');
        $this->assertFalse($filterOptions[$former->public_id]['is_active'], 'The index filter still finds former acquirers.');
    }

    public function test_an_acquirer_reference_to_another_company_renders_as_unassigned(): void
    {
        $foreign = $this->employee('manager', $this->otherCompany);
        $customer = $this->customerAcquiredBy(null);
        DB::table('customers')->where('id', $customer->id)->update(['acquired_by_user_id' => $foreign->id]);

        $show = $this->props($this->manager, 'admin.customers.show', ['customer' => $customer])['customer']['data'];
        $this->assertNull($show['acquirer']);

        $this->actingAs($this->manager);
        $this->assertNull(Customer::query()->with('acquirer')->findOrFail($customer->id)->acquirer);
        $this->assertSame(
            $this->staff->id,
            $this->customerAcquiredBy($this->staff)->fresh()->acquirer?->id,
        );

        $filterIds = array_column($this->props($this->manager, 'admin.customers.index')['acquirerOptions'], 'id');
        $this->assertNotContains($foreign->public_id, $filterIds);
    }

    public function test_create_and_edit_offer_only_eligible_employees_and_default_to_the_creator(): void
    {
        $this->employee('manager', attributes: ['is_active' => false]);
        $this->employee('manager', $this->otherCompany);
        $this->employee('customer');
        $customer = $this->customerAcquiredBy(null);
        $eligible = [$this->manager->public_id, $this->staff->public_id];

        $create = $this->props($this->manager, 'admin.customers.create');
        $this->assertEqualsCanonicalizing($eligible, array_column($create['acquirerOptions'], 'id'));
        $this->assertSame($this->manager->public_id, $create['acquirerDefaultId']);

        $createAsAdmin = $this->props($this->adminWithoutProfile, 'admin.customers.create');
        $this->assertEqualsCanonicalizing($eligible, array_column($createAsAdmin['acquirerOptions'], 'id'));
        $this->assertNull($createAsAdmin['acquirerDefaultId'], 'An admin without an employee profile defaults to Unassigned.');

        $edit = $this->props($this->staff, 'admin.customers.edit', ['customer' => $customer]);
        $this->assertEqualsCanonicalizing($eligible, array_column($edit['acquirerOptions'], 'id'));
        $this->assertSame(['id', 'name', 'is_active', 'employee_number'], array_keys($edit['acquirerOptions'][0]));
    }

    public function test_create_and_edit_expose_no_options_to_users_without_a_sales_capability(): void
    {
        $editor = $this->employeeWithPermissions(['customer.view', 'customer.update']);
        $onboarder = $this->employeeWithPermissions(['customer.view', 'customer.create', 'customer.address.manage', 'customer.subscription.manage', 'spk.create']);
        $customer = $this->customerAcquiredBy($this->staff);

        $edit = $this->props($editor, 'admin.customers.edit', ['customer' => $customer]);
        $this->assertSame([], $edit['acquirerOptions']);
        $this->assertFalse($edit['can']['viewAcquirer']);
        $this->assertArrayNotHasKey('acquirer', $edit['customer']['data']);

        $create = $this->props($onboarder, 'admin.customers.create');
        $this->assertSame([], $create['acquirerOptions']);
        $this->assertNull($create['acquirerDefaultId']);
        $this->assertFalse($create['can']['assignAcquirer']);
    }

    public function test_index_filters_by_acquirer_and_unassigned_within_the_company(): void
    {
        $foreign = $this->employee('manager', $this->otherCompany);
        $this->customerAcquiredBy($this->staff, ['code' => 'CUS-A']);
        $this->customerAcquiredBy($this->manager, ['code' => 'CUS-B']);
        $this->customerAcquiredBy(null, ['code' => 'CUS-C']);
        $this->customerAcquiredBy($foreign, ['code' => 'CUS-F'], $this->otherCompany);

        $codes = fn (array $query): array => collect($this->props($this->manager, 'admin.customers.index', $query)['customers']['data'])->pluck('code')->all();

        $this->assertSame(['CUS-A'], $codes(['acquired_by' => $this->staff->public_id]));
        $this->assertSame(['CUS-C'], $codes(['acquired_by' => 'unassigned']));
        $this->assertSame([], $codes(['acquired_by' => $foreign->public_id]));
        $this->assertSame(['CUS-A', 'CUS-B', 'CUS-C'], $codes(['acquired_by' => 'not-an-id']));
        $this->assertSame(['CUS-A', 'CUS-B', 'CUS-C'], $codes(['acquired_by' => ['x']]));
        $this->assertSame(
            'unassigned',
            $this->props($this->manager, 'admin.customers.index', ['acquired_by' => 'unassigned'])['filters']['acquired_by'],
        );
    }

    public function test_export_adds_the_acquired_by_column_only_for_visible_viewers_and_honors_the_filter(): void
    {
        $this->customerAcquiredBy($this->staff, ['code' => 'CUS-A', 'name' => 'Acquired Customer', 'type' => 'Individual']);
        $this->customerAcquiredBy(null, ['code' => 'CUS-B', 'name' => 'Open Customer']);
        $exportOnly = $this->employeeWithPermissions(['customer.view', 'customer.export']);

        $csv = $this->exportCsv($this->manager);
        $this->assertStringContainsString('Diperoleh oleh', $csv);
        $this->assertStringContainsString('Kode', $csv);
        $this->assertStringContainsString('Perorangan', $csv);
        $this->assertStringContainsString($this->staff->name, $csv);
        $this->assertStringContainsString('Belum ditentukan', $csv);

        $unassigned = $this->exportCsv($this->manager, ['acquired_by' => 'unassigned']);
        $this->assertStringContainsString('CUS-B', $unassigned);
        $this->assertStringNotContainsString('CUS-A', $unassigned);

        $byId = $this->exportCsv($this->manager, ['acquired_by' => $this->staff->public_id]);
        $this->assertStringContainsString('CUS-A', $byId);
        $this->assertStringNotContainsString('CUS-B', $byId);

        $hidden = $this->exportCsv($exportOnly, ['acquired_by' => 'unassigned']);
        $this->assertStringNotContainsString('Diperoleh oleh', $hidden);
        $this->assertStringNotContainsString($this->staff->name, $hidden);
        $this->assertStringContainsString('CUS-A', $hidden, 'The filter is ignored for viewers without attribution access.');

        $this->actingAs($this->manager)
            ->get(route('admin.customers.export', ['format' => 'pdf']))
            ->assertOk();
    }

    public function test_index_and_export_query_counts_do_not_grow_with_the_number_of_acquired_customers(): void
    {
        $sellers = collect(range(1, 6))->map(fn (): User => $this->employee('staff'));
        $this->customerAcquiredBy($sellers[0]);
        $this->customerAcquiredBy($sellers[1]);

        $this->props($this->manager, 'admin.customers.index');
        $this->exportCsv($this->manager);

        $indexBefore = $this->queryCount(fn () => $this->props($this->manager, 'admin.customers.index'));
        $exportBefore = $this->queryCount(fn () => $this->exportCsv($this->manager));

        foreach ([2, 3, 4, 5] as $position) {
            $this->customerAcquiredBy($sellers[$position]);
        }

        $this->assertSame($indexBefore, $this->queryCount(fn () => $this->props($this->manager, 'admin.customers.index')));
        $this->assertSame($exportBefore, $this->queryCount(fn () => $this->exportCsv($this->manager)));
    }

    // helpers

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function props(User $user, string $routeName, array $parameters = []): array
    {
        $version = app(HandleInertiaRequests::class)->version(Request::create('/'));
        $headers = array_filter([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => is_string($version) ? $version : '',
        ], static fn (string $value): bool => $value !== '');

        return $this->actingAs($user)
            ->get(route($routeName, $parameters), $headers)
            ->assertOk()
            ->json('props');
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function exportCsv(User $user, array $query = []): string
    {
        $response = $this->actingAs($user)->get(route('admin.customers.export', ['format' => 'csv', ...$query]));
        $response->assertOk();

        return $response->streamedContent();
    }

    private function queryCount(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
