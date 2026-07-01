<?php

namespace App\Listeners;

use App\Events\CompanyCreated;
use Database\Seeders\CompanySeeder;

class SeedCompanyDefaults
{
    public function handle(CompanyCreated $event): void
    {
        app(CompanySeeder::class)->runFor($event->company);
    }
}
