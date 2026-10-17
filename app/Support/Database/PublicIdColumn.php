<?php

namespace App\Support\Database;

use App\Support\PublicId;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives existing tables the `public_id` ULID column of App\Traits\HasPublicId (spec .scratch/opaque-ids K2) in
 * three steps: a nullable column, a chunked backfill, then NOT NULL with a unique index. Each step checks what is
 * already there, so a migration that stopped half way (a row inserted by old code between the backfill and the NOT
 * NULL change) is repaired by running it again. The migrations only name their tables; the steps live here so the
 * core and module migrations cannot drift apart.
 */
final class PublicIdColumn
{
    private const CHUNK = 500;

    /** @param list<string> $tables */
    public static function add(array $tables): void
    {
        foreach ($tables as $table) {
            if (! Schema::hasColumn($table, 'public_id')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->ulid('public_id')->nullable());
            }

            self::backfill($table);

            $unique = Schema::hasIndex($table, ['public_id'], 'unique');

            Schema::table($table, function (Blueprint $blueprint) use ($unique): void {
                $blueprint->ulid('public_id')->nullable(false)->change();

                if (! $unique) {
                    $blueprint->unique('public_id');
                }
            });
        }
    }

    /** @param list<string> $tables */
    public static function drop(array $tables): void
    {
        foreach ($tables as $table) {
            if (! Schema::hasColumn($table, 'public_id')) {
                continue;
            }

            $unique = Schema::hasIndex($table, ['public_id'], 'unique');

            Schema::table($table, function (Blueprint $blueprint) use ($unique): void {
                if ($unique) {
                    $blueprint->dropUnique(['public_id']);
                }

                $blueprint->dropColumn('public_id');
            });
        }
    }

    private static function backfill(string $table): void
    {
        DB::table($table)->whereNull('public_id')->orderBy('id')->chunkById(self::CHUNK, function ($rows) use ($table): void {
            DB::transaction(function () use ($rows, $table): void {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update(['public_id' => PublicId::new()]);
                }
            });
        });
    }
}
