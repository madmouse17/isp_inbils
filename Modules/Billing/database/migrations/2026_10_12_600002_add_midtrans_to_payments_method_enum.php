<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `payments` MODIFY `method` ENUM('cash', 'transfer', 'cheque', 'other', 'midtrans') NOT NULL");
    }

    public function down(): void
    {
        if (DB::table('payments')->where('method', 'midtrans')->exists()) {
            throw new RuntimeException('Cannot remove "midtrans" from payments.method: gateway payments exist and the ledger is immutable.');
        }

        DB::statement("ALTER TABLE `payments` MODIFY `method` ENUM('cash', 'transfer', 'cheque', 'other') NOT NULL");
    }
};
