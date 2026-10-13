<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /** Set when the ticket is created; tickets that exist today keep it empty and count as "no response target". */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('tickets', 'response_deadline')) {
                $table->timestamp('response_deadline')->nullable()->after('sla_deadline');
                $table->index(['company_id', 'response_deadline']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if (Schema::hasColumn('tickets', 'response_deadline')) {
                $table->dropIndex(['company_id', 'response_deadline']);
                $table->dropColumn('response_deadline');
            }
        });
    }
};
