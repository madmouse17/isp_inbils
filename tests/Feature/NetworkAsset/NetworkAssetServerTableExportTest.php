<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\NetworkAsset\Models\NetworkAsset;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NetworkAssetServerTableExportTest extends TestCase
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

    private function makeAsset(int $companyId, string $code, string $name, string $status = 'available'): NetworkAsset
    {
        return NetworkAsset::query()->forceCreate([
            'company_id' => $companyId,
            'code' => $code,
            'name' => $name,
            'asset_type' => 'router',
            'serial_number' => $code.'-SN',
            'status' => $status,
            'ownership' => 'owned',
        ]);
    }

    public function test_index_is_company_scoped_and_paginated(): void
    {
        foreach (range(1, 11) as $number) {
            $this->makeAsset((int) $this->admin->company_id, "AST-{$number}", "Asset {$number}");
        }

        $this->makeAsset((int) $this->otherCompany->id, 'AST-OTHER', 'Foreign Asset');

        $this->actingAs($this->admin)
            ->get(route('admin.network-assets.index', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/NetworkAssets/Index')
                ->has('assets.data', 1)
                ->where('assets.meta.current_page', 2)
                ->where('assets.meta.last_page', 2)
                ->where('can.export', true)
            );
    }

    public function test_export_csv_respects_filters_and_company_scope(): void
    {
        $this->makeAsset((int) $this->admin->company_id, 'AST-CSV-1', 'Export Router');
        $this->makeAsset((int) $this->admin->company_id, 'AST-CSV-2', 'Skip Router', 'maintenance');
        $this->makeAsset((int) $this->otherCompany->id, 'AST-CSV-OTHER', 'Export Router');

        $response = $this->actingAs($this->admin)
            ->get(route('admin.network-assets.export', [
                'format' => 'csv',
                'search' => 'Export Router',
                'status' => 'available',
            ]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('AST-CSV-1', $content);
        $this->assertStringNotContainsString('AST-CSV-2', $content);
        $this->assertStringNotContainsString('AST-CSV-OTHER', $content);
    }

    public function test_export_forbidden_without_permission(): void
    {
        $user = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $role = Role::firstOrCreate(['name' => 'network-asset-view-only', 'guard_name' => 'web']);
        Permission::findOrCreate('network_asset.view', 'web');
        $role->syncPermissions(['network_asset.view']);
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(route('admin.network-assets.export', ['format' => 'csv']))
            ->assertForbidden();
    }

    public function test_index_sort_allowlist_and_export_sort_parity(): void
    {
        $this->makeAsset((int) $this->admin->company_id, 'AST-B', 'Bravo');
        $this->makeAsset((int) $this->admin->company_id, 'AST-A', 'Alpha');
        $this->makeAsset((int) $this->admin->company_id, 'AST-C', 'Charlie');

        $this->actingAs($this->admin)
            ->get(route('admin.network-assets.index', [
                'sort' => 'code',
                'direction' => 'asc',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/NetworkAssets/Index')
                ->where('assets.data.0.code', 'AST-A')
                ->where('assets.data.1.code', 'AST-B')
                ->where('assets.data.2.code', 'AST-C')
                ->where('filters.sort', 'code')
                ->where('filters.direction', 'asc')
            );

        $this->actingAs($this->admin)
            ->get(route('admin.network-assets.index', [
                'sort' => 'drop table assets;--',
                'direction' => 'sideways',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/NetworkAssets/Index')
                ->where('filters.sort', 'drop table assets;--')
                ->where('filters.direction', 'sideways')
            );

        $csv = $this->actingAs($this->admin)
            ->get(route('admin.network-assets.export', [
                'format' => 'csv',
                'sort' => 'code',
                'direction' => 'asc',
            ]));

        $csv->assertOk();
        $content = $csv->streamedContent();
        $posA = strpos($content, 'AST-A');
        $posB = strpos($content, 'AST-B');
        $posC = strpos($content, 'AST-C');
        $this->assertNotFalse($posA);
        $this->assertNotFalse($posB);
        $this->assertNotFalse($posC);
        $this->assertTrue($posA < $posB && $posB < $posC);

        $invalid = $this->actingAs($this->admin)
            ->get(route('admin.network-assets.export', [
                'format' => 'csv',
                'sort' => 'drop table assets;--',
                'direction' => 'sideways',
            ]));
        $invalid->assertOk();
        $this->assertStringContainsString('text/csv', (string) $invalid->headers->get('content-type'));
    }
}
