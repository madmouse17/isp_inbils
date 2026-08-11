<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customer_addresses', function (Blueprint $table) {
            $table->char('province_code', 2)->nullable()->after('postal_code');
            $table->char('city_code', 4)->nullable()->after('province_code');
            $table->char('district_code', 7)->nullable()->after('city_code');
            $table->char('village_code', 10)->nullable()->after('district_code');

            $table->foreign('province_code')->references('code')->on('indonesia_provinces')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign('city_code')->references('code')->on('indonesia_cities')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign('district_code')->references('code')->on('indonesia_districts')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign('village_code')->references('code')->on('indonesia_villages')->restrictOnDelete()->cascadeOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_addresses', function (Blueprint $table) {
            $table->dropForeign(['province_code']);
            $table->dropForeign(['city_code']);
            $table->dropForeign(['district_code']);
            $table->dropForeign(['village_code']);
            $table->dropColumn(['province_code', 'city_code', 'district_code', 'village_code']);
        });
    }
};
