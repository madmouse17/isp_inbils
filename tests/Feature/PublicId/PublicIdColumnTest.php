<?php

namespace Tests\Feature\PublicId;

use App\Support\Database\PublicIdColumn;
use App\Support\PublicId;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The three steps the public_id migrations share, run against a scratch table of this test (MySQL commits on DDL, so
 * the test owns the table and drops it itself).
 */
class PublicIdColumnTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'public_id_probe';

    private const ULID = '/^[0-7][0-9a-hjkmnp-tv-z]{25}$/';

    protected function tearDown(): void
    {
        Schema::dropIfExists(self::TABLE);

        parent::tearDown();
    }

    private function probe(int $rows): void
    {
        Schema::dropIfExists(self::TABLE);
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('label');
        });

        foreach (array_chunk(range(1, $rows), 500) as $chunk) {
            DB::table(self::TABLE)->insert(array_map(fn (int $n): array => ['label' => "row {$n}"], $chunk));
        }
    }

    public function test_every_existing_row_gets_its_own_lowercase_ulid_across_chunks(): void
    {
        $this->probe(1201);

        PublicIdColumn::add([self::TABLE]);

        $ids = DB::table(self::TABLE)->pluck('public_id');
        $this->assertCount(1201, $ids);
        $this->assertSame(1201, $ids->unique()->count(), 'no two rows share an id');
        $this->assertSame(0, $ids->reject(fn (string $id): bool => preg_match(self::ULID, $id) === 1)->count(), 'every id is a lowercase ULID');
    }

    public function test_the_column_is_then_not_null_and_unique(): void
    {
        $this->probe(3);
        PublicIdColumn::add([self::TABLE]);

        $column = collect(Schema::getColumns(self::TABLE))->firstWhere('name', 'public_id');
        $this->assertSame('char(26)', $column['type']);
        $this->assertFalse($column['nullable']);
        $this->assertTrue(Schema::hasIndex(self::TABLE, ['public_id'], 'unique'));

        $taken = DB::table(self::TABLE)->value('public_id');
        $this->assertThrows(fn () => DB::table(self::TABLE)->insert(['label' => 'twin', 'public_id' => $taken]), QueryException::class);
        $this->assertThrows(fn () => DB::table(self::TABLE)->insert(['label' => 'anonymous']), QueryException::class);
        DB::table(self::TABLE)->insert(['label' => 'fresh', 'public_id' => PublicId::new()]);
        $this->assertSame(4, DB::table(self::TABLE)->count());
    }

    public function test_a_second_run_keeps_the_ids_and_repairs_a_row_that_old_code_inserted_in_between(): void
    {
        $this->probe(2);
        PublicIdColumn::add([self::TABLE]);
        $before = DB::table(self::TABLE)->orderBy('id')->pluck('public_id', 'id')->all();

        // The state a migration that stopped after the backfill leaves behind: a nullable column and a row without an id.
        Schema::table(self::TABLE, fn (Blueprint $table) => $table->ulid('public_id')->nullable()->change());
        DB::table(self::TABLE)->insert(['label' => 'late', 'public_id' => null]);

        PublicIdColumn::add([self::TABLE]);

        $after = DB::table(self::TABLE)->orderBy('id')->pluck('public_id', 'id')->all();
        $this->assertSame($before, array_slice($after, 0, 2, true), 'the rows that had an id keep it');
        $this->assertMatchesRegularExpression(self::ULID, (string) end($after));
        $this->assertFalse(collect(Schema::getColumns(self::TABLE))->firstWhere('name', 'public_id')['nullable']);
        $this->assertCount(1, array_filter(Schema::getIndexes(self::TABLE), fn (array $index): bool => $index['columns'] === ['public_id']), 'one unique index, not two');
    }

    public function test_drop_removes_the_index_and_the_column_and_may_run_twice(): void
    {
        $this->probe(2);
        PublicIdColumn::add([self::TABLE]);

        PublicIdColumn::drop([self::TABLE]);
        PublicIdColumn::drop([self::TABLE]);

        $this->assertFalse(Schema::hasColumn(self::TABLE, 'public_id'));
        $this->assertFalse(Schema::hasIndex(self::TABLE, ['public_id']));
        $this->assertSame(2, DB::table(self::TABLE)->count(), 'the rows stay');
    }
}
