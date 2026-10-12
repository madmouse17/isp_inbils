<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('service_subscriptions', function (Blueprint $table) {
            // Null on every existing row: legacy overdue isolations fall back to the grace-only reconnect rule.
            $table->timestamp('suspended_at')->nullable()->after('suspension_source');
        });
    }

    public function down(): void
    {
        Schema::table('service_subscriptions', function (Blueprint $table) {
            $table->dropColumn('suspended_at');
        });
    }
};
