<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('service_subscriptions', function (Blueprint $table) {
            $table->timestamp('blocked_at')->nullable();
            $table->foreignId('blocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('block_reason', 500)->nullable();
            $table->index(['company_id', 'blocked_at']);
        });
    }

    public function down(): void
    {
        Schema::table('service_subscriptions', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'blocked_at']);
            $table->dropConstrainedForeignId('blocked_by');
            $table->dropColumn(['blocked_at', 'block_reason']);
        });
    }
};
