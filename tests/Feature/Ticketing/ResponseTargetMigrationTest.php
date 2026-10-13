<?php

declare(strict_types=1);

namespace Tests\Feature\Ticketing;

use App\Models\Core\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** MySQL commits on DDL: each test puts the columns back before it ends and removes the rows it inserted. */
class ResponseTargetMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TICKETS = 'Modules/Ticketing/database/migrations/2026_10_14_110000_add_response_deadline_to_tickets_table.php';

    private const CATEGORIES = 'Modules/Ticketing/database/migrations/2026_10_14_110001_add_default_response_hours_to_ticket_categories_table.php';

    public function test_the_response_deadline_column_and_its_index_drop_and_return(): void
    {
        $migration = require base_path(self::TICKETS);

        try {
            $this->assertTrue(Schema::hasColumn('tickets', 'response_deadline'));
            $this->assertTrue(Schema::hasIndex('tickets', ['company_id', 'response_deadline']));

            $migration->down();
            $this->assertFalse(Schema::hasColumn('tickets', 'response_deadline'));
            $this->assertFalse(Schema::hasIndex('tickets', ['company_id', 'response_deadline']));

            $migration->up();
            $this->assertTrue(Schema::hasColumn('tickets', 'response_deadline'));
            $this->assertTrue(Schema::hasIndex('tickets', ['company_id', 'response_deadline']));
        } finally {
            if (! Schema::hasColumn('tickets', 'response_deadline')) {
                $migration->up();
            }
        }
    }

    public function test_default_response_hours_is_half_the_resolution_hours_rounded_up_for_old_and_new_rows(): void
    {
        $migration = require base_path(self::CATEGORIES);
        $company = Company::factory()->create();

        try {
            $migration->down();
            $this->assertFalse(Schema::hasColumn('ticket_categories', 'default_response_hours'));

            // Categories that exist before the column: resolution hours 7, 4 and 1.
            foreach ([7, 4, 1] as $hours) {
                $this->insertCategory($company->id, "OLD-{$hours}", $hours);
            }

            $migration->up();
            $this->assertSame(
                ['OLD-1' => 1, 'OLD-4' => 2, 'OLD-7' => 4],
                $this->responseHours($company->id)->sortKeys()->all(),
            );

            // A row inserted without the column afterwards takes the same default.
            $this->insertCategory($company->id, 'NEW-9', 9);
            $this->assertSame(5, $this->responseHours($company->id)['NEW-9']);

            $migration->down();
            $this->assertFalse(Schema::hasColumn('ticket_categories', 'default_response_hours'));
        } finally {
            if (! Schema::hasColumn('ticket_categories', 'default_response_hours')) {
                $migration->up();
            }
            DB::table('ticket_categories')->where('company_id', $company->id)->delete();
            DB::table('companies')->where('id', $company->id)->delete();
        }
    }

    private function insertCategory(int $companyId, string $code, int $slaHours): void
    {
        DB::table('ticket_categories')->insert([
            'company_id' => $companyId,
            'name' => $code,
            'code' => $code,
            'default_sla_hours' => $slaHours,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return Collection<string, int> response hours by category code */
    private function responseHours(int $companyId): Collection
    {
        return DB::table('ticket_categories')->where('company_id', $companyId)->pluck('default_response_hours', 'code')->map(fn ($hours): int => (int) $hours);
    }
}
