<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_categories', function (Blueprint $table) {
            // "Saran untuk pelanggan": a short tip the customer app shows before it offers to open a ticket. At most 500
            // characters, enforced by UpdateTicketCategoryRequest.
            $table->text('customer_hint')->nullable()->after('default_priority');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_categories', function (Blueprint $table) {
            $table->dropColumn('customer_hint');
        });
    }
};
