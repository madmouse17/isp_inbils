<?php

namespace App\Models;

use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A starter role the platform maintains (ADR-004), copied into every company when it is created. It belongs to no
 * company: nothing here ever changes a company's roles after the copy. `key` is immutable (the copy's
 * `roles.template_key` points at it), so only the service sets it, with forceFill().
 *
 * @property int $id
 * @property string $key
 * @property string $name
 */
class RoleTemplate extends Model
{
    use HasPublicId;

    protected $fillable = ['name'];

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_template_has_permissions', 'role_template_id', 'permission_id');
    }
}
