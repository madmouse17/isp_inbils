<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * How the targets of a tier are counted (K11): `24h` counts every hour, which is what every tier did until now, and
     * `business` counts only the open hours of the company (companies.settings.service_hours). The column goes at the end of
     * the table with a constant default, so MySQL adds it in place and instantly, and every existing tier stays on 24 hours.
     * A third calendar would be appended to the enum later, which MySQL also does in place.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('sla_tiers', 'calendar')) {
            Schema::table('sla_tiers', function (Blueprint $table) {
                $table->enum('calendar', ['24h', 'business'])->default('24h');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sla_tiers', 'calendar')) {
            Schema::table('sla_tiers', function (Blueprint $table) {
                $table->dropColumn('calendar');
            });
        }
    }
};
