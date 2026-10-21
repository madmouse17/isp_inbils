<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\PermissionRegistrar;

/**
 * The package caches every permission with the roles that hold it in ONE entry (ADR-004: roles belong to companies,
 * the cache does not). The query that fills it must therefore lift the company scope of App\Models\Role: otherwise
 * the entry would hold the roles of whichever company's request filled it, and every other company would lose its
 * role grants until the next flush. App\Models\Permission lifts the scope in its own relation; this covers the
 * package's Permission class too, because a static finder on it (Permission::findOrCreate) swaps the registrar's
 * class, and a database where `roles.company_id` does not exist yet (the migrations before it).
 *
 * shortcut: one global permission cache, upgrade to per-company cache keys (a registrar per company) past ~300 tenants
 * or a cache entry over 2 MB. It holds about 500 role grants per company with the eight templates.
 */
class CompanyPermissionRegistrar extends PermissionRegistrar
{
    protected function getPermissionsWithRoles(): Collection
    {
        return $this->permissionClass::select()
            ->with(['roles' => fn ($roles) => $roles->withoutGlobalScope('company')])
            ->get();
    }
}
