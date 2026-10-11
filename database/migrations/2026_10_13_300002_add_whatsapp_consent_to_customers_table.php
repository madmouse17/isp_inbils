<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /** Existing customers start without consent: nothing is sent until staff record it. */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('whatsapp_opt_in_at')->nullable();
            $table->string('whatsapp_opt_in_source', 20)->nullable();
            $table->foreignId('whatsapp_opt_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('whatsapp_opt_out_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('whatsapp_opt_in_by');
            $table->dropColumn(['whatsapp_opt_in_at', 'whatsapp_opt_in_source', 'whatsapp_opt_out_at']);
        });
    }
};
