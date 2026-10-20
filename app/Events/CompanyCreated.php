<?php

namespace App\Events;

use App\Models\Core\Company;
use Database\Seeders\CompanySeeder;
use Illuminate\Foundation\Events\Dispatchable;

class CompanyCreated
{
    use Dispatchable;

    /** @param  string  $catalog  what the defaults seeder gives the company, one of CompanySeeder::CATALOGS */
    public function __construct(
        public readonly Company $company,
        public readonly string $catalog = CompanySeeder::CATALOG_STARTER,
    ) {}
}
