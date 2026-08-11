<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('locations')) {
            return;
        }

        Schema::table('locations', function (Blueprint $table) {
            if (! Schema::hasColumn('locations', 'path')) {
                $table->string('path')->nullable()->after('parent_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('locations')) {
            return;
        }

        Schema::table('locations', function (Blueprint $table) {
            if (Schema::hasColumn('locations', 'path')) {
                $table->dropColumn('path');
            }
        });
    }
};
