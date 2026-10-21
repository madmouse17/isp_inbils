<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PermissionRenameMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_17_100000_rename_two_factor_reset_and_document_export_permissions.php';

    protected function setUp(): void
    {
        parent::setUp();

        // The catalogue already holds the new names (the role-template migration installed it). The migration under test
        // runs on a database that still has the old ones, so each test starts from there.
        DB::table('permissions')->whereIn('name', ['users.two_factor.reset', 'system.document.export'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_a_role_keeps_its_grant_under_the_new_name_and_down_restores_the_old_one(): void
    {
        $migration = require database_path('migrations/'.self::MIGRATION);
        $company = Company::factory()->create();
        $role = $this->companyRole($company, 'rename-probe', ['user.two_factor.reset', 'document.export']);
        $reset = Permission::query()->where('name', 'user.two_factor.reset')->firstOrFail();
        $export = Permission::query()->where('name', 'document.export')->firstOrFail();

        $migration->up();

        $this->assertSame('users.two_factor.reset', $reset->fresh()->name);
        $this->assertSame('system.document.export', $export->fresh()->name);
        $this->assertNull(Permission::query()->where('name', 'user.two_factor.reset')->first());
        $this->assertTrue($this->holds($company, $role, 'users.two_factor.reset'));
        $this->assertTrue($this->holds($company, $role, 'system.document.export'));

        $migration->down();

        $this->assertSame('user.two_factor.reset', $reset->fresh()->name);
        $this->assertSame('document.export', $export->fresh()->name);
        $this->assertTrue($this->holds($company, $role, 'document.export'));
    }

    public function test_a_missing_source_is_skipped(): void
    {
        $migration = require database_path('migrations/'.self::MIGRATION);
        Permission::findOrCreate('users.two_factor.reset', 'web');

        $migration->up();

        $this->assertSame(1, Permission::query()->where('name', 'users.two_factor.reset')->count());
        $this->assertNull(Permission::query()->where('name', 'user.two_factor.reset')->first());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_it_refuses_when_the_target_name_already_exists(): void
    {
        $migration = require database_path('migrations/'.self::MIGRATION);
        Permission::findOrCreate('user.two_factor.reset', 'web');
        Permission::findOrCreate('users.two_factor.reset', 'web');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("'users.two_factor.reset' already exists");

        $migration->up();
    }

    private function holds(Company $company, Role $role, string $permission): bool
    {
        return CompanyService::runAs($company->id, fn (): bool => Role::query()->whereKey($role->id)->firstOrFail()->hasPermissionTo($permission));
    }
}
