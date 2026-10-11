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

    public function test_index_honors_page_size_sorted_boundaries_and_preserves_query(): void
    {
        foreach (range(1, 26) as $number) {
            SpeedProfile::factory()->create([
                'company_id' => $this->admin->company_id,
                'name' => sprintf('Contract %02d', $number),
            ]);
        }
        SpeedProfile::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Excluded Local']);
        SpeedProfile::factory()->create(['company_id' => Company::factory()->create()->id, 'name' => 'Contract Foreign']);
        $filters = ['search' => 'Contract', 'sort' => 'name', 'direction' => 'desc', 'per_page' => 25];

        $this->actingAs($this->admin)->get(route('admin.speed-profiles.index', $filters))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Service/SpeedProfiles/Index')
            ->has('speedProfiles.data', 25)
            ->where('speedProfiles.meta.total', 26)
            ->where('speedProfiles.meta.per_page', 25)
            ->where('speedProfiles.data.0.name', 'Contract 26')
            ->where('speedProfiles.data.24.name', 'Contract 02')
            ->where('filters.per_page', '25')
            ->where('filters.search', 'Contract')
            ->where('filters.sort', 'name')
            ->where('filters.direction', 'desc')
            ->where('speedProfiles.links.next', function ($url) use ($filters): bool {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);

                return $query == array_merge($filters, ['page' => 2]);
            })
            );
        $this->get(route('admin.speed-profiles.index', array_merge($filters, ['page' => 2])))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('speedProfiles.data', 1)
            ->where('speedProfiles.data.0.name', 'Contract 01')
            ->where('speedProfiles.meta.current_page', 2)
            );
        $this->get(route('admin.speed-profiles.index', array_merge($filters, ['per_page' => 7])))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('speedProfiles.data', 10)
            ->where('speedProfiles.meta.per_page', 10)
            ->where('speedProfiles.data.9.name', 'Contract 17')
            );
        $content = $this->get(route('admin.speed-profiles.export', array_merge($filters, ['format' => 'csv', 'page' => 2])))
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
