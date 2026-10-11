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

    public function test_index_honors_page_size_sorted_boundaries_and_preserves_query(): void
    {
        foreach (range(1, 26) as $number) {
            BandwidthProfile::factory()->create([
                'company_id' => $this->admin->company_id,
                'name' => sprintf('Contract %02d', $number),
            ]);
        }
        BandwidthProfile::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Excluded Local']);
        BandwidthProfile::factory()->create(['company_id' => Company::factory()->create()->id, 'name' => 'Contract Foreign']);
        $filters = ['search' => 'Contract', 'sort' => 'name', 'direction' => 'desc', 'per_page' => 25];

        $this->actingAs($this->admin)->get(route('admin.bandwidth-profiles.index', $filters))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Service/BandwidthProfiles/Index')
            ->has('bandwidthProfiles.data', 25)
            ->where('bandwidthProfiles.meta.total', 26)
            ->where('bandwidthProfiles.meta.per_page', 25)
            ->where('bandwidthProfiles.data.0.name', 'Contract 26')
            ->where('bandwidthProfiles.data.24.name', 'Contract 02')
            ->where('filters.per_page', '25')
            ->where('filters.search', 'Contract')
            ->where('filters.sort', 'name')
            ->where('filters.direction', 'desc')
            ->where('bandwidthProfiles.links.next', function ($url) use ($filters): bool {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);

                return $query == array_merge($filters, ['page' => 2]);
            })
            );
        $this->get(route('admin.bandwidth-profiles.index', array_merge($filters, ['page' => 2])))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('bandwidthProfiles.data', 1)
            ->where('bandwidthProfiles.data.0.name', 'Contract 01')
            ->where('bandwidthProfiles.meta.current_page', 2)
            );
        $this->get(route('admin.bandwidth-profiles.index', array_merge($filters, ['per_page' => 7])))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('bandwidthProfiles.data', 10)
            ->where('bandwidthProfiles.meta.per_page', 10)
            ->where('bandwidthProfiles.data.9.name', 'Contract 17')
            );
        $content = $this->get(route('admin.bandwidth-profiles.export', array_merge($filters, ['format' => 'csv', 'page' => 2])))
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
