<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    public function up(): void
    {
        // The new value goes last: on MySQL 8 that changes the column's metadata only, so it is safe on a live table.
        DB::statement("ALTER TABLE `payment_attempts` MODIFY `status` ENUM('pending', 'paid', 'failed', 'expired', 'exception', 'cancelled_locally') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        // The shrink would fail while a row holds the new value; a page Inbils closed ended without a payment, as an expired one did.
        DB::table('payment_attempts')->where('status', 'cancelled_locally')->update(['status' => 'expired']);

        DB::statement("ALTER TABLE `payment_attempts` MODIFY `status` ENUM('pending', 'paid', 'failed', 'expired', 'exception') NOT NULL DEFAULT 'pending'");
    }
};
