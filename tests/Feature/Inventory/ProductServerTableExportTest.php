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

class ProductServerTableExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $otherCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $company = Company::factory()->create(['is_active' => true]);
        $this->otherCompany = Company::factory()->create(['is_active' => true]);

        $this->admin = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');
    }

    public function test_index_is_company_scoped_and_filterable(): void
    {
        $unit = Unit::factory()->create(['company_id' => $this->admin->company_id]);
        $category = Category::factory()->create([
            'company_id' => $this->admin->company_id,
            'unit_id' => $unit->id,
        ]);

        Product::factory()->create([
            'company_id' => $this->admin->company_id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku' => 'SKU-OWN-1',
            'name' => 'Fiber Cable',
            'is_active' => true,
        ]);
        Product::factory()->create([
            'company_id' => $this->admin->company_id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku' => 'SKU-OWN-2',
            'name' => 'Router Box',
            'is_active' => true,
        ]);
        Product::factory()->create([
            'company_id' => $this->otherCompany->id,
            'sku' => 'SKU-OTHER',
            'name' => 'Fiber Cable',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.products.index', ['search' => 'Fiber']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Inventory/Products/Index')
                ->has('products.data', 1)
                ->where('products.data.0.sku', 'SKU-OWN-1')
                ->where('filters.search', 'Fiber')
            );
    }

    public function test_export_csv_respects_filters_and_company_scope(): void
    {
        $unit = Unit::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Meter',
            'symbol' => 'm',
        ]);
        $category = Category::factory()->create([
            'company_id' => $this->admin->company_id,
            'unit_id' => $unit->id,
            'name' => 'Cables',
        ]);

        Product::factory()->create([
            'company_id' => $this->admin->company_id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku' => 'SKU-CSV-1',
            'name' => 'Export Product',
            'is_active' => true,
            'min_stock' => 5,
            'type' => 'consumable',
        ]);
        Product::factory()->create([
            'company_id' => $this->admin->company_id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku' => 'SKU-CSV-2',
            'name' => 'Skip Product',
            'is_active' => true,
        ]);
        Product::factory()->create([
            'company_id' => $this->otherCompany->id,
            'sku' => 'SKU-CSV-OTHER',
            'name' => 'Export Product',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.products.export', [
                'format' => 'csv',
                'search' => 'Export',
            ]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('SKU-CSV-1', $content);
        $this->assertStringContainsString('Export Product', $content);
        $this->assertStringNotContainsString('SKU-CSV-2', $content);
        $this->assertStringNotContainsString('SKU-CSV-OTHER', $content);
    }

    public function test_export_pdf_returns_pdf(): void
    {
        $unit = Unit::factory()->create(['company_id' => $this->admin->company_id]);
        $category = Category::factory()->create([
            'company_id' => $this->admin->company_id,
            'unit_id' => $unit->id,
        ]);
        Product::factory()->create([
            'company_id' => $this->admin->company_id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku' => 'SKU-PDF-1',
            'name' => 'Pdf Product',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.products.export', ['format' => 'pdf']));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
    }
}
