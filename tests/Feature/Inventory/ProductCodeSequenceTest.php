<?php

namespace Tests\Feature\Inventory;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Tests\TestCase;
use Tests\Traits\AllowsManualCodes;

class ProductCodeSequenceTest extends TestCase
{
    use AllowsManualCodes;
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private Category $category;

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
        $unit = Unit::factory()->create(['company_id' => $this->company->id]);
        $this->category = Category::factory()->create(['company_id' => $this->company->id, 'unit_id' => $unit->id]);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return ['name' => 'Kabel FO', 'category_id' => $this->category->id, ...$overrides];
    }

    public function test_a_typed_sku_is_kept_by_default(): void
    {
        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->payload(['sku' => 'FO-CABLE-1']))
            ->assertRedirect(route('admin.products.index'));

        $this->assertDatabaseHas('products', ['company_id' => $this->company->id, 'sku' => 'FO-CABLE-1']);
    }

    public function test_an_empty_sku_gets_the_configured_format(): void
    {
        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->payload())
            ->assertRedirect(route('admin.products.index'));

        $this->assertDatabaseHas('products', ['company_id' => $this->company->id, 'sku' => 'PRD-00001']);
    }

    public function test_a_typed_sku_is_rejected_while_manual_input_is_off(): void
    {
        $this->setManualCodes($this->company->id, false, 'product');

        $this->actingAs($this->admin)->post(route('admin.products.store'), $this->payload(['sku' => 'FO-CABLE-1']))
            ->assertSessionHasErrors(['sku']);

        $this->assertDatabaseMissing('products', ['sku' => 'FO-CABLE-1']);
    }

    public function test_update_ignores_the_sku_while_manual_input_is_off(): void
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'category_id' => $this->category->id, 'unit_id' => $this->category->unit_id, 'sku' => 'KEEP-1']);
        $this->setManualCodes($this->company->id, false, 'product');

        $this->actingAs($this->admin)->put(route('admin.products.update', $product), $this->payload(['sku' => 'CHANGED-1', 'name' => 'Renamed']))
            ->assertRedirect(route('admin.products.index'));

        $product->refresh();
        $this->assertSame('KEEP-1', $product->sku);
        $this->assertSame('Renamed', $product->name);
    }

    public function test_update_changes_the_sku_when_manual_input_is_on(): void
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'category_id' => $this->category->id, 'unit_id' => $this->category->unit_id, 'sku' => 'OLD-1']);

        $this->actingAs($this->admin)->put(route('admin.products.update', $product), $this->payload(['sku' => 'NEW-1']))
            ->assertRedirect(route('admin.products.index'));

        $this->assertSame('NEW-1', $product->fresh()->sku);
    }

    public function test_create_and_edit_pages_expose_the_code_sequence(): void
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'category_id' => $this->category->id, 'unit_id' => $this->category->unit_id]);

        $this->actingAs($this->admin)->get(route('admin.products.create'))
            ->assertInertia(fn (Assert $page) => $page->where('codeSequence.manual', true)->where('codeSequence.preview', 'PRD-00001'));
        $this->actingAs($this->admin)->get(route('admin.products.edit', $product))
            ->assertInertia(fn (Assert $page) => $page->where('codeSequence.manual', true)->has('codeSequence.preview'));
    }
}
