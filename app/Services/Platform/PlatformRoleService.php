<?php

namespace App\Services\Platform;

use App\Models\PlatformAdmin;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Role definitions are global (spatie teams=false), so the platform panel is their only writer (ADR-003). Every
 * change lands on the `web` guard in one transaction with its audit row: the name and the permission list before
 * and after, since one change reaches the users of every tenant.
 */
class PlatformRoleService
{
    /** The roles RolePermissionSeeder maintains: their names never change and they are never deleted. */
    public const PROTECTED = ['admin', 'manager', 'staff', 'technician', 'customer'];

    public const SEEDED_WARNING = 'Peran bawaan disinkronkan ulang oleh RolePermissionSeeder pada setiap deploy: perubahan hak akses di sini dapat dikembalikan ke bawaan.';

    public function __construct(private readonly PlatformAuditService $audit) {}

    public static function isProtected(Role $role): bool
    {
        return in_array($role->name, self::PROTECTED, true);
    }

    /** @param  list<string>  $permissions */
    public function create(PlatformAdmin $actor, string $name, array $permissions): Role
    {
        return DB::transaction(function () use ($actor, $name, $permissions): Role {
            $role = Role::query()->create(['name' => $name, 'guard_name' => 'web']);
            $role->syncPermissions($permissions);

            $this->audit->platform('role.created', $this->change(null, $name, [], $this->permissionNames($role)), $role, $actor);

            return $role;
        });
    }

    /** @param  list<string>  $permissions */
    public function update(PlatformAdmin $actor, Role $role, string $name, array $permissions): Role
    {
        return DB::transaction(function () use ($actor, $role, $name, $permissions): Role {
            $nameBefore = $role->name;
            $before = $this->permissionNames($role);

            $role->update(['name' => $name]);
            $role->syncPermissions($permissions);

            $this->audit->platform('role.updated', $this->change($nameBefore, $name, $before, $this->permissionNames($role)), $role, $actor);

            return $role;
        });
    }

    public function delete(PlatformAdmin $actor, Role $role): void
    {
        if (self::isProtected($role)) {
            throw new AuthorizationException('Peran bawaan tidak dapat dihapus.');
        }

        DB::transaction(function () use ($actor, $role): void {
            $this->audit->platform('role.deleted', $this->change($role->name, null, $this->permissionNames($role), []), $role, $actor);

            $role->delete();
        });
    }

    /** @return list<string> */
    private function permissionNames(Role $role): array
    {
        return $role->permissions()->pluck('name')->sort()->values()->all();
    }

    /**
     * @param  list<string>  $permissionsBefore
     * @param  list<string>  $permissionsAfter
     * @return array{name_before: ?string, name_after: ?string, permissions_before: list<string>, permissions_after: list<string>}
     */
    private function change(?string $nameBefore, ?string $nameAfter, array $permissionsBefore, array $permissionsAfter): array
    {
        return [
            'name_before' => $nameBefore,
            'name_after' => $nameAfter,
            'permissions_before' => $permissionsBefore,
            'permissions_after' => $permissionsAfter,
        ];
    }
}
