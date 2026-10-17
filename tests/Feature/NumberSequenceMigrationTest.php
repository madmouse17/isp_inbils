<?php

namespace Tests\Feature;

use App\Support\NumberSequences\NumberTemplate;
use App\Support\PublicId;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * MySQL commits on DDL, so the rows this test writes are real: it deletes them, and puts the columns back,
 * before it ends.
 */
class NumberSequenceMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_13_100000_add_template_and_reset_to_number_sequences_table.php';

    private function migration(): Migration
    {
        return require database_path('migrations/'.self::MIGRATION);
    }

    private function company(string $code): int
    {
        return DB::table('companies')->insertGetId([
            'public_id' => PublicId::new(),
            'name' => 'Number sequence migration '.$code,
            'code' => $code,
            'timezone' => 'Asia/Jakarta',
            'currency' => 'IDR',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, object> number_sequences rows of the given companies, keyed by "company:entity" */
    private function rows(array $companyIds): array
    {
        return DB::table('number_sequences')->whereIn('company_id', $companyIds)->get()
            ->keyBy(static fn (object $row): string => $row->company_id.':'.$row->entity_type)->all();
    }

    public function test_existing_rows_keep_their_format_and_the_migration_rolls_back_and_forward(): void
    {
        $migration = $this->migration();
        $companyIds = [$this->company('MIGA'), $this->company('MIGB')];
        [$a, $b] = $companyIds;

        try {
            $migration->down();
            $this->assertFalse(Schema::hasColumn('number_sequences', 'template'));
            $this->assertTrue(Schema::hasColumn('number_sequences', 'padding'));
            $this->assertTrue(Schema::hasColumn('number_sequences', 'year_suffix'));

            $legacy = [
                [$a, 'invoice', 'INV', 12, 5, true],
                [$a, 'spk', 'SPK', 3, 4, false],
                [$b, 'subscription', 'SUB', 1, 3, true],
                [$b, 'network_asset', 'NA', 9, 0, true],
                [$b, 'customer', 'CUS', 9, 200, false],
            ];
            foreach ($legacy as [$companyId, $entityType, $prefix, $next, $padding, $yearSuffix]) {
                DB::table('number_sequences')->insert([
                    'public_id' => PublicId::new(),
                    'company_id' => $companyId, 'entity_type' => $entityType, 'prefix' => $prefix, 'next_number' => $next,
                    'padding' => $padding, 'year_suffix' => $yearSuffix, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $migration->up();

            foreach (['template', 'reset_period', 'period_key', 'allow_manual'] as $column) {
                $this->assertTrue(Schema::hasColumn('number_sequences', $column), "{$column} exists after up()");
            }
            $this->assertFalse(Schema::hasColumn('number_sequences', 'padding'));
            $this->assertFalse(Schema::hasColumn('number_sequences', 'year_suffix'));

            $rows = $this->rows($companyIds);
            $expected = [
                $a.':invoice' => ['INV', 12, '{PREFIX}-{YYYY}-{SEQ:5}'],
                $a.':spk' => ['SPK', 3, '{PREFIX}-{SEQ:4}'],
                $b.':subscription' => ['SUB', 1, '{PREFIX}-{YYYY}-{SEQ:3}'],
                $b.':network_asset' => ['NA', 9, '{PREFIX}-{YYYY}-{SEQ:1}'],
                $b.':customer' => ['CUS', 9, '{PREFIX}-{SEQ:10}'],
            ];
            foreach ($expected as $key => [$prefix, $next, $template]) {
                $this->assertSame([$prefix, $next, $template, 'never', null, 0], [
                    $rows[$key]->prefix, (int) $rows[$key]->next_number, $rows[$key]->template,
                    $rows[$key]->reset_period, $rows[$key]->period_key, (int) $rows[$key]->allow_manual,
                ], $key);
            }

            // A converted row renders the code the old engine would have handed out.
            $at = now('Asia/Jakarta');
            $this->assertSame('INV-'.$at->format('Y').'-00012', NumberTemplate::render($rows[$a.':invoice']->template, 'INV', 'MIGA', 12, $at));
            $this->assertSame('SPK-0003', NumberTemplate::render($rows[$a.':spk']->template, 'SPK', 'MIGA', 3, $at));

            DB::table('number_sequences')->where('company_id', $b)->where('entity_type', 'subscription')->update(['template' => '{COMPANY}/{YY}/{SEQ:6}', 'reset_period' => 'monthly']);
            DB::table('number_sequences')->where('company_id', $b)->where('entity_type', 'network_asset')->update(['template' => '{PREFIX}']);

            $migration->down();

            $this->assertFalse(Schema::hasColumn('number_sequences', 'template'));
            foreach (['reset_period', 'period_key', 'allow_manual'] as $column) {
                $this->assertFalse(Schema::hasColumn('number_sequences', $column), "{$column} is gone after down()");
            }
            $restored = $this->rows($companyIds);
            $expectedBack = [
                $a.':invoice' => [12, 5, 1],
                $a.':spk' => [3, 4, 0],
                $b.':subscription' => [1, 6, 1],
                $b.':network_asset' => [9, 5, 0],
                $b.':customer' => [9, 10, 0],
            ];
            foreach ($expectedBack as $key => [$next, $padding, $yearSuffix]) {
                $this->assertSame([$next, $padding, $yearSuffix], [
                    (int) $restored[$key]->next_number, (int) $restored[$key]->padding, (int) $restored[$key]->year_suffix,
                ], $key);
            }

            $migration->up();

            $this->assertSame('{PREFIX}-{YYYY}-{SEQ:5}', $this->rows($companyIds)[$a.':invoice']->template, 'up() after down() converts again');
        } finally {
            if (! Schema::hasColumn('number_sequences', 'template')) {
                $migration->up();
            }
            DB::table('number_sequences')->whereIn('company_id', $companyIds)->delete();
            DB::table('companies')->whereIn('id', $companyIds)->delete();
        }
    }
}
