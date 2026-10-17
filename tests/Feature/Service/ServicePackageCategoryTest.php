<?php

namespace Tests\Feature\Service;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use App\Services\Core\CompanyHierarchyService;
use App\Services\Core\CompanyService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Service\Database\Seeders\ServiceCatalogSeeder;
use Modules\Service\Models\ServicePackage;
use Modules\Service\Models\ServicePackageCategory;
use Modules\Service\Services\ServicePackageOptions;
use Tests\TestCase;

class ServicePackageCategoryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->other = Company::factory()->create(['is_active' => true]);
    }

    private function user(string $role, ?Company $company = null): User
    {
        $user = User::factory()->create(['company_id' => ($company ?? $this->company)->id, 'email_verified_at' => now(), 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function category(string $code, int $sortOrder = 0, bool $active = true, ?Company $company = null): ServicePackageCategory
    {
        return ServicePackageCategory::factory()->create([
            'company_id' => ($company ?? $this->company)->id, 'code' => $code, 'name' => ucfirst(strtolower($code)),
            'sort_order' => $sortOrder, 'is_active' => $active,
        ]);
    }

    private function package(ServicePackageCategory $category, string $name = 'Paket', bool $active = true): ServicePackage
    {
        return ServicePackage::factory()->create([
            'company_id' => $category->company_id, 'service_package_category_id' => $category->id,
            'name' => $name, 'code' => 'P-'.strtoupper(substr(md5($name.$category->id), 0, 8)), 'is_active' => $active,
        ]);
    }

    /** @return array<string, mixed> */
    private function packagePayload(ServicePackage $base, string|int $categoryId, array $overrides = []): array
    {
        return [
            'code' => 'NEW-1', 'name' => 'Paket Baru',
            'bandwidth_profile_id' => $this->publicId('bandwidth_profiles', $base->bandwidth_profile_id),
            'speed_profile_id' => $this->publicId('speed_profiles', $base->speed_profile_id),
            'sla_tier_id' => $this->publicId('sla_tiers', $base->sla_tier_id),
            'service_package_category_id' => $categoryId, 'price_mrc' => 200000, 'is_active' => true,
            ...$overrides,
        ];
    }

    private function publicId(string $table, int $id): string
    {
        return (string) DB::table($table)->where('id', $id)->value('public_id');
    }

    public function test_index_lists_company_categories_with_the_package_count_including_soft_deleted(): void
    {
        $rumahan = $this->category('RUMAHAN', 1);
        $this->category('BISNIS', 2, false);
        $this->category('LAIN', 1, true, $this->other);
        $this->package($rumahan, 'A');
        $this->package($rumahan, 'B')->delete();

        $this->actingAs($this->user('manager'))->get(route('admin.service-package-categories.index'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Service/PackageCategories/Index', false)
            ->has('packageCategories.data', 2)
            ->where('packageCategories.data.0', fn ($row) => $row['code'] === 'RUMAHAN' && $row['packages_count'] === 2 && $row['is_active'] === true && $row['sort_order'] === 1)
            ->where('packageCategories.data.1.code', 'BISNIS')
            ->where('packageCategories.data.1.is_active', false)
            ->where('can', ['create' => true, 'update' => true, 'delete' => true]));
    }

    public function test_staff_may_view_but_not_change_categories(): void
    {
        $category = $this->category('RUMAHAN');
        $staff = $this->user('staff');

        $this->actingAs($staff)->get(route('admin.service-package-categories.index'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('can', ['create' => false, 'update' => false, 'delete' => false]));
        $this->actingAs($staff)->post(route('admin.service-package-categories.store'), ['code' => 'X', 'name' => 'X'])->assertForbidden();
        $this->actingAs($staff)->put(route('admin.service-package-categories.update', $category), ['name' => 'X'])->assertForbidden();
        $this->actingAs($staff)->delete(route('admin.service-package-categories.destroy', $category))->assertForbidden();

        $this->assertSame('Rumahan', $category->fresh()->name);
    }

    public function test_manager_creates_a_category_with_an_uppercased_code_unique_per_company(): void
    {
        $this->category('SOHO', 4, true, $this->other);
        $manager = $this->user('manager');

        $this->actingAs($manager)->from(route('admin.service-package-categories.index'))
            ->post(route('admin.service-package-categories.store'), ['code' => ' soho ', 'name' => 'Usaha Kecil', 'sort_order' => 7, 'is_active' => true])
            ->assertRedirect(route('admin.service-package-categories.index'));

        $created = ServicePackageCategory::query()->where('code', 'SOHO')->sole();
        $this->assertSame($this->company->id, $created->company_id);
        $this->assertSame([7, true, 'Usaha Kecil'], [$created->sort_order, $created->is_active, $created->name]);

        $this->actingAs($manager)->postJson(route('admin.service-package-categories.store'), ['code' => 'soho', 'name' => 'Lagi'])
            ->assertUnprocessable()->assertJsonValidationErrors(['code' => 'Kode kategori sudah dipakai.']);
        $this->actingAs($manager)->postJson(route('admin.service-package-categories.store'), ['code' => 'a b', 'name' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['code', 'name']);
        $this->assertSame(1, ServicePackageCategory::query()->where('code', 'SOHO')->count());
    }

    public function test_update_changes_name_order_and_status_but_never_the_code(): void
    {
        $category = $this->category('BISNIS', 2);

        $this->actingAs($this->user('manager'))
            ->put(route('admin.service-package-categories.update', $category), ['code' => 'HACK', 'name' => 'Usaha', 'sort_order' => 9, 'is_active' => false])
            ->assertRedirect();

        $this->assertDatabaseHas('service_package_categories', ['id' => $category->id, 'code' => 'BISNIS', 'name' => 'Usaha', 'sort_order' => 9, 'is_active' => false]);
    }

    public function test_another_company_category_is_not_reachable(): void
    {
        $foreign = $this->category('RUMAHAN', 1, true, $this->other);
        $manager = $this->user('manager');

        $this->actingAs($manager)->put(route('admin.service-package-categories.update', $foreign), ['name' => 'X'])->assertNotFound();
        $this->actingAs($manager)->delete(route('admin.service-package-categories.destroy', $foreign))->assertNotFound();
        $this->assertDatabaseHas('service_package_categories', ['id' => $foreign->id]);
    }

    public function test_an_unused_category_can_be_deleted(): void
    {
        $category = $this->category('KOSONG');

        $this->actingAs($this->user('manager'))->delete(route('admin.service-package-categories.destroy', $category))->assertRedirect();

        $this->assertDatabaseMissing('service_package_categories', ['id' => $category->id]);
    }

    public function test_a_category_in_use_cannot_be_deleted_even_by_a_soft_deleted_package(): void
    {
        $category = $this->category('RUMAHAN');
        $this->package($category, 'Hidup');
        $this->package($category, 'Terhapus')->delete();
        $manager = $this->user('manager');

        $this->actingAs($manager)->deleteJson(route('admin.service-package-categories.destroy', $category))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category' => 'Kategori masih dipakai 2 paket. Nonaktifkan saja.']);
        $this->assertDatabaseHas('service_package_categories', ['id' => $category->id]);

        $this->actingAs($manager)->put(route('admin.service-package-categories.update', $category), ['name' => 'Rumahan', 'is_active' => false])->assertRedirect();
        $this->assertFalse($category->fresh()->is_active);
    }

    public function test_package_category_is_required_in_the_company_and_active(): void
    {
        $manager = $this->user('manager');
        $active = $this->category('RUMAHAN', 1);
        $inactive = $this->category('LAMA', 5, false);
        $foreign = $this->category('ASING', 1, true, $this->other);
        $base = $this->package($active, 'Dasar');
        $url = route('admin.service-packages.store');

        $this->actingAs($manager)->postJson($url, collect($this->packagePayload($base, $active->public_id))->except('service_package_category_id')->all())
            ->assertUnprocessable()->assertJsonValidationErrors('service_package_category_id');
        foreach ([$inactive->public_id, $foreign->public_id, 999999] as $id) {
            $this->actingAs($manager)->postJson($url, $this->packagePayload($base, $id))
                ->assertUnprocessable()->assertJsonValidationErrors('service_package_category_id');
        }

        $this->actingAs($manager)->post($url, $this->packagePayload($base, $active->public_id))->assertRedirect(route('admin.service-packages.index'));
        $this->assertSame($active->id, ServicePackage::query()->where('code', 'NEW-1')->sole()->service_package_category_id);
    }

    public function test_package_update_keeps_an_inactive_category_but_cannot_switch_to_another_inactive_one(): void
    {
        $manager = $this->user('manager');
        $retired = $this->category('LAMA', 5, false);
        $otherRetired = $this->category('LAMA2', 6, false);
        $active = $this->category('BISNIS', 2);
        $package = $this->package($retired, 'Warisan');
        $url = route('admin.service-packages.update', $package);

        $this->actingAs($manager)->put($url, $this->packagePayload($package, $retired->public_id, ['code' => $package->code, 'name' => 'Warisan 2']))->assertRedirect();
        $this->assertSame('Warisan 2', $package->fresh()->name);

        $this->actingAs($manager)->putJson($url, $this->packagePayload($package, $otherRetired->public_id, ['code' => $package->code]))
            ->assertUnprocessable()->assertJsonValidationErrors('service_package_category_id');

        $this->actingAs($manager)->put($url, $this->packagePayload($package, $active->public_id, ['code' => $package->code]))->assertRedirect();
        $this->assertSame($active->id, $package->fresh()->service_package_category_id);
    }

    public function test_package_form_props_offer_active_categories_plus_the_one_already_selected(): void
    {
        $manager = $this->user('manager');
        $active = $this->category('RUMAHAN', 1);
        $retired = $this->category('LAMA', 5, false);
        $this->category('LAIN', 1, true, $this->other);
        $package = $this->package($retired, 'Warisan');
        $shape = fn (Assert $page) => $page->where('packageCategories.0', ['id' => $active->public_id, 'code' => 'RUMAHAN', 'name' => 'Rumahan', 'is_active' => true]);

        $this->actingAs($manager)->get(route('admin.service-packages.create'))->assertOk()
            ->assertInertia(fn (Assert $page) => $shape($page->has('packageCategories', 1)));
        $this->actingAs($manager)->get(route('admin.service-packages.edit', $package))->assertOk()
            ->assertInertia(fn (Assert $page) => $shape($page->has('packageCategories', 2)
                ->where('packageCategories.1.code', 'LAMA')->where('servicePackage.data.category.code', 'LAMA')));
    }

    public function test_package_list_filters_and_shows_the_category(): void
    {
        $manager = $this->user('manager');
        $rumahan = $this->category('RUMAHAN', 1);
        $bisnis = $this->category('BISNIS', 2);
        $this->package($rumahan, 'Home');
        $this->package($bisnis, 'Biz');

        $this->actingAs($manager)->get(route('admin.service-packages.index', ['service_package_category_id' => $bisnis->public_id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('servicePackages.data', 1)
            ->where('servicePackages.data.0.category', ['id' => $bisnis->public_id, 'code' => 'BISNIS', 'name' => 'Bisnis'])
            ->where('servicePackages.data.0.service_package_category_id', $bisnis->public_id)
            ->where('filters.service_package_category_id', $bisnis->public_id)
            ->where('packageCategories', fn ($rows) => collect($rows)->pluck('code')->all() === ['RUMAHAN', 'BISNIS']));

        $this->actingAs($manager)->get(route('admin.service-packages.index'))
            ->assertInertia(fn (Assert $page) => $page->has('servicePackages.data', 2));
    }

    public function test_grouped_picker_orders_categories_by_customer_type_and_hides_inactive_ones(): void
    {
        $rumahan = $this->category('RUMAHAN', 1);
        $bisnis = $this->category('BISNIS', 2);
        $korporat = $this->category('KORPORAT', 3);
        $promo = $this->category('PROMO', 0);
        $lama = $this->category('LAMA', 4, false);
        $kosong = $this->category('KOSONG', 5);
        $home = $this->package($rumahan, 'Home 20');
        $this->package($rumahan, 'Home 10');
        $this->package($rumahan, 'Home Mati', false);
        $this->package($bisnis, 'Biz');
        $this->package($korporat, 'Ent');
        $this->package($promo, 'Promo');
        $this->package($lama, 'Lama');
        $this->package($this->category('ASING', 1, true, $this->other), 'Asing');

        $codes = fn (?string $type) => CompanyService::runAs($this->company->id, fn () => collect(ServicePackageOptions::grouped($type))->pluck('category.code')->all());

        $this->assertSame(['PROMO', 'RUMAHAN', 'BISNIS', 'KORPORAT'], $codes(null));
        $this->assertSame(['RUMAHAN', 'PROMO', 'BISNIS', 'KORPORAT'], $codes('Individual'));
        $this->assertSame(['BISNIS', 'KORPORAT', 'PROMO', 'RUMAHAN'], $codes('Company'));
        $this->assertSame(['PROMO', 'RUMAHAN', 'BISNIS', 'KORPORAT'], $codes('Whatever'));

        $groups = CompanyService::runAs($this->company->id, fn () => ServicePackageOptions::grouped('Individual'));
        $this->assertSame(['id' => $rumahan->public_id, 'code' => 'RUMAHAN', 'name' => 'Rumahan'], $groups[0]['category']);
        $this->assertSame(['Home 10', 'Home 20'], array_column($groups[0]['packages'], 'label'));
        $this->assertSame(
            ['value' => $home->public_id, 'label' => 'Home 20', 'description' => $home->code.' · Rp '.number_format((float) $home->price_mrc, 0, ',', '.').'/bln'],
            $groups[0]['packages'][1],
        );
        $this->assertNotContains($kosong->code, array_column(array_column($groups, 'category'), 'code'));
    }

    public function test_customer_create_and_subscription_screens_carry_the_grouped_packages(): void
    {
        $manager = $this->user('manager');
        $rumahan = $this->category('RUMAHAN', 1);
        $bisnis = $this->category('BISNIS', 2);
        $this->package($rumahan, 'Home');
        $this->package($bisnis, 'Biz');
        $company = Customer::factory()->create(['company_id' => $this->company->id, 'type' => 'Company']);
        $person = Customer::factory()->create(['company_id' => $this->company->id, 'type' => 'Individual']);

        $this->actingAs($manager)->get(route('admin.customers.create'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('packageGroups.0.category.code', 'RUMAHAN')->where('packageGroups.1.category.code', 'BISNIS'));
        $this->actingAs($manager)->get(route('admin.customers.create', ['customer_type' => 'Company']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('packageGroups.0.category.code', 'BISNIS')->where('packageGroups.0.packages.0.label', 'Biz'));
        $this->actingAs($manager)->get(route('admin.customers.subscriptions.index', $company))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('packageGroups.0.category.code', 'BISNIS'));
        $this->actingAs($manager)->get(route('admin.customers.subscriptions.index', $person))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('packageGroups.0.category.code', 'RUMAHAN'));
    }

    public function test_company_seeder_and_catalog_seeder_agree_with_the_backfill_rule(): void
    {
        $expected = collect(ServicePackageCategoryMigrationTest::packages())->mapWithKeys(fn (array $row) => [$row[0] => $row[2]]);

        CompanyService::runAs($this->company->id, fn () => app(CompanySeeder::class)->runFor($this->company));
        CompanyService::runAs($this->other->id, fn () => (new ServiceCatalogSeeder())->runFor($this->other->id));

        foreach ([$this->company, $this->other] as $company) {
            $categories = DB::table('service_package_categories')->where('company_id', $company->id)->orderBy('sort_order')->pluck('name', 'code')->all();
            $this->assertSame(['RUMAHAN' => 'Rumahan', 'BISNIS' => 'Bisnis', 'KORPORAT' => 'Korporat'], $categories);

            $packages = DB::table('service_packages')->join('service_package_categories as c', 'c.id', '=', 'service_packages.service_package_category_id')
                ->where('service_packages.company_id', $company->id)->get(['service_packages.code as package', 'c.code as category'])->pluck('category', 'package');
            $this->assertCount(8, $packages);
            foreach ($packages as $code => $category) {
                $this->assertSame($expected[$code], $category, $code);
            }
        }
    }

    public function test_new_subsidiaries_get_the_default_categories(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin);

        $subsidiary = app(CompanyHierarchyService::class)->createSubsidiary($this->company, ['name' => 'Anak Usaha', 'code' => 'ANAK']);

        $this->assertSame(
            ['RUMAHAN', 'BISNIS', 'KORPORAT'],
            DB::table('service_package_categories')->where('company_id', $subsidiary->id)->orderBy('sort_order')->pluck('code')->all(),
        );
        $this->assertSame(0, DB::table('service_package_categories')->where('company_id', $this->company->id)->count());
    }
}
