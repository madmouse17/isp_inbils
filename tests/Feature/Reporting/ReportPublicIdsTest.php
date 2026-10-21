<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\EmployeeProfile;
use App\Models\Core\Location;
use App\Models\User;
use App\Services\Core\AuditService;
use App\Support\PublicId;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Unit;
use Modules\Service\Models\ServicePackageCategory;
use Modules\Ticketing\Models\TicketCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\BuildsReportingWorld;
use Tests\Traits\CreatesReportingUsers;
use Tests\Traits\ParsesCsvDownloads;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * The report pages, their lists and their files name a record by its public id only (spec .scratch/opaque-ids): the
 * props, the links and the files carry no integer id, and a filter refuses an integer, an unknown id and the id of
 * another company in the same words.
 */
class ReportPublicIdsTest extends TestCase
{
    use BuildsReportingWorld;
    use CreatesReportingUsers;
    use ParsesCsvDownloads;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private const JANUARY = ['date_from' => '2026-01-01', 'date_to' => '2026-01-31'];

    /** Props every page shares (HandleInertiaRequests): not what the reports send. */
    private const SHARED_PROPS = ['auth', 'impersonation', 'appOnly', 'company', 'companyHierarchy', 'companies', 'canSwitch', 'flash', 'workQueues', 'canMyWork', 'developerGalleryEnabled', 'notifications', 'app', 'errors'];

    private User $admin;

    private int $companyId;

    private User $technician;

    private Location $location;

    private TicketCategory $category;

