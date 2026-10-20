<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        // The new value goes last: on MySQL 8 that changes the column's metadata only, so it is safe on a live table.
        DB::statement("ALTER TABLE `payment_attempts` MODIFY `status` ENUM('pending', 'paid', 'failed', 'expired', 'exception', 'cancelled_locally', 'refunded') NOT NULL DEFAULT 'pending'");

        Schema::table('payment_attempts', function (Blueprint $table) {
            // Who closed an exception whose money went back outside Inbils, when, and the refund's reference and note.
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_reference', 100)->nullable();
            $table->string('resolution_note', 500)->nullable();
        });
    }

    public function down(): void
    {
        // The shrink would fail while a row holds the new value; the money of a marked attempt is "Perlu dicek" again.
        DB::table('payment_attempts')->where('status', 'refunded')->update(['status' => 'exception']);

        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn(['resolved_at', 'resolution_reference', 'resolution_note']);
        });

        DB::statement("ALTER TABLE `payment_attempts` MODIFY `status` ENUM('pending', 'paid', 'failed', 'expired', 'exception', 'cancelled_locally') NOT NULL DEFAULT 'pending'");
    }
};
