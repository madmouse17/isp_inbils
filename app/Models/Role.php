<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use App\Traits\HasPublicId;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A role of one company (ADR-004). The company scope answers for the company in effect (CompanyService::currentId()),
 * and with none nothing matches, so a role of another company is never reachable by accident; code that must read
 * across companies says so with withoutCompany() or forCompany().
 *
 * `company_id` and `template_key` are not mass assignable: only CompanyRoleService sets them, with forceFill().
 * `template_key` names the template the role was copied from. It survives renames and is never edited. The package's
 * assignRole() takes a Role instance without checking its company, so only CompanyRoleService, CustomerService and the
 * seeders assign roles, always from roles resolved in the company or returned by copyTemplates().
 *
 * @property int $company_id
 * @property string|null $template_key
 */
class Role extends SpatieRole
{
    use BelongsToCompany, HasPublicId;

    protected $fillable = ['name', 'guard_name'];

    /** The current company's role copied from the template, or null when the company has none (deleted, or never copied). */
    public static function forTemplate(string $key): ?self
    {
        return static::query()->where('template_key', $key)->first();
    }
}
