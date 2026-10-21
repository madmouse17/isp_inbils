<?php

namespace Tests\Traits;

use App\Services\Core\CompanyService;
use Illuminate\Support\Facades\DB;

/**
 * For tests of the role migrations (ADR-004), which have to see the state before the cutover: global roles, that is
 * roles without a company. `roles.company_id` is required after the migrations, so a scenario first makes it nullable.
 * That is DDL, and MySQL commits on DDL: the transaction RefreshDatabase opened is gone. The scenario therefore runs in
 * a transaction of its own, which is rolled back before the column becomes required again, so nothing it writes stays.
 */
trait RunsRoleMigrations
{
    /** Runs $scenario with `roles.company_id` nullable, inside a transaction that is rolled back. */
    private function underLegacyRoles(callable $scenario): void
    {
        $requireCompany = $this->migration('2026_10_29_100500_require_company_on_roles.php');
        $requireCompany->down();

        // The DDL committed the transaction RefreshDatabase opened: align the counter with the connection, then open a real one.
        DB::rollBack();
        DB::beginTransaction();

        try {
            $scenario();
        } finally {
            DB::rollBack();
            CompanyService::resetCache();
            $requireCompany->up();
        }
    }

    /** The migration object of a file in database/migrations: an anonymous class with up() and down(). */
    private function migration(string $file): object
    {
        return require database_path('migrations/'.$file);
    }
}
