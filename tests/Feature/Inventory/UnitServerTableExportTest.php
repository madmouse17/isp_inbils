<?php

namespace Tests\Feature\Inventory;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Inventory\Models\Unit;
use Tests\TestCase;

class UnitServerTableExportTest extends TestCase
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
        Unit::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Meter Own',
            'symbol' => 'm',
        ]);
        Unit::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Kilogram Own',
            'symbol' => 'kg',
        ]);
        Unit::factory()->create([
            'company_id' => $this->otherCompany->id,
            'name' => 'Meter Other',
            'symbol' => 'mo',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.units.index', ['search' => 'Meter']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Inventory/Units/Index')
                ->has('units.data', 1)
                ->where('units.data.0.name', 'Meter Own')
                ->where('filters.search', 'Meter')
            );
    }

    public function test_export_csv_respects_filters_and_company_scope(): void
    {
        Unit::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Export Unit',
            'symbol' => 'eu',
        ]);
        Unit::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Skip Unit',
            'symbol' => 'su',
        ]);
        Unit::factory()->create([
            'company_id' => $this->otherCompany->id,
            'name' => 'Export Unit',
            'symbol' => 'xo',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.units.export', [
                'format' => 'csv',
                'search' => 'Export',
            ]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('Export Unit', $content);
        $this->assertStringContainsString('eu', $content);
        $this->assertStringNotContainsString('Skip Unit', $content);
        $this->assertStringNotContainsString('xo', $content);
    }

    public function test_export_pdf_returns_pdf(): void
    {
        Unit::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Pdf Unit',
            'symbol' => 'pu',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.units.export', ['format' => 'pdf']));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
    }
}
