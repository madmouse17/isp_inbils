<?php

namespace Tests\Feature\Integrity;

use App\Models\Core\Company;
use App\Models\User;
use App\Support\OptimisticLock;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Modules\Service\Models\ServicePackage;
use Modules\Service\Models\ServicePackageCategory;
use Tests\TestCase;

/**
 * Item 11 of integrity-hardening for the catalog forms: a product or package save carries the version the form was
 * rendered from; a save from an older copy is refused and writes nothing.
 */
class CatalogEditConflictTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->admin->assignRole('admin');
    }

    public function test_product_and_package_saves_from_an_older_copy_are_refused(): void
    {
        $package = $this->package();
        $packageUrl = route('admin.service-packages.update', $package);
        $packageRendered = OptimisticLock::version($package);

        $this->actingAs($this->admin)->put($packageUrl, $this->packagePayload($package, ['name' => 'First save', OptimisticLock::FIELD => $packageRendered]))
            ->assertRedirect(route('admin.service-packages.index'));

        $this->putJson($packageUrl, $this->packagePayload($package, ['name' => 'Second save', OptimisticLock::FIELD => $packageRendered]))
            ->assertStatus(409)
            ->assertExactJson(['message' => OptimisticLock::MESSAGE, 'error' => 'conflict']);
        $this->assertSame('First save', $package->fresh()->name);

        $product = $this->product();
        $productUrl = route('admin.products.update', $product);
        $productRendered = OptimisticLock::version($product);

        $this->put($productUrl, $this->productPayload($product, ['name' => 'First save', OptimisticLock::FIELD => $productRendered]))
            ->assertRedirect(route('admin.products.index'));

        $this->withHeader('X-Inertia', 'true')
            ->put($productUrl, $this->productPayload($product, ['name' => 'Second save', OptimisticLock::FIELD => $productRendered]))
            ->assertStatus(303)
            ->assertSessionHasErrors(['conflict' => OptimisticLock::MESSAGE])
            ->assertSessionHas('error', OptimisticLock::MESSAGE);
        $this->assertSame('First save', $product->fresh()->name);
    }

    public function test_a_save_from_the_current_copy_is_stored(): void
    {
        $product = $this->product();
        $package = $this->package();

        $this->actingAs($this->admin)
            ->put(route('admin.products.update', $product), $this->productPayload($product, ['name' => 'Renamed', OptimisticLock::FIELD => OptimisticLock::version($product)]))
            ->assertRedirect(route('admin.products.index'));
        $this->put(route('admin.service-packages.update', $package), $this->packagePayload($package, ['name' => 'Renamed', OptimisticLock::FIELD => OptimisticLock::version($package)]))
            ->assertRedirect(route('admin.service-packages.index'));

        $this->assertSame('Renamed', $product->fresh()->name);
        $this->assertSame('Renamed', $package->fresh()->name);
    }

    public function test_a_save_without_a_version_is_a_validation_error_and_changes_nothing(): void
    {
        $product = $this->product();
        $package = $this->package();

        $this->actingAs($this->admin)->putJson(route('admin.products.update', $product), $this->productPayload($product, ['name' => 'No version']))
            ->assertUnprocessable()->assertJsonValidationErrors(OptimisticLock::FIELD);
        $this->putJson(route('admin.service-packages.update', $package), $this->packagePayload($package, ['name' => 'No version']))
            ->assertUnprocessable()->assertJsonValidationErrors(OptimisticLock::FIELD);

        $this->assertSame('Start', $product->fresh()->name);
        $this->assertSame('Start', $package->fresh()->name);
    }

    public function test_the_edit_pages_hand_out_the_version_to_send_back(): void
    {
        $product = $this->product();
        $package = $this->package();

        $this->actingAs($this->admin)->get(route('admin.products.edit', $product))
            ->assertInertia(fn (Assert $page) => $page->where('product.data.updated_at', OptimisticLock::version($product)));
        $this->get(route('admin.service-packages.edit', $package))
            ->assertInertia(fn (Assert $page) => $page->where('servicePackage.data.updated_at', OptimisticLock::version($package)));
    }

    /** A product whose version is a minute old, so that any write moves it to another second. */
    private function product(): Product
    {
        $unit = Unit::factory()->create(['company_id' => $this->company->id]);
        $category = Category::factory()->create(['company_id' => $this->company->id, 'unit_id' => $unit->id]);

        return Product::factory()->create([
            'company_id' => $this->company->id, 'category_id' => $category->id, 'unit_id' => $unit->id,
            'sku' => 'KEEP-1', 'name' => 'Start', 'updated_at' => now()->subMinute(),
        ])->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function productPayload(Product $product, array $overrides): array
    {
        return ['sku' => $product->sku, 'name' => 'Kabel FO', 'category_id' => $product->category->public_id, ...$overrides];
    }

    private function package(): ServicePackage
    {
        $category = ServicePackageCategory::factory()->create(['company_id' => $this->company->id, 'is_active' => true]);

        return ServicePackage::factory()->create([
            'company_id' => $this->company->id, 'service_package_category_id' => $category->id,
            'code' => 'PKG-1', 'name' => 'Start', 'updated_at' => now()->subMinute(),
        ])->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function packagePayload(ServicePackage $package, array $overrides): array
    {
        return [
            'code' => $package->code, 'name' => 'Paket Baru',
            'bandwidth_profile_id' => $this->publicId('bandwidth_profiles', $package->bandwidth_profile_id),
            'speed_profile_id' => $this->publicId('speed_profiles', $package->speed_profile_id),
            'sla_tier_id' => $this->publicId('sla_tiers', $package->sla_tier_id),
            'service_package_category_id' => $package->category->public_id,
            'price_mrc' => 200000, 'is_active' => true,
            ...$overrides,
        ];
    }

    private function publicId(string $table, int $id): string
    {
        return (string) DB::table($table)->where('id', $id)->value('public_id');
    }
}
