<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * One row per inbound WhatsApp message (a STOP reply) already handled for a company, so that Meta's at-least-once
     * delivery cannot apply the same reply twice. It keeps a keyed hash of the message id (Meta ids embed the sender's
     * number) and nothing else: no number, no text.
     */
    public function up(): void
    {
        Schema::create('whatsapp_inbound_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->char('message_hash', 64);
            $table->timestamps();

            $table->unique(['company_id', 'message_hash']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_inbound_receipts');
    }
};
