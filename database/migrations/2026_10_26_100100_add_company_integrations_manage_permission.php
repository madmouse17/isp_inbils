<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Who may configure the company's own Midtrans, WhatsApp, e-mail and RADIUS accounts: admin only. RolePermissionSeeder
 * lists the same name for fresh installs; this gives a database that is already seeded the permission and the grant.
 */
return new class() extends Migration
{
    private const PERMISSION = 'company.integrations.manage';

    public function up(): void
    {
        $permission = Permission::findOrCreate(self::PERMISSION, 'web');

        Role::query()->where('name', 'admin')->where('guard_name', 'web')->first()?->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', self::PERMISSION)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
