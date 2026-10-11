<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * The monthly cap is counted per company and month of sending while the company's row lock is held, so the
     * count has to stay a short index range scan however many months of log the company keeps.
     */
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->index(['company_id', 'status', 'sent_at'], 'whatsapp_messages_quota_index');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropIndex('whatsapp_messages_quota_index');
        });
    }
};
