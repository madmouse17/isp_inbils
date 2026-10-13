<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PermissionRenameMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_17_100000_rename_two_factor_reset_and_document_export_permissions.php';

    public function test_a_role_keeps_its_grant_under_the_new_name_and_down_restores_the_old_one(): void
    {
        $migration = require database_path('migrations/'.self::MIGRATION);
        $reset = Permission::findOrCreate('user.two_factor.reset', 'web');
        $export = Permission::findOrCreate('document.export', 'web');
        $role = Role::findOrCreate('rename-probe', 'web');
        $role->givePermissionTo([$reset, $export]);

        $migration->up();

        $this->assertSame('users.two_factor.reset', $reset->fresh()->name);
        $this->assertSame('system.document.export', $export->fresh()->name);
        $this->assertNull(Permission::query()->where('name', 'user.two_factor.reset')->first());
        $role = Role::findByName('rename-probe', 'web');
        $this->assertTrue($role->hasPermissionTo('users.two_factor.reset'));
        $this->assertTrue($role->hasPermissionTo('system.document.export'));

        $migration->down();

        $this->assertSame('user.two_factor.reset', $reset->fresh()->name);
        $this->assertSame('document.export', $export->fresh()->name);
        $this->assertTrue(Role::findByName('rename-probe', 'web')->hasPermissionTo('document.export'));
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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("'users.two_factor.reset' already exists");

        $migration->up();
    }
}
