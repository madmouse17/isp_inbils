<?php

namespace App\Support\Platform;

use App\Models\Core\Company;
use App\Models\Role;
use App\Support\RecordNotFound;

/**
 * Finds the companies and roles of ONE tenant for the platform panel, which has no tenant context: the company scope
 * of the role model hides every row there. Each lookup is bounded to the head and its subsidiaries, so a company or
 * a role of another tenant answers 404 like a record that does not exist.
 */
final class TenantRoles
{
    /** @return list<int> the head's id first, then its subsidiaries' */
    public static function companyIds(Company $root): array
    {
        return [$root->id, ...Company::query()->where('parent_company_id', $root->id)->orderBy('id')->pluck('id')->all()];
    }

    /** The company a public id names when it is the head or one of its subsidiaries; null otherwise. */
    public static function company(Company $root, mixed $publicId): ?Company
    {
        return Company::query()->wherePublicId($publicId)->whereIn('id', self::companyIds($root))->first();
    }

    /** @throws RecordNotFound */
    public static function role(Company $root, mixed $publicId): Role
    {
        return Role::withoutCompany()
            ->wherePublicId($publicId)
            ->whereIn('company_id', self::companyIds($root))
            ->where('guard_name', 'web')
            ->first() ?? RecordNotFound::abort();
    }
}
