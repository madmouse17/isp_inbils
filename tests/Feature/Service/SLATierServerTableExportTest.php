<?php

namespace Tests\Feature\Service;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Service\Models\SLATier;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SLATierServerTableExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $company = Company::factory()->create(['is_active' => true]);

        $this->admin = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');
    }

    public function test_index_honors_page_size_sorted_boundaries_and_preserves_query(): void
    {
        foreach (range(1, 26) as $number) {
            SLATier::factory()->create([
                'company_id' => $this->admin->company_id,
                'name' => sprintf('Contract %02d', $number),
            ]);
        }
        SLATier::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Excluded Local']);
        SLATier::factory()->create(['company_id' => Company::factory()->create()->id, 'name' => 'Contract Foreign']);
        $filters = ['search' => 'Contract', 'sort' => 'name', 'direction' => 'desc', 'per_page' => 25];

        $this->actingAs($this->admin)->get(route('admin.sla-tiers.index', $filters))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Service/SLATiers/Index')
            ->has('slaTiers.data', 25)
            ->where('slaTiers.meta.total', 26)
            ->where('slaTiers.meta.per_page', 25)
            ->where('slaTiers.data.0.name', 'Contract 26')
            ->where('slaTiers.data.24.name', 'Contract 02')
            ->where('filters.per_page', '25')
            ->where('filters.search', 'Contract')
            ->where('filters.sort', 'name')
            ->where('filters.direction', 'desc')
            ->where('slaTiers.links.next', function ($url) use ($filters): bool {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);

                return $query == array_merge($filters, ['page' => 2]);
            })
            );
        $this->get(route('admin.sla-tiers.index', array_merge($filters, ['page' => 2])))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('slaTiers.data', 1)
            ->where('slaTiers.data.0.name', 'Contract 01')
            ->where('slaTiers.meta.current_page', 2)
            );
        $this->get(route('admin.sla-tiers.index', array_merge($filters, ['per_page' => 7])))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('slaTiers.data', 10)
            ->where('slaTiers.meta.per_page', 10)
            ->where('slaTiers.data.9.name', 'Contract 17')
            );
        $content = $this->get(route('admin.sla-tiers.export', array_merge($filters, ['format' => 'csv', 'page' => 2])))
            ->assertOk()->streamedContent();
        foreach (range(1, 26) as $number) {
            $this->assertStringContainsString(sprintf('Contract %02d', $number), $content);
        }
        $this->assertStringNotContainsString('Excluded Local', $content);
        $this->assertStringNotContainsString('Contract Foreign', $content);
        $this->assertLessThan(strpos($content, 'Contract 01'), strpos($content, 'Contract 26'));
    }

    public function test_export_csv_streams_filtered_data(): void
    {
        SLATier::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'SLA Export Tier',
        ]);
        SLATier::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'SLA Skip Tier',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.sla-tiers.export', [
                'format' => 'csv',
                'search' => 'Export',
            ]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('SLA Export Tier', $content);
        $this->assertStringNotContainsString('SLA Skip Tier', $content);
    }

    public function test_export_pdf_returns_pdf(): void
    {
        SLATier::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'SLA PDF Tier',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.sla-tiers.export', ['format' => 'pdf']));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
    }

    public function test_export_forbidden_without_permission(): void
    {
        $user = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $role = Role::firstOrCreate(['name' => 'sla-no-export', 'guard_name' => 'web']);
        Permission::findOrCreate('service.view', 'web');
        $role->syncPermissions(['service.view']);
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(route('admin.sla-tiers.export', ['format' => 'csv']))
            ->assertForbidden();
    }
}
