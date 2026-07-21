<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('categories') || ! Schema::hasTable('units')) {
            return;
        }

        if (! Schema::hasColumn('categories', 'unit_id')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->foreignId('unit_id')
                    ->nullable()
                    ->after('parent_id')
                    ->constrained('units')
                    ->restrictOnDelete();

                $table->index(['company_id', 'unit_id']);
            });
        }

        $categories = DB::table('categories')->whereNull('unit_id')->get(['id', 'company_id']);

        foreach ($categories as $category) {
            $unitId = null;

            if (Schema::hasTable('products')) {
                $unitId = DB::table('products')
                    ->where('category_id', $category->id)
                    ->whereNotNull('unit_id')
                    ->orderBy('id')
                    ->value('unit_id');
            }

            if (! $unitId) {
                $unitId = DB::table('units')
                    ->where('company_id', $category->company_id)
                    ->orderBy('id')
                    ->value('id');
            }

            if (! $unitId) {
                $unitId = DB::table('units')->insertGetId([
                    'company_id' => $category->company_id,
                    'name' => 'Default',
                    'symbol' => 'def-'.$category->company_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('categories')
                ->where('id', $category->id)
                ->update(['unit_id' => $unitId]);
        }

        $this->makeUnitIdRequired();
    }

    public function down(): void
    {
        if (! Schema::hasTable('categories') || ! Schema::hasColumn('categories', 'unit_id')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['unit_id']);
            $table->dropIndex(['company_id', 'unit_id']);
            $table->dropColumn('unit_id');
        });
    }

    private function makeUnitIdRequired(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE categories MODIFY unit_id BIGINT UNSIGNED NOT NULL');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE categories ALTER COLUMN unit_id SET NOT NULL');
        }
    }
};
