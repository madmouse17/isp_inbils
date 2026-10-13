<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /** Uptime and credit were never read: the tier now only holds the response and resolution targets. */
    public function up(): void
    {
        $drop = array_values(array_filter(
            ['uptime_pct', 'credit_pct'],
            static fn (string $column): bool => Schema::hasColumn('sla_tiers', $column),
        ));

        if ($drop !== []) {
            Schema::table('sla_tiers', function (Blueprint $table) use ($drop) {
                $table->dropColumn($drop);
            });
        }
    }

    /** The columns return nullable and empty: the dropped values are not recoverable. */
    public function down(): void
    {
        Schema::table('sla_tiers', function (Blueprint $table) {
            if (! Schema::hasColumn('sla_tiers', 'uptime_pct')) {
                $table->decimal('uptime_pct', 5, 2)->nullable()->after('name');
            }
            if (! Schema::hasColumn('sla_tiers', 'credit_pct')) {
                $table->decimal('credit_pct', 5, 2)->nullable()->after('resolution_time_hours');
            }
        });
    }
};
