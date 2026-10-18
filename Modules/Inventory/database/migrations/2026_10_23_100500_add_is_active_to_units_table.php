<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /** Units are masters that can be deactivated like products and categories (spec master-lifecycle §3.1). */
    public function up(): void
    {
        if (! Schema::hasColumn('units', 'is_active')) {
            Schema::table('units', fn (Blueprint $table) => $table->boolean('is_active')->default(true)->after('symbol'));
        }

        if (! Schema::hasIndex('units', ['company_id', 'is_active'])) {
            Schema::table('units', fn (Blueprint $table) => $table->index(['company_id', 'is_active']));
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('units', ['company_id', 'is_active'])) {
            Schema::table('units', fn (Blueprint $table) => $table->dropIndex(['company_id', 'is_active']));
        }

        if (Schema::hasColumn('units', 'is_active')) {
            Schema::table('units', fn (Blueprint $table) => $table->dropColumn('is_active'));
        }
    }
};
