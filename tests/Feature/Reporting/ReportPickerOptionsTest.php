<?php

namespace Tests\Feature\Reporting;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\Location;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesReportingUsers;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * The technician, product, location and user filters of the report pages are server-searched pickers:
 * the option prop is optional, needs two characters, stays inside the company, and the option of the
 * current value (`selected*`) never reveals another company's row.
 */
class ReportPickerOptionsTest extends TestCase
{
    use CreatesReportingUsers;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private User $admin;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->admin = $this->userWithRole('admin');
        $this->other = Company::factory()->create(['is_active' => true]);
        $this->actingAs($this->admin);
    }

    // ---------------------------------------------------------------------------------------------
    // Technician
    // ---------------------------------------------------------------------------------------------

    public function test_the_technician_page_sends_no_unbounded_list_and_an_optional_option_prop(): void
    {
        $this->technician('Rani Teknisi', 'rani@example.test');

        $this->get(route('admin.reports.technician'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Reports/Technician')
                ->missing('technicians')
                ->missing('technicianOptions')
                ->where('selectedTechnician', null)
                ->where('hasTechnicians', true));
    }

    public function test_the_technician_page_says_when_no_technician_can_be_picked(): void
    {
        $this->get(route('admin.reports.technician'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('hasTechnicians', false));
    }

    public function test_a_technician_search_under_two_characters_adds_nothing(): void
    {
        $this->technician('Rani Teknisi', 'rani@example.test');

        $this->assertSame([], $this->partial('Admin/Reports/Technician', 'technicianOptions', route('admin.reports.technician', ['technician_search' => 'r']))->json('props.technicianOptions'));
    }

    public function test_the_technician_options_hold_only_eligible_technicians_of_the_company(): void
    {
        $eligible = $this->technician('Rani Teknisi', 'rani@example.test');
        $this->technician('Rani Nonaktif', 'rani.off@example.test', active: false);
        $this->technician('Rani Asing', 'rani.asing@example.test', companyId: $this->other->id);
        $staff = User::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Rani Staf', 'is_active' => true]);
        $staff->assignRole(Role::findByName('staff'));
        EmployeeProfile::factory()->create(['company_id' => $this->admin->company_id, 'user_id' => $staff->id, 'organization_id' => null, 'status' => 'active']);

        $response = $this->partial('Admin/Reports/Technician', 'technicianOptions', route('admin.reports.technician', ['technician_search' => 'Rani']))->assertOk();

        $this->assertSame(
            [['value' => $eligible->id, 'label' => 'Rani Teknisi', 'description' => 'rani@example.test']],
            $response->json('props.technicianOptions'),
        );
    }

    public function test_the_selected_technician_is_the_option_of_the_current_filter(): void
    {
        $eligible = $this->technician('Rani Teknisi', 'rani@example.test');

        $this->get(route('admin.reports.technician', ['technician_id' => $eligible->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedTechnician', ['value' => $eligible->id, 'label' => 'Rani Teknisi', 'description' => 'rani@example.test'])
                ->missing('technicianOptions'));
    }

    public function test_a_foreign_technician_id_is_refused_and_never_named(): void
    {
        $foreign = $this->technician('Rani Asing', 'rani.asing@example.test', companyId: $this->other->id);

        $this->get(route('admin.reports.technician', ['technician_id' => $foreign->id]))->assertForbidden();
    }

    // ---------------------------------------------------------------------------------------------
    // Product
    // ---------------------------------------------------------------------------------------------

    public function test_the_product_options_are_optional_and_need_two_characters(): void
    {
        $this->product($this->admin->company_id, 'Kabel Fiber', 'SKU-KAB-1');

        $this->get(route('admin.reports.stock-card'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Reports/StockCard')
                ->missing('productOptions')
                ->where('selectedProduct', null));

        $this->assertSame([], $this->partial('Admin/Reports/StockCard', 'productOptions', route('admin.reports.stock-card', ['product_search' => 'k']))->json('props.productOptions'));
    }

    public function test_the_product_options_match_name_or_sku_in_the_company_and_keep_a_deleted_product(): void
    {
        $mine = $this->product($this->admin->company_id, 'Kabel Fiber', 'SKU-A-1');
        $deleted = $this->product($this->admin->company_id, 'Kabel Lama', 'SKU-A-2');
        $deleted->delete();
        $this->product($this->other->id, 'Kabel Asing', 'SKU-A-3');

        $options = $this->partial('Admin/Reports/StockCard', 'productOptions', route('admin.reports.stock-card', ['product_search' => 'Kabel']))->json('props.productOptions');

        $this->assertSame(
            [
                ['value' => $mine->id, 'label' => 'Kabel Fiber', 'description' => 'SKU-A-1'],
                ['value' => $deleted->id, 'label' => 'Kabel Lama', 'description' => 'SKU-A-2'],
            ],
            $options,
        );
        $this->assertSame([$mine->id], array_column(
            $this->partial('Admin/Reports/StockCard', 'productOptions', route('admin.reports.stock-card', ['product_search' => 'SKU-A-1']))->json('props.productOptions'),
            'value',
        ));
    }

    public function test_the_selected_product_is_the_current_option_even_when_deleted(): void
    {
        $product = $this->product($this->admin->company_id, 'Kabel Lama', 'SKU-B-1');
        $product->delete();

        $this->get(route('admin.reports.stock-card', ['product_id' => $product->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedProduct', ['value' => $product->id, 'label' => 'Kabel Lama', 'description' => 'SKU-B-1']));
    }

    public function test_a_foreign_product_id_gets_a_validation_error_not_a_page(): void
    {
        $foreign = $this->product($this->other->id, 'Kabel Asing', 'SKU-C-1');

        $response = $this->get(route('admin.reports.stock-card', ['product_id' => $foreign->id]));

        $response->assertSessionHasErrors('product_id');
        $this->assertStringNotContainsString('Kabel Asing', $response->getContent());
    }

    // ---------------------------------------------------------------------------------------------
    // Location
    // ---------------------------------------------------------------------------------------------

    public function test_the_location_options_are_optional_scoped_and_need_two_characters(): void
    {
        $mine = Location::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Gudang Pusat', 'code' => 'GDG-01']);
        Location::factory()->create(['company_id' => $this->other->id, 'name' => 'Gudang Asing', 'code' => 'GDG-99']);

        $this->get(route('admin.reports.stock-card'))
            ->assertInertia(fn (Assert $page) => $page->missing('locationOptions')->where('selectedLocation', null));

        $this->assertSame([], $this->partial('Admin/Reports/StockCard', 'locationOptions', route('admin.reports.stock-card', ['location_search' => 'g']))->json('props.locationOptions'));
        $this->assertSame(
            [['value' => $mine->id, 'label' => 'Gudang Pusat', 'description' => 'GDG-01']],
            $this->partial('Admin/Reports/StockCard', 'locationOptions', route('admin.reports.stock-card', ['location_search' => 'Gudang']))->json('props.locationOptions'),
        );
    }

    public function test_the_selected_location_is_the_current_option_and_a_foreign_id_is_a_validation_error(): void
    {
        $mine = Location::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Gudang Pusat', 'code' => 'GDG-01']);
        $foreign = Location::factory()->create(['company_id' => $this->other->id, 'name' => 'Gudang Asing', 'code' => 'GDG-99']);
        $product = $this->product($this->admin->company_id, 'Kabel Fiber', 'SKU-D-1');

        $this->get(route('admin.reports.stock-card', ['product_id' => $product->id, 'location_id' => $mine->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedLocation', ['value' => $mine->id, 'label' => 'Gudang Pusat', 'description' => 'GDG-01']));

        $response = $this->get(route('admin.reports.stock-card', ['product_id' => $product->id, 'location_id' => $foreign->id]));

        $response->assertSessionHasErrors('location_id');
        $this->assertStringNotContainsString('Gudang Asing', $response->getContent());
    }

    // ---------------------------------------------------------------------------------------------
    // Audit log user
    // ---------------------------------------------------------------------------------------------

    public function test_the_user_options_are_optional_need_two_characters_and_hold_only_company_members(): void
    {
        $mine = $this->member('Rina Anggota', 'rina@example.test', $this->admin->company_id);
        $this->member('Rina Asing', 'rina.asing@example.test', $this->other->id);

        $this->get(route('admin.reports.audit-log'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Reports/AuditLog')
                ->missing('userOptions')
                ->where('selectedUser', null));

        $this->assertSame([], $this->partial('Admin/Reports/AuditLog', 'userOptions', route('admin.reports.audit-log', ['user_search' => 'r']))->json('props.userOptions'));
        $this->assertSame(
            [['value' => $mine->id, 'label' => 'Rina Anggota', 'description' => 'rina@example.test']],
            $this->partial('Admin/Reports/AuditLog', 'userOptions', route('admin.reports.audit-log', ['user_search' => 'Rina']))->json('props.userOptions'),
        );
    }

    public function test_the_selected_user_is_the_current_option_and_a_foreign_user_is_a_validation_error(): void
    {
        $mine = $this->member('Rina Anggota', 'rina@example.test', $this->admin->company_id);
        $foreign = $this->member('Rina Asing', 'rina.asing@example.test', $this->other->id);

        $this->get(route('admin.reports.audit-log', ['user_id' => $mine->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedUser', ['value' => $mine->id, 'label' => 'Rina Anggota', 'description' => 'rina@example.test']));

        $response = $this->get(route('admin.reports.audit-log', ['user_id' => $foreign->id]));

        $response->assertSessionHasErrors('user_id');
        $this->assertStringNotContainsString('Rina Asing', $response->getContent());
    }

    // ---------------------------------------------------------------------------------------------
    // Drill
    // ---------------------------------------------------------------------------------------------

    public function test_the_drill_page_sends_the_kind_of_its_source(): void
    {
        $this->get(route('admin.reports.business.drill', ['metric' => 'mrr']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Reports/Drill')
                ->where('source.kind', 'subscription'));
    }

    // ---------------------------------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------------------------------

    /** The prop is optional, so it only comes with a partial reload of that prop. */
    private function partial(string $component, string $prop, string $url): TestResponse
    {
        return $this->get($url, [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => $component,
            'X-Inertia-Partial-Data' => $prop,
        ]);
    }

    private function technician(string $name, string $email, bool $active = true, ?int $companyId = null): User
    {
        $companyId ??= (int) $this->admin->company_id;
        $user = User::factory()->create(['company_id' => $companyId, 'name' => $name, 'email' => $email, 'is_active' => $active, 'email_verified_at' => now()]);
        $user->assignRole(Role::findByName('technician'));
        EmployeeProfile::factory()->create(['company_id' => $companyId, 'user_id' => $user->id, 'organization_id' => null, 'status' => 'active']);

        return $user;
    }

    private function member(string $name, string $email, int $companyId): User
    {
        $user = User::factory()->create(['company_id' => $companyId, 'name' => $name, 'email' => $email, 'is_active' => true]);
        DB::table('company_user')->insert([
            'company_id' => $companyId,
            'user_id' => $user->id,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function product(int $companyId, string $name, string $sku): Product
    {
        $unit = Unit::factory()->create(['company_id' => $companyId]);
        $category = Category::factory()->create(['company_id' => $companyId, 'unit_id' => $unit->id]);

        return Product::withoutCompany()->forceCreate(
            Product::factory()->make([
                'company_id' => $companyId,
                'category_id' => $category->id,
                'unit_id' => $unit->id,
                'name' => $name,
                'sku' => $sku,
            ])->getAttributes()
        )->refresh();
    }
}
