<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Idempotency marker: one SLA-breach notification per ticket.
            if (! Schema::hasColumn('tickets', 'sla_breached_notified_at')) {
                $table->timestamp('sla_breached_notified_at')->nullable()->after('sla_deadline');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if (Schema::hasColumn('tickets', 'sla_breached_notified_at')) {
                $table->dropColumn('sla_breached_notified_at');
            }
        });
    }
};
