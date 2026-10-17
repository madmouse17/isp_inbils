<?php

namespace Tests\Feature\PublicId;

use App\Models\Core\Company;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The migrations that add `public_id`, rolled back and applied again. MySQL commits on DDL, so a test that needs rows
 * writes them straight to the table and removes them before it ends, and every test puts the columns back.
 */
class PublicIdMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private const ULID = '/^[0-7][0-9a-hjkmnp-tv-z]{25}$/';

    private const FILES = [
        'core' => 'database/migrations/2026_10_22_100000_add_public_id_to_core_tables.php',
        'billing' => 'Modules/Billing/database/migrations/2026_10_22_100100_add_public_id_to_billing_tables.php',
        'inventory' => 'Modules/Inventory/database/migrations/2026_10_22_100200_add_public_id_to_inventory_tables.php',
        'ipam' => 'Modules/Ipam/database/migrations/2026_10_22_100300_add_public_id_to_ipam_tables.php',
        'network_asset' => 'Modules/NetworkAsset/database/migrations/2026_10_22_100400_add_public_id_to_network_asset_tables.php',
        'service' => 'Modules/Service/database/migrations/2026_10_22_100500_add_public_id_to_service_tables.php',
        'spk' => 'Modules/SPK/database/migrations/2026_10_22_100600_add_public_id_to_spk_tables.php',
        'ticketing' => 'Modules/Ticketing/database/migrations/2026_10_22_100700_add_public_id_to_ticketing_tables.php',
    ];

    /** @return array<string, array{string}> */
    public static function migrationGroups(): array
    {
        return array_map(fn (string $group): array => [$group], array_combine(array_keys(self::FILES), array_keys(self::FILES)));
    }

    #[DataProvider('migrationGroups')]
    public function test_each_migration_rolls_back_to_exactly_its_tables_and_applies_again(string $group): void
    {
        $migration = require base_path(self::FILES[$group]);
        $all = $this->carrying();

        try {
            $migration->down();
            $this->assertEqualsCanonicalizing(PublicIdInventoryTest::TABLES[$group], array_values(array_diff($all, $this->carrying())));

            $migration->up();
            $this->assertEqualsCanonicalizing($all, $this->carrying());
        } finally {
            $migration->up();
        }
    }

    public function test_the_core_migration_gives_the_rows_it_finds_a_unique_ulid(): void
    {
        $migration = require base_path(self::FILES['core']);
        $ids = [];

        try {
            $migration->down();

            foreach (['PI-ONE', 'PI-TWO', 'PI-THREE'] as $code) {
                $ids[] = DB::table('companies')->insertGetId([
                    'name' => "Public id {$code}", 'code' => $code, 'timezone' => 'Asia/Jakarta', 'currency' => 'IDR',
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $migration->up();

            $public = DB::table('companies')->whereIn('id', $ids)->pluck('public_id');
            $this->assertCount(3, $public);
            $this->assertCount(3, $public->unique());
            $this->assertSame(0, $public->reject(fn (string $id): bool => preg_match(self::ULID, $id) === 1)->count());
        } finally {
            $migration->up();
            DB::table('companies')->whereIn('id', $ids)->delete();
        }
    }

    public function test_a_module_migration_backfills_its_tables_and_afterwards_refuses_a_row_without_an_id(): void
    {
        $migration = require base_path(self::FILES['inventory']);
        $company = Company::factory()->create();
        $row = fn (string $name): array => ['company_id' => $company->id, 'name' => $name, 'symbol' => $name, 'created_at' => now(), 'updated_at' => now()];

        try {
            $migration->down();
            DB::table('units')->insert([$row('pcs'), $row('meter')]);

            $migration->up();

            $public = DB::table('units')->where('company_id', $company->id)->pluck('public_id');
            $this->assertCount(2, $public);
            $this->assertCount(2, $public->unique());
            $this->assertSame(0, $public->reject(fn (string $id): bool => preg_match(self::ULID, $id) === 1)->count());

            $this->assertThrows(fn () => DB::table('units')->insert($row('box')), QueryException::class);
            $this->assertThrows(fn () => DB::table('units')->insert([...$row('box'), 'public_id' => $public[0]]), QueryException::class);
        } finally {
            $migration->up();
            DB::table('units')->where('company_id', $company->id)->delete();
            DB::table('companies')->where('id', $company->id)->delete();
        }
    }

    /** @return list<string> the tables that have a `public_id` column right now */
    private function carrying(): array
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->where('column_name', 'public_id')
            ->selectRaw('LOWER(table_name) AS name')
            ->pluck('name')
            ->all();
    }
}
