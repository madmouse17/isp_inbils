<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Permissions are global: one catalogue in code (PermissionCatalogue). The roles that hold one belong to many
 * companies, so this relation lifts the company scope of App\Models\Role.
 */
class Permission extends SpatiePermission
{
    public function roles(): BelongsToMany
    {
        return parent::roles()->withoutGlobalScope('company');
    }
}
