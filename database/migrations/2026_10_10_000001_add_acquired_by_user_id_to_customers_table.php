<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Customer-level sales attribution: the employee credited with acquiring the customer.
     * Nullable (existing customers stay Unassigned); restrictOnDelete keeps attribution history
     * (same retention rule as invoices.cancelled_by).
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('acquired_by_user_id')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->index(['company_id', 'acquired_by_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'acquired_by_user_id']);
            $table->dropConstrainedForeignId('acquired_by_user_id');
        });
    }
};
