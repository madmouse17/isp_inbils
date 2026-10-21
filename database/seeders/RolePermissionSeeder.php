<?php

namespace Database\Seeders;

use App\Support\PermissionCatalogue;
use Illuminate\Database\Seeder;

/**
 * Installs the permission catalogue and the default role templates a company copies when it is created (ADR-004).
 * It creates no role and never changes a company's roles or an existing template: roles belong to companies, and a
 * deploy never resyncs them. For fresh installs and tests; a release that adds a permission does it in a migration
 * with PermissionCatalogue::release(), and nobody runs db:seed on a live database.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        PermissionCatalogue::install();
    }
}
