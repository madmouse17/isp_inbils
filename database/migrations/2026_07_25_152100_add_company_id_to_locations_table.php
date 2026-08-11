<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive repair for already-migrated DBs whose locations table predated company_id.
 * Greenfield installs get company_id from 2026_06_30_210000_create_locations_table.
 */
return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('locations') || Schema::hasColumn('locations', 'company_id')) {
            return;
        }

        Schema::table('locations', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->after('id');
        });

        $companyId = DB::table('companies')->orderBy('id')->value('id');
        if ($companyId !== null) {
            DB::table('locations')->whereNull('company_id')->update(['company_id' => $companyId]);
        }

        // Drop bare unique(code) if present so we can re-unique per company.
        $this->dropIndexIfExists('locations', 'locations_code_unique');

        Schema::table('locations', function (Blueprint $table) use ($companyId) {
            if ($companyId !== null) {
                $table->unsignedBigInteger('company_id')->nullable(false)->change();
            }

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'type']);
            $table->index(['company_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('locations') || ! Schema::hasColumn('locations', 'company_id')) {
            return;
        }

        Schema::table('locations', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropUnique(['company_id', 'code']);
            $table->dropIndex(['company_id', 'type']);
            $table->dropIndex(['company_id', 'parent_id']);
            $table->dropColumn('company_id');
            $table->unique('code');
        });
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        $database = Schema::getConnection()->getDatabaseName();
        $exists = DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();

        if ($exists) {
            Schema::table($table, function (Blueprint $blueprint) use ($index) {
                $blueprint->dropUnique($index);
            });
        }
    }
};
