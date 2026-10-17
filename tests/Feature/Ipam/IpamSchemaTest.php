<?php

namespace Tests\Feature\Ipam;

use App\Support\Database\PublicIdColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Creates no rows: the migrations are rolled back and re-applied, and a DDL statement commits the
 * RefreshDatabase transaction, so nothing may be written here.
 */
class IpamSchemaTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> table => migration, in the order they run */
    private const MIGRATIONS = [
        'vlans' => 'Modules/Ipam/database/migrations/2026_10_13_100001_create_vlans_table.php',
        'ip_prefixes' => 'Modules/Ipam/database/migrations/2026_10_13_100002_create_ip_prefixes_table.php',
        'ip_addresses' => 'Modules/Ipam/database/migrations/2026_10_13_100003_create_ip_addresses_table.php',
    ];

    /** @return array<string, array<string, mixed>> table => migration object */
    private function migrations(): array
    {
        return array_map(fn (string $file): object => require base_path($file), self::MIGRATIONS);
    }

    /** @return array<string, list<string>> unique index name => columns */
    private function uniqueIndexes(string $table): array
    {
        $indexes = [];

        foreach (DB::select("SHOW INDEX FROM `{$table}`") as $row) {
            if ((int) $row->Non_unique === 0 && $row->Key_name !== 'PRIMARY') {
                $indexes[$row->Key_name][(int) $row->Seq_in_index] = $row->Column_name;
            }
        }

        return array_map(fn (array $columns): array => array_values($columns), $indexes);
    }

    public function test_the_migrations_roll_back_and_apply_again(): void
    {
        $migrations = $this->migrations();

        try {
            foreach (array_reverse($migrations, true) as $table => $migration) {
                $migration->down();
                $this->assertFalse(Schema::hasTable($table), "{$table} should be gone after down()");
            }

            foreach ($migrations as $table => $migration) {
                $migration->up();
                $this->assertTrue(Schema::hasTable($table), "{$table} should exist after up()");
            }
        } finally {
            foreach ($migrations as $table => $migration) {
                if (! Schema::hasTable($table)) {
                    $migration->up();
                }
            }

            PublicIdColumn::add(array_keys(self::MIGRATIONS));
        }
    }

    public function test_the_unique_indexes_that_guard_conflicts_exist(): void
    {
        $this->assertContains(['company_id', 'cidr'], array_values($this->uniqueIndexes('ip_prefixes')));
        $this->assertContains(['company_id', 'address_cmp'], array_values($this->uniqueIndexes('ip_addresses')));
        $this->assertContains(['subscription_id', 'family'], array_values($this->uniqueIndexes('ip_addresses')));
        $this->assertContains(['company_id', 'location_id', 'vid'], array_values($this->uniqueIndexes('vlans')));
    }

    public function test_comparable_columns_are_binary_ascii(): void
    {
        $collations = fn (string $table): array => collect(DB::select("SHOW FULL COLUMNS FROM `{$table}`"))->pluck('Collation', 'Field')->all();

        $this->assertSame('ascii_bin', $collations('ip_addresses')['address_cmp']);
        $this->assertSame('ascii_bin', $collations('ip_prefixes')['range_from']);
        $this->assertSame('ascii_bin', $collations('ip_prefixes')['range_to']);
    }

    public function test_the_range_lookup_index_exists(): void
    {
        $columns = collect(DB::select('SHOW INDEX FROM `ip_prefixes`'))
            ->where('Key_name', 'ip_prefixes_company_id_family_range_from_index')
            ->sortBy('Seq_in_index')
            ->pluck('Column_name')
            ->all();

        $this->assertSame(['company_id', 'family', 'range_from'], $columns);
    }
}
