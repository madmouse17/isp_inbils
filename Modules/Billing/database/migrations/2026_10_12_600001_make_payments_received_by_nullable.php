<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /** A gateway payment has no human receiver; the ledger records that honestly as NULL. */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('received_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('payments')->whereNull('received_by')->exists()) {
            throw new RuntimeException('Cannot make payments.received_by NOT NULL: payments without a receiver (gateway payments) exist.');
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('received_by')->nullable(false)->change();
        });
    }
};
