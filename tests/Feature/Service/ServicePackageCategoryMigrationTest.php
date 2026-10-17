<?php

namespace Tests\Feature\Service;

use App\Models\Core\Company;
use App\Support\Database\PublicIdColumn;
use App\Support\PublicId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** MySQL commits on DDL: the test cleans up every row it inserted and leaves the schema migrated. */
class ServicePackageCategoryMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'Modules/Service/database/migrations/2026_10_14_200000_create_service_package_categories_table.php';

    /** Dropped and recreated by the migrations this test reverses, so they come back without their `public_id`. */
    private const RECREATED = ['service_package_categories', 'announcements', 'promo_banners'];

    /**
     * Every package the seeders create (ServiceCatalogSeeder, CompanySeeder, FoundationDemo) plus the edge cases of the
     * P7 rule: [code, name, expected category code].
     *
     * @return array<string, array{string, string, string}>
     */
    public static function packages(): array
    {
        return [
            'catalog home 20' => ['PKG-HOME-20M', 'Home 20Mbps', 'RUMAHAN'],
            'catalog home 50' => ['PKG-HOME-50M', 'Home 50Mbps', 'RUMAHAN'],
            'catalog home 100' => ['PKG-HOME-100M', 'Home 100Mbps', 'RUMAHAN'],
            'catalog business 50' => ['PKG-BIZ-50M', 'Business 50Mbps', 'BISNIS'],
            'catalog business dedicated' => ['PKG-BIZ-100M', 'Business 100Mbps Dedicated', 'KORPORAT'],
            'catalog enterprise 100' => ['PKG-ENT-100M', 'Enterprise 100Mbps', 'KORPORAT'],
            'catalog enterprise 500' => ['PKG-ENT-500M', 'Enterprise 500Mbps', 'KORPORAT'],
            'catalog soho' => ['PKG-SOHO-20M', 'SOHO 20Mbps', 'BISNIS'],
            'company home 10' => ['PKG-HOME-10', 'Home 10Mbps', 'RUMAHAN'],
            'company home 20' => ['PKG-HOME-20', 'Home 20Mbps', 'RUMAHAN'],
            'company home 50' => ['PKG-HOME-50', 'Home 50Mbps', 'RUMAHAN'],
            'company business 100' => ['PKG-BIZ-100', 'Business 100Mbps', 'BISNIS'],
            'company business 200' => ['PKG-BIZ-200', 'Business 200Mbps', 'BISNIS'],
            'company home promo' => ['PKG-HOME-20-PROMO', 'Home 20Mbps Promo', 'RUMAHAN'],
            'company business gold' => ['PKG-BIZ-100-GOLD', 'Business 100Mbps Gold', 'BISNIS'],
            'company trial' => ['PKG-TRIAL', 'Trial 5Mbps', 'RUMAHAN'],
            'demo home 30' => ['PKG-HOME-30', 'Home 30Mbps', 'RUMAHAN'],
            'demo gamer' => ['PKG-GAMER-100', 'Gamer 100Mbps', 'RUMAHAN'],
            'demo kos' => ['PKG-KOS-50', 'Kos dan Asrama 50Mbps', 'RUMAHAN'],
            'name only, case-insensitive' => ['X-1', 'paket usaha BISNIS kecil', 'BISNIS'],
            'code only' => ['corporate-9', 'Layanan 9', 'KORPORAT'],
            'korporat beats bisnis' => ['X-2', 'Bisnis Korporat 1Gbps', 'KORPORAT'],
            'no keyword falls to rumahan' => ['X-3', 'Paket Tetangga', 'RUMAHAN'],
            'keywords match whole words only' => ['X-4', 'Content Pack', 'RUMAHAN'],
        ];
    }

    public function test_backfill_assigns_the_category_by_code_and_name_for_every_seeded_package(): void
    {
        $migration = require base_path(self::MIGRATION);
        $company = Company::factory()->create();
        $rows = array_values(self::packages());
        $ids = $this->dropSchemaAndInsert($migration, $company, array_map(fn (array $row): array => [$row[0], $row[1]], $rows));

        try {
            $migration->up();

            $actual = DB::table('service_packages')
                ->join('service_package_categories as c', 'c.id', '=', 'service_packages.service_package_category_id')
                ->whereIn('service_packages.id', $ids)
                ->get(['service_packages.id', 'c.code', 'c.company_id'])
                ->keyBy('id');

            foreach ($rows as $index => [$code, $name, $expected]) {
                $this->assertSame($expected, $actual[$ids[$index]]->code, "{$code} {$name}");
                $this->assertSame($company->id, (int) $actual[$ids[$index]]->company_id);
            }
        } finally {
            $this->cleanUp($migration, [$company->id]);
        }
    }

    public function test_every_company_gets_the_three_default_categories_and_soft_deleted_packages_are_backfilled(): void
    {
        $migration = require base_path(self::MIGRATION);
        $withPackages = Company::factory()->create();
        $empty = Company::factory()->create();
        $ids = $this->dropSchemaAndInsert($migration, $withPackages, [['PKG-ENT-1', 'Enterprise 1G'], ['PKG-HOME-1', 'Home 1']], deleteSecond: true);

        try {
            $migration->up();

            foreach ([$withPackages, $empty] as $company) {
                $this->assertSame(
                    ['RUMAHAN:Rumahan:1:1', 'BISNIS:Bisnis:2:1', 'KORPORAT:Korporat:3:1'],
                    DB::table('service_package_categories')->where('company_id', $company->id)->orderBy('sort_order')->get()
                        ->map(fn ($row) => "{$row->code}:{$row->name}:{$row->sort_order}:{$row->is_active}")->all(),
                );
            }

            $this->assertSame(
                ['KORPORAT', 'RUMAHAN'],
                DB::table('service_packages')->whereIn('service_packages.id', $ids)->orderBy('service_packages.id')
                    ->join('service_package_categories as c', 'c.id', '=', 'service_packages.service_package_category_id')
                    ->pluck('c.code')->all(),
            );
            $this->assertNotNull(DB::table('service_packages')->where('id', $ids[1])->value('deleted_at'));
        } finally {
            $this->cleanUp($migration, [$withPackages->id, $empty->id]);
        }
    }

    public function test_migration_is_reversible_and_the_category_is_required_and_protected(): void
    {
        $migration = require base_path(self::MIGRATION);

        try {
            $this->assertTrue(Schema::hasTable('service_package_categories'));
            $this->assertTrue(Schema::hasColumn('service_packages', 'service_package_category_id'));
            $column = collect(Schema::getColumns('service_packages'))->firstWhere('name', 'service_package_category_id');
            $this->assertFalse($column['nullable']);
            $this->assertContains(
                ['company_id', 'service_package_category_id'],
                collect(Schema::getIndexes('service_packages'))->pluck('columns')->all(),
            );
            $this->assertSame(['RESTRICT'], collect(DB::select(
                'select DELETE_RULE from information_schema.REFERENTIAL_CONSTRAINTS where CONSTRAINT_SCHEMA = database() and TABLE_NAME = ? and REFERENCED_TABLE_NAME = ?',
                ['service_packages', 'service_package_categories'],
            ))->pluck('DELETE_RULE')->all());

            $this->dependents('down');
            $migration->down();
            $this->assertFalse(Schema::hasTable('service_package_categories'));
            $this->assertFalse(Schema::hasColumn('service_packages', 'service_package_category_id'));
            $this->assertTrue(Schema::hasTable('service_packages'));
        } finally {
            if (! Schema::hasTable('service_package_categories')) {
                $migration->up();
            }
            $this->dependents('up');
            PublicIdColumn::add(self::RECREATED);
        }

        $this->assertTrue(Schema::hasColumn('service_packages', 'service_package_category_id'));
    }

    /**
     * @param  list<array{string, string}>  $packages
     * @return list<int> package ids
     */
    private function dropSchemaAndInsert(object $migration, Company $company, array $packages, bool $deleteSecond = false): array
    {
        $this->dependents('down');
        $migration->down();

        $profiles = [
            'bandwidth_profile_id' => DB::table('bandwidth_profiles')->insertGetId([
                'public_id' => PublicId::new(),
                'company_id' => $company->id, 'name' => 'BW '.$company->id, 'download_mbps' => 10, 'upload_mbps' => 5, 'type' => 'shared',
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]),
            'speed_profile_id' => DB::table('speed_profiles')->insertGetId([
                'public_id' => PublicId::new(),
                'company_id' => $company->id, 'name' => 'SP '.$company->id, 'download_max_mbps' => 10, 'upload_max_mbps' => 5,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]),
            'sla_tier_id' => DB::table('sla_tiers')->insertGetId([
                'public_id' => PublicId::new(),
                'company_id' => $company->id, 'name' => 'SLA '.$company->id, 'response_time_hours' => 4, 'resolution_time_hours' => 8,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]),
        ];

        return array_map(fn (array $package, int $index) => DB::table('service_packages')->insertGetId([
            'public_id' => PublicId::new(),
            'company_id' => $company->id, 'code' => $package[0], 'name' => $package[1], ...$profiles,
            'price_mrc' => 100000, 'is_active' => true,
            'deleted_at' => $deleteSecond && $index === 1 ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]), $packages, array_keys($packages));
    }

    /** @param  list<int>  $companyIds */
    private function cleanUp(object $migration, array $companyIds): void
    {
        if (! Schema::hasTable('service_package_categories')) {
            $migration->up();
        }
        $this->dependents('up');
        PublicIdColumn::add(self::RECREATED);

        DB::table('service_packages')->whereIn('company_id', $companyIds)->delete();
        DB::table('service_package_categories')->whereIn('company_id', $companyIds)->delete();
        DB::table('bandwidth_profiles')->whereIn('company_id', $companyIds)->delete();
        DB::table('speed_profiles')->whereIn('company_id', $companyIds)->delete();
        DB::table('sla_tiers')->whereIn('company_id', $companyIds)->delete();
        DB::table('companies')->whereIn('id', $companyIds)->delete();
    }

    /**
     * Later migrations reference `service_package_categories` (announcement and banner targeting pivots), so the
     * category migration can only be reversed once those are gone, and they come back after it.
     */
    private function dependents(string $direction): void
    {
        $files = [
            'announcements' => 'Modules/Service/database/migrations/2026_10_15_200000_create_announcements_table.php',
            'promo_banners' => 'Modules/Service/database/migrations/2026_10_15_200100_create_promo_banners_table.php',
        ];

        foreach ($direction === 'down' ? array_reverse($files, true) : $files as $table => $file) {
            $dependent = require base_path($file);
            if ($direction === 'down' && Schema::hasTable($table)) {
                $dependent->down();
            } elseif ($direction === 'up' && ! Schema::hasTable($table)) {
                $dependent->up();
            }
        }
    }
}
