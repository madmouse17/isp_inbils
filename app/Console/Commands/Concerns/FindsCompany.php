<?php

namespace App\Console\Commands\Concerns;

use App\Models\Core\Company;

trait FindsCompany
{
    /** A company by code first, then by numeric id. */
    private function findCompany(string $idOrCode): ?Company
    {
        return Company::query()->where('code', $idOrCode)->first()
            ?? (ctype_digit($idOrCode) ? Company::query()->find((int) $idOrCode) : null);
    }
}
