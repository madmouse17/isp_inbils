<?php

namespace Tests\Feature\Inventory;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Tests\TestCase;

class CategoryUnitInheritanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create([
            'company_id' => $this->company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');
    }

    public function test_product_store_inherits_unit_from_category(): void
    {
        $unit = Unit::factory()->create(['company_id' => $this->company->id]);
        $category = Category::factory()->create([
            'company_id' => $this->company->id,
            'unit_id' => $unit->id,
        ]);
        $otherUnit = Unit::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($this->admin)->post(route('admin.products.store'), [
            'sku' => 'UNIT-INHERIT-1',
            'name' => 'Inherited unit product',
            'category_id' => $category->id,
            'unit_id' => $otherUnit->id,
            'track_stock' => true,
            'is_active' => true,
        ])->assertRedirect(route('admin.products.index'));

        $this->assertDatabaseHas('products', [
            'sku' => 'UNIT-INHERIT-1',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'company_id' => $this->company->id,
        ]);
    }

    public function test_category_update_cascades_unit_to_products(): void
    {
        $oldUnit = Unit::factory()->create(['company_id' => $this->company->id]);
        $newUnit = Unit::factory()->create(['company_id' => $this->company->id]);
        $category = Category::factory()->create([
            'company_id' => $this->company->id,
            'unit_id' => $oldUnit->id,
        ]);
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'category_id' => $category->id,
            'unit_id' => $oldUnit->id,
        ]);

        $this->actingAs($this->admin)->put(route('admin.categories.update', $category), [
            'name' => $category->name,
            'code' => $category->code,
            'unit_id' => $newUnit->id,
            'description' => $category->description,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'unit_id' => $newUnit->id,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'unit_id' => $newUnit->id,
        ]);
    }

    public function test_category_store_requires_unit(): void
    {
        $this->actingAs($this->admin)->post(route('admin.categories.store'), [
            'name' => 'No Unit Category',
            'code' => 'NOUNIT',
            'is_active' => true,
        ])->assertSessionHasErrors(['unit_id']);
    }
}
