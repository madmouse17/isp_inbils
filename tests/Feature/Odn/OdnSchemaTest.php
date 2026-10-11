<?php

namespace Tests\Feature\Odn;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Creates no rows: the migrations are rolled back and re-applied, and a DDL statement commits the
 * RefreshDatabase transaction, so nothing may be written here.
 */
class OdnSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const SPLITTERS = 'Modules/NetworkAsset/database/migrations/2026_10_13_100004_create_odn_splitters_table.php';

    private const PORTS = 'Modules/NetworkAsset/database/migrations/2026_10_13_100005_create_odn_ports_table.php';

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
        $splitters = require base_path(self::SPLITTERS);
        $ports = require base_path(self::PORTS);

        try {
            $ports->down();
            $this->assertFalse(Schema::hasTable('odn_ports'));
            $this->assertTrue(Schema::hasTable('odn_splitters'), 'the splitters table stays until its own migration is rolled back');

            $splitters->down();
            $this->assertFalse(Schema::hasTable('odn_splitters'));

            $splitters->up();
            $ports->up();
            $this->assertTrue(Schema::hasTable('odn_splitters'));
            $this->assertTrue(Schema::hasTable('odn_ports'));
        } finally {
            if (! Schema::hasTable('odn_splitters')) {
                $splitters->up();
            }

            if (! Schema::hasTable('odn_ports')) {
                $ports->up();
            }
        }
    }

    public function test_the_unique_indexes_that_keep_the_register_a_tree_exist(): void
    {
        $ports = array_values($this->uniqueIndexes('odn_ports'));

        $this->assertContains(['subscription_id'], $ports);
        $this->assertContains(['network_asset_id', 'pon_number'], $ports);
        $this->assertContains(['odn_splitter_id', 'number'], $ports);
        $this->assertContains(['input_port_id'], array_values($this->uniqueIndexes('odn_splitters')));
    }

    public function test_the_splitter_input_is_a_foreign_key_to_the_ports_table(): void
    {
        $references = DB::select(
            'select referenced_table_name as t from information_schema.key_column_usage where table_schema = database() and table_name = ? and column_name = ? and referenced_table_name is not null',
            ['odn_splitters', 'input_port_id'],
        );

        $this->assertSame(['odn_ports'], array_map(fn (object $row): string => $row->t, $references));
    }
}
