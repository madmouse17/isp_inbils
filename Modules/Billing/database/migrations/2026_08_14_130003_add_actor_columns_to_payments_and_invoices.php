<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Non-destructive rollback contract: retain historical attribution columns
        // (cancelled_by on payments and invoices) to preserve audit trails and immutable history.
    }
};
