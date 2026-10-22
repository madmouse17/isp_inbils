<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The technician app's Idempotency-Key, kept on the row: the cache replay record lives 24 h, a mobile outbox may replay
 * later. NULL (web form) is never unique-checked by MySQL, so only keyed API writes are deduplicated.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_requests', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->after('reason');
            $table->unique(['company_id', 'requested_by', 'idempotency_key'], 'inventory_requests_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_requests', function (Blueprint $table) {
            $table->dropUnique('inventory_requests_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
