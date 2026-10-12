<?php

namespace Modules\NetworkAsset\Services\Radius;

use App\Models\Core\Company;

/**
 * The RADIUS realm of a company: its code in lower case. FreeRADIUS looks users up by name alone, so every
 * username is `local@realm` and every group `realm/name`. A code that is not a short DNS-like label has no
 * realm: that company cannot register NAS rows or accounts until the code is changed.
 */
final class Realm
{
    public const PATTERN = '/^[a-z0-9-]{1,24}$/';

    public static function fromCode(?string $code): ?string
    {
        $realm = strtolower((string) $code);

        return preg_match(self::PATTERN, $realm) === 1 ? $realm : null;
    }

    public static function forCompany(int $companyId): ?string
    {
        return self::fromCode(Company::query()->whereKey($companyId)->value('code'));
    }
}