    private ServicePackageCategory $packageCategory;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->admin = $this->userWithRole('admin');
        $this->companyId = (int) $this->admin->company_id;
        $this->actingAs($this->admin);
        $this->seedWorld();
    }

    // ---------------------------------------------------------------------------------------------
    // Pages
    // ---------------------------------------------------------------------------------------------

    public function test_no_report_page_or_list_sends_an_integer_id_and_each_echoes_the_filters_it_was_given(): void
    {
        $pages = [
            [route('admin.reports.business', [...self::JANUARY, 'service_package_category_id' => $this->packageCategory->public_id]), 'service_package_category_id', $this->packageCategory->public_id],
            [route('admin.reports.sales', [...self::JANUARY, 'acquirer_id' => $this->admin->public_id]), 'acquirer_id', $this->admin->public_id],
            [route('admin.reports.asset', ['location_id' => $this->location->public_id]), 'location_id', $this->location->public_id],
            [route('admin.reports.sla', [...self::JANUARY, 'category_id' => $this->category->public_id]), 'category_id', $this->category->public_id],
            [route('admin.reports.technician', [...self::JANUARY, 'technician_id' => $this->technician->public_id]), 'technician_id', $this->technician->public_id],
            [route('admin.reports.stock-card', ['product_id' => $this->product->public_id, 'location_id' => $this->location->public_id]), 'product_id', $this->product->public_id],
            [route('admin.reports.audit-log', ['user_id' => $this->admin->public_id]), 'user_id', $this->admin->public_id],
            [route('admin.reports.business.drill', ['metric' => 'new_subscriptions', ...self::JANUARY]), null, null],
            [route('admin.reports.sla.drill', ['metric' => 'total_resolved', ...self::JANUARY, 'category_id' => $this->category->public_id]), 'category_id', $this->category->public_id],
            [route('admin.reports.asset.drill', ['metric' => 'installation_count', ...self::JANUARY]), null, null],
            [route('admin.reports.technician.drill', ['metric' => 'spk_completed', ...self::JANUARY, 'technician_id' => $this->technician->public_id]), 'technician_id', $this->technician->public_id],
        ];

        foreach ($pages as [$url, $filter, $echo]) {
            $props = $this->get($url)->assertOk()->inertiaProps();

            $this->assertOnlyPublicIds(array_diff_key($props, array_flip(self::SHARED_PROPS)), $url);

            if ($filter !== null) {
                $this->assertSame($echo, $props['filters'][$filter], $url);
            }
        }
    }

    public function test_the_stock_card_names_its_references_and_links_without_an_integer_id(): void
    {
        $props = $this->get(route('admin.reports.stock-card', ['product_id' => $this->product->public_id, 'location_id' => $this->location->public_id]))
            ->assertOk()->inertiaProps();

        $movement = $props['movements']['data'][0];
        $this->assertSame($this->product->public_id, $movement['product_id']);
        $this->assertSame($this->location->public_id, $movement['reference_id']);
        $this->assertSame('Location #'.$this->location->public_id, $movement['reference']['label']);
        $this->assertSame(
            ['product_id' => $this->product->public_id, 'location_id' => $this->location->public_id],
            $this->queryOf($props['links']['stocks']),
        );
    }

    public function test_the_sales_report_lists_acquirers_and_package_categories_by_public_id(): void
    {
        $props = $this->get(route('admin.reports.sales', self::JANUARY))->assertOk()->inertiaProps();

        $this->assertContains($this->admin->public_id, array_column($props['acquirers'], 'id'));
        $this->assertSame([$this->packageCategory->public_id], array_column($props['packageCategories'], 'id'));
    }

    // ---------------------------------------------------------------------------------------------
    // Filters
    // ---------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string}> */
    public static function recordFilters(): array
    {
        return [
            'the location of the asset report' => ['admin.reports.asset', 'location_id'],
            'the category of the SLA report' => ['admin.reports.sla', 'category_id'],
            'the package category of the business report' => ['admin.reports.business', 'service_package_category_id'],
            'the acquirer of the sales report' => ['admin.reports.sales', 'acquirer_id'],
            'the product of the stock card' => ['admin.reports.stock-card', 'product_id'],
            'the user of the audit log' => ['admin.reports.audit-log', 'user_id'],
            'the technician of the technician report' => ['admin.reports.technician', 'technician_id'],
        ];
    }

    #[DataProvider('recordFilters')]
    public function test_a_record_filter_refuses_the_integer_id_of_a_record_that_exists(string $route, string $filter): void
    {
        $this->getJson(route($route, [$filter => (string) $this->recordFor($filter)->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($filter);
    }

    #[DataProvider('recordFilters')]
    public function test_a_record_filter_takes_the_public_id_of_a_record_of_the_company(string $route, string $filter): void
    {
        $this->getJson(route($route, [$filter => $this->recordFor($filter)->public_id]))->assertOk();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function companyRecordFilters(): array
    {
        return array_diff_key(self::recordFilters(), ['the technician of the technician report' => 1]);
    }

    #[DataProvider('companyRecordFilters')]
    public function test_a_record_filter_answers_alike_for_an_unknown_id(string $route, string $filter): void
    {
        $this->getJson(route($route, [$filter => PublicId::new()]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($filter);
    }

    public function test_the_technician_filter_refuses_an_integer_and_forbids_an_unknown_or_foreign_technician(): void
    {
        $foreign = User::factory()->create(['company_id' => $this->userWithRole('admin')->company_id, 'is_active' => true]);
        $this->grantRole($foreign, 'technician');

        $this->getJson(route('admin.reports.technician', ['technician_id' => (string) $this->technician->id]))->assertUnprocessable();
        $this->getJson(route('admin.reports.technician', ['technician_id' => PublicId::new()]))->assertForbidden();
        $this->getJson(route('admin.reports.technician', ['technician_id' => $foreign->public_id]))->assertForbidden();
    }

    // ---------------------------------------------------------------------------------------------
    // Links and files
    // ---------------------------------------------------------------------------------------------

    public function test_a_drill_link_and_a_summary_file_carry_the_public_id_of_the_filter(): void
    {
        $query = ['technician_id' => $this->technician->public_id, ...self::JANUARY];
        $link = $this->get(route('admin.reports.technician', $query))->assertOk()->inertiaProps('drill.spk_completed');

        $this->assertSame($query, $this->queryOf($link));
        $this->get($link)->assertOk()->assertInertia(fn ($page) => $page
            ->where('filters.technician_id', $this->technician->public_id)
            ->where('rows.total', 1)
            ->where('report.href', fn (string $href) => $this->queryOf($href) === $query));

        $figure = collect($this->csvRecords($this->get(route('admin.reports.technician.export', $query))))->first();
        $this->assertSame('technician_id='.$this->technician->public_id.'; date_from=2026-01-01; date_to=2026-01-31', $figure['Filter']);
    }

    public function test_the_audit_file_names_the_entry_the_actor_and_the_subject_by_public_id(): void
    {
        $activity = AuditService::log('billing', 'with_a_subject', ['note' => 'x'], $this->location);
        AuditService::log('billing', 'without_a_subject');

        $file = fn (): array => collect($this->csvRecords($this->get(route('admin.reports.audit-log.export', ['log_name' => 'billing']))))->keyBy('Aksi')->all();
        $row = $file()['with_a_subject'];

        $this->assertSame($activity->public_id, $row['ID']);
        $this->assertSame($this->admin->public_id, $row['ID pelaku']);
        $this->assertSame($this->location->public_id, $row['ID subjek']);
        $this->assertSame('', $file()['without_a_subject']['ID subjek']);

        // A deleted subject keeps its id in the file, like on the screen.
        $this->location->delete();
        $this->assertSame($this->location->public_id, $file()['with_a_subject']['ID subjek']);
    }

    // ---------------------------------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------------------------------

    /** The company's record a filter names. */
    private function recordFor(string $filter): Model
    {
        return match ($filter) {
            'location_id' => $this->location,
            'category_id' => $this->category,
            'service_package_category_id' => $this->packageCategory,
            'product_id' => $this->product,
            'technician_id' => $this->technician,
            default => $this->admin,
        };
    }

    /**
     * One January in the company: an SPK done and a ticket resolved by the technician, a subscription, an asset with
     * its installation, and a stock movement that points at a location.
     */
    private function seedWorld(): void
    {
        $company = $this->companyId;

        $this->technician = User::factory()->create(['company_id' => $company, 'is_active' => true, 'email_verified_at' => now(), 'name' => 'Teknisi Satu']);
        $this->grantRole($this->technician, 'technician');
        EmployeeProfile::factory()->create(['company_id' => $company, 'user_id' => $this->technician->id, 'status' => 'active']);
        // An acquirer of the sales report is an active employee with customer.create; a member is what the audit log's user filter takes.
        EmployeeProfile::factory()->create(['company_id' => $company, 'user_id' => $this->admin->id, 'status' => 'active']);
        DB::table('company_user')->insert(['company_id' => $company, 'user_id' => $this->admin->id, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->location = $this->worldLocation($company, 'POP-C', 'POP Central');
        $this->category = $this->worldCategory($company, 'Support', 'SUP');
        $this->packageCategory = ServicePackageCategory::factory()->create(['company_id' => $company, 'code' => 'RUMAHAN', 'name' => 'Rumahan', 'sort_order' => 1]);

        $this->worldWorkOrder($company, 'installation', 'completed', '2026-01-10 10:00:00', $this->technician->id, '2026-01-10 09:00:00');
        $this->worldTicket($company, $this->category, '2026-01-11 08:00:00', '2026-01-11 08:05:00', '2026-01-11 09:00:00', '2026-01-11 10:00:00', 'resolved', $this->technician->id);
        $this->worldSubscription($company, 'active', 100000, '2026-01-10 03:00:00');
        $this->worldInstallation($company, $this->worldAsset($company, 'router', 'installed', $this->location), $this->location, '2026-01-12 08:00:00');

        $unit = Unit::factory()->create(['company_id' => $company]);
        $category = Category::factory()->create(['company_id' => $company, 'unit_id' => $unit->id]);
        $this->product = Product::factory()->create(['company_id' => $company, 'category_id' => $category->id, 'unit_id' => $unit->id]);
        StockMovement::withoutCompany()->forceCreate([
            'company_id' => $company,
            'product_id' => $this->product->id,
            'to_location_id' => $this->location->id,
            'movement_type' => 'receive',
            'quantity' => 5,
            'balance_after' => 5,
            'reserved_after' => 0,
            'created_by' => $this->admin->id,
            'reference_type' => Location::class,
            'reference_id' => $this->location->id,
        ]);
    }

    /**
     * Every `id`, `*_id`, `*_ids` and `*_by` key of the props holds a public id or null: an integer id never leaves
     * the server.
     */
    private function assertOnlyPublicIds(mixed $node, string $path): void
    {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            $at = $path.'.'.$key;

            // tax_id (NPWP) and other business identifiers are not record ids.
            if (is_string($key) && preg_match('/^(id|assigned_to)$|(?<!tax)(_id|_ids)$|(?<!sort)_by$/', $key) === 1) {
                foreach ((is_array($value) ? $value : [$value]) as $id) {
                    $this->assertTrue($id === null || (is_string($id) && Str::isUlid($id)), "{$at} is not a public id: ".json_encode($id));
                }

                continue;
            }

            $this->assertOnlyPublicIds($value, $at);
        }
    }

    /** @return array<string, string> */
    private function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }
}
