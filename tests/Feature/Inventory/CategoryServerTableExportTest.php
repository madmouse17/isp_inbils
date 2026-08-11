<?php

namespace Tests\Feature\Inventory;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Unit;
use Tests\TestCase;

class CategoryServerTableExportTest extends TestCase
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

        Category::factory()->create([
            'company_id' => $this->admin->company_id,
            'unit_id' => $unit->id,
            'name' => 'Cable Own',
            'code' => 'CBL-OWN',
        ]);
        Category::factory()->create([
            'company_id' => $this->admin->company_id,
            'unit_id' => $unit->id,
            'name' => 'Router Own',
            'code' => 'RTR-OWN',
        ]);
        Category::factory()->create([
            'company_id' => $this->otherCompany->id,
            'name' => 'Cable Other',
            'code' => 'CBL-OTHER',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.categories.index', ['search' => 'Cable']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Inventory/Categories/Index')
                ->has('categories.data', 1)
                ->where('categories.data.0.code', 'CBL-OWN')
                ->where('filters.search', 'Cable')
            );
    }

    public function test_export_csv_respects_filters_and_company_scope(): void
    {
        $unit = Unit::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Piece',
            'symbol' => 'pcs',
        ]);

        Category::factory()->create([
            'company_id' => $this->admin->company_id,
            'unit_id' => $unit->id,
            'name' => 'Export Category',
            'code' => 'EXP-1',
        ]);
        Category::factory()->create([
            'company_id' => $this->admin->company_id,
            'unit_id' => $unit->id,
            'name' => 'Skip Category',
            'code' => 'SKP-1',
        ]);
        Category::factory()->create([
            'company_id' => $this->otherCompany->id,
            'name' => 'Export Category',
            'code' => 'EXP-OTHER',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.categories.export', [
                'format' => 'csv',
                'search' => 'Export',
            ]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('EXP-1', $content);
        $this->assertStringContainsString('Export Category', $content);
        $this->assertStringNotContainsString('SKP-1', $content);
        $this->assertStringNotContainsString('EXP-OTHER', $content);
    }

    public function test_export_pdf_returns_pdf(): void
    {
        Category::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Pdf Category',
            'code' => 'PDF-1',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.categories.export', ['format' => 'pdf']));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
    }
}
