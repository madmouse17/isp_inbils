<?php

namespace Tests\Feature\Service;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Service\Models\ServicePackage;
use Modules\Service\Models\SLATier;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServicePackageServerTableExportTest extends TestCase
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

    private function makePackage(int $companyId, string $code, string $name): ServicePackage
    {
        return ServicePackage::factory()->create([
            'company_id' => $companyId,
            'code' => $code,
            'name' => $name,
        ]);
    }

    public function test_index_paginates_searches_and_honors_sort_allowlist(): void
    {
        foreach (range(1, 11) as $i) {
            $codePrefix = chr(75 - $i + 1);
            $this->makePackage((int) $this->admin->company_id, "PKG-{$codePrefix}".sprintf('%02d', $i), sprintf('Plan %02d', $i));
        }
        $this->makePackage((int) $this->otherCompany->id, 'PKG-OTHER', 'Foreign Plan');

        $this->actingAs($this->admin)
            ->get(route('admin.service-packages.index', ['sort' => 'code', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Service/Packages/Index')
                ->has('servicePackages.data', 10)
                ->where('servicePackages.data.0.code', 'PKG-A11')
                ->where('can.export', true)
            );

        $this->actingAs($this->admin)
            ->get(route('admin.service-packages.index', ['sort' => 'not_a_column']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Service/Packages/Index')
                ->has('servicePackages.data', 10)
                ->where('servicePackages.data.0.code', 'PKG-K01')
                ->where('filters.sort', 'not_a_column')
            );

        $this->actingAs($this->admin)
            ->get(route('admin.service-packages.index', ['sort' => 'code', 'direction' => 'asc', 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Service/Packages/Index')
                ->has('servicePackages.data', 1)
                ->where('servicePackages.meta.current_page', 2)
                ->where('servicePackages.data.0.code', 'PKG-K01')
                ->where('filters.sort', 'code')
                ->where('filters.direction', 'asc')
            );
    }

    public function test_index_and_export_preserve_filtered_page_size_and_sorted_boundaries(): void
    {
        $tier = SLATier::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Contract SLA']);
        $otherTier = SLATier::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Excluded SLA']);
        $attributes = ['company_id' => $this->admin->company_id, 'is_active' => false, 'sla_tier_id' => $tier->id];
        foreach (range(1, 26) as $number) {
            ServicePackage::factory()->create(array_merge($attributes, [
                'code' => sprintf('MATCH-%02d', $number), 'name' => sprintf('Contract %02d', $number),
            ]));
        }
        foreach ([
            ['code' => 'SKIP-ACTIVE', 'is_active' => true],
            ['code' => 'SKIP-TIER', 'sla_tier_id' => $otherTier->id],
            ['code' => 'SKIP-SEARCH', 'name' => 'Unrelated'],
            ['code' => 'SKIP-TENANT', 'company_id' => $this->otherCompany->id],
        ] as $overrides) {
            ServicePackage::factory()->create(array_merge($attributes, ['name' => 'Contract Distractor'], $overrides));
        }
        $filters = [
            'search' => 'Contract', 'is_active' => '0', 'sla_tier_id' => $tier->id,
            'sort' => 'code', 'direction' => 'desc', 'per_page' => 25,
        ];

        $this->actingAs($this->admin)->get(route('admin.service-packages.index', $filters))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('servicePackages.data', 25)
            ->where('servicePackages.meta.total', 26)
            ->where('servicePackages.meta.per_page', 25)
            ->where('servicePackages.data.0.code', 'MATCH-26')
            ->where('servicePackages.data.24.code', 'MATCH-02')
            ->where('filters.per_page', '25')
            ->where('filters.is_active', '0')
            ->where('filters.sla_tier_id', (string) $tier->id)
            ->where('filters.search', 'Contract')
            ->where('filters.sort', 'code')
            ->where('filters.direction', 'desc')
            ->where('servicePackages.links.next', function ($url) use ($filters): bool {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);

                return $query == array_merge($filters, ['page' => 2]);
            })
            );
        $this->get(route('admin.service-packages.index', array_merge($filters, ['page' => 2])))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('servicePackages.data', 1)
            ->where('servicePackages.data.0.code', 'MATCH-01')
            ->where('servicePackages.meta.current_page', 2)
            );
        $this->get(route('admin.service-packages.index', array_merge($filters, ['per_page' => 7])))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('servicePackages.data', 10)
            ->where('servicePackages.meta.per_page', 10)
            ->where('servicePackages.data.9.code', 'MATCH-17')
            );
        $content = $this->get(route('admin.service-packages.export', array_merge($filters, ['format' => 'csv', 'page' => 2])))
            ->assertOk()->streamedContent();
        foreach (range(1, 26) as $number) {
            $this->assertStringContainsString(sprintf('MATCH-%02d', $number), $content);
        }
        foreach (['SKIP-ACTIVE', 'SKIP-TIER', 'SKIP-SEARCH', 'SKIP-TENANT'] as $code) {
            $this->assertStringNotContainsString($code, $content);
        }
        $this->assertLessThan(strpos($content, 'MATCH-01'), strpos($content, 'MATCH-26'));
    }

    public function test_export_csv_streams_filtered_packages(): void
    {
        ServicePackage::factory()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'PKG-EXPORT-CSV',
            'name' => 'Export CSV Plan',
        ]);
        ServicePackage::factory()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'PKG-SKIP-CSV',
            'name' => 'Skip CSV Plan',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.service-packages.export', [
                'format' => 'csv',
                'search' => 'PKG-EXPORT-CSV',
            ]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('PKG-EXPORT-CSV', $content);
        $this->assertStringNotContainsString('PKG-SKIP-CSV', $content);
    }

    public function test_export_pdf_returns_pdf(): void
    {
        ServicePackage::factory()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'PKG-PDF-UNIQUE',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.service-packages.export', ['format' => 'pdf']));

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
        $role = Role::firstOrCreate(['name' => 'service-no-export', 'guard_name' => 'web']);
        Permission::findOrCreate('service.view', 'web');
        $role->syncPermissions(['service.view']);
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(route('admin.service-packages.export', ['format' => 'csv']))
            ->assertForbidden();
    }
}
