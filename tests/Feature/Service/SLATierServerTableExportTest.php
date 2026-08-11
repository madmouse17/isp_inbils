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

    public function test_index_paginates_and_searches(): void
    {
        SLATier::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Gold Tier',
        ]);
        SLATier::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Silver Tier',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.sla-tiers.index', ['search' => 'Gold']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Service/SLATiers/Index')
                ->has('slaTiers.data', 1)
                ->where('slaTiers.data.0.name', 'Gold Tier')
                ->where('can.export', true)
            );
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
