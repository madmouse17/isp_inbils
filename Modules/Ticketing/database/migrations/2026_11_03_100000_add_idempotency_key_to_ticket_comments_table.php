<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The app's Idempotency-Key, kept on the row: the cache replay record lives 24 h, a mobile outbox may replay later.
 * NULL (web form, staff web) is never unique-checked by MySQL, so only keyed API writes are deduplicated.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->after('is_internal');
            $table->unique(['company_id', 'author_id', 'idempotency_key'], 'ticket_comments_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->dropUnique('ticket_comments_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
