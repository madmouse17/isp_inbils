<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_addresses')) {
            return;
        }

        Schema::table('customer_addresses', function (Blueprint $table) {
            if (! Schema::hasColumn('customer_addresses', 'lat')) {
                $table->decimal('lat', 10, 7)->nullable()->after('postal_code');
            }
            if (! Schema::hasColumn('customer_addresses', 'lng')) {
                $table->decimal('lng', 10, 7)->nullable()->after('lat');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('customer_addresses')) {
            return;
        }

        Schema::table('customer_addresses', function (Blueprint $table) {
            if (Schema::hasColumn('customer_addresses', 'lat')) {
                $table->dropColumn('lat');
            }
            if (Schema::hasColumn('customer_addresses', 'lng')) {
                $table->dropColumn('lng');
            }
        });
    }
};
