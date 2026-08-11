<?php

namespace Tests\Feature\Service;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Service\Models\SpeedProfile;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SpeedProfileServerTableExportTest extends TestCase
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
        SpeedProfile::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Fast Profile',
        ]);
        SpeedProfile::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Slow Profile',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.speed-profiles.index', ['search' => 'Fast']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Service/SpeedProfiles/Index')
                ->has('speedProfiles.data', 1)
                ->where('speedProfiles.data.0.name', 'Fast Profile')
                ->where('can.export', true)
            );
    }

    public function test_export_csv_streams_filtered_data(): void
    {
        SpeedProfile::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Speed Export Profile',
        ]);
        SpeedProfile::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Speed Skip Profile',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.speed-profiles.export', [
                'format' => 'csv',
                'search' => 'Export',
            ]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('Speed Export Profile', $content);
        $this->assertStringNotContainsString('Speed Skip Profile', $content);
    }

    public function test_export_pdf_returns_pdf(): void
    {
        SpeedProfile::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Speed PDF Profile',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.speed-profiles.export', ['format' => 'pdf']));

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
        $role = Role::firstOrCreate(['name' => 'speed-no-export', 'guard_name' => 'web']);
        Permission::findOrCreate('service.view', 'web');
        $role->syncPermissions(['service.view']);
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(route('admin.speed-profiles.export', ['format' => 'csv']))
            ->assertForbidden();
    }
}
