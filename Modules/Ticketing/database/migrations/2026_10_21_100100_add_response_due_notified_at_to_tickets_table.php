<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Idempotency marker: one "first response is due" notification per ticket, whether it was about to be late or already was.
            if (! Schema::hasColumn('tickets', 'response_due_notified_at')) {
                $table->timestamp('response_due_notified_at')->nullable()->after('response_deadline');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if (Schema::hasColumn('tickets', 'response_due_notified_at')) {
                $table->dropColumn('response_due_notified_at');
            }
        });
    }
};
