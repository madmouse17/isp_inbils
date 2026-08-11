<?php

namespace Tests\Feature\Service;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Service\Models\BandwidthProfile;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BandwidthProfileServerTableExportTest extends TestCase
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
        BandwidthProfile::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Premium BW',
        ]);
        BandwidthProfile::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Basic BW',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.bandwidth-profiles.index', ['search' => 'Premium']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Service/BandwidthProfiles/Index')
                ->has('bandwidthProfiles.data', 1)
                ->where('bandwidthProfiles.data.0.name', 'Premium BW')
                ->where('can.export', true)
            );
    }

    public function test_export_csv_streams_filtered_data(): void
    {
        BandwidthProfile::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'BW Export Profile',
        ]);
        BandwidthProfile::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'BW Skip Profile',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.bandwidth-profiles.export', [
                'format' => 'csv',
                'search' => 'Export',
            ]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('BW Export Profile', $content);
        $this->assertStringNotContainsString('BW Skip Profile', $content);
    }

    public function test_export_pdf_returns_pdf(): void
    {
        BandwidthProfile::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'BW PDF Profile',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.bandwidth-profiles.export', ['format' => 'pdf']));

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
        $role = Role::firstOrCreate(['name' => 'bw-no-export', 'guard_name' => 'web']);
        Permission::findOrCreate('service.view', 'web');
        $role->syncPermissions(['service.view']);
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(route('admin.bandwidth-profiles.export', ['format' => 'csv']))
            ->assertForbidden();
    }
}
