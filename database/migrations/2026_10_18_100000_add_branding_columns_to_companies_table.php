<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('whatsapp', 50)->nullable()->after('phone');
            // #RRGGBB, validated by UpdateCompanyProfileRequest; the mobile apps seed their theme from it.
            $table->char('brand_color', 7)->nullable()->after('website');
            $table->string('support_hours', 120)->nullable()->after('brand_color');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['whatsapp', 'brand_color', 'support_hours']);
        });
    }
};
