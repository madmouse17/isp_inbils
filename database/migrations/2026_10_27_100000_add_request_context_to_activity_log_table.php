<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        // No hasTable/hasColumn guard (unlike 2026_09_07_000001): the table always exists (2026_06_30_134625), and
        // introspection selects return [] while pretending (Connection.php:412), so a guard would turn
        // `migrate --pretend` into an empty run.
        Schema::connection(config('activitylog.database_connection'))
            ->table(config('activitylog.table_name', 'activity_log'), function (Blueprint $table): void {
                $table->string('request_id', 128)->nullable()->instant(); // AssignRequestId accepts client ids up to 128
                $table->string('ip_address', 45)->nullable()->instant();
                $table->string('user_agent', 255)->nullable()->instant();
                $table->index(['company_id', 'event', 'created_at']);
            });
    }

    public function down(): void
    {
        Schema::connection(config('activitylog.database_connection'))
            ->table(config('activitylog.table_name', 'activity_log'), function (Blueprint $table): void {
                $table->dropIndex(['company_id', 'event', 'created_at']);
                $table->dropColumn(['request_id', 'ip_address', 'user_agent']);
            });
    }
};
