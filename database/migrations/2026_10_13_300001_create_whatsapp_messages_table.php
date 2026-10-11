<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Delivery log of every WhatsApp template message and every reason one was not sent. Holds neither the
     * rendered text nor the parameters nor the full phone number: only a keyed hash and the last four digits.
     */
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('recipient_type', 20);
            $table->unsignedBigInteger('recipient_id');
            $table->string('event', 40);
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('template', 100);
            $table->string('language', 10);
            $table->string('dedupe_key', 150);
            $table->enum('status', ['queued', 'sent', 'delivered', 'read', 'failed', 'skipped'])->default('queued');
            $table->string('skip_reason', 30)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('provider_message_id', 191)->nullable()->unique();
            $table->char('recipient_hash', 64)->nullable();
            $table->char('recipient_last4', 4)->nullable();
            $table->boolean('billable')->nullable();
            $table->string('pricing_category', 40)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'dedupe_key']);
            $table->index(['company_id', 'recipient_type', 'recipient_id', 'created_at'], 'whatsapp_messages_recipient_index');
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
