<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * The services a company runs on its own accounts (Midtrans, WhatsApp, e-mail, RADIUS), one row per company and
     * provider. `credentials` holds the secrets only, encrypted; `settings` holds what a screen may show. Additive:
     * nothing existing changes, so it is safe on a live database.
     */
    public function up(): void
    {
        Schema::create('company_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);
            $table->text('credentials')->nullable();
            $table->json('settings')->nullable();
            // The WhatsApp phone_number_id: the webhook finds its company by it. Not unique, because companies of one
            // group may share a number; each row is still guarded by its own app secret.
            $table->string('external_id', 100)->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'provider']);
            $table->index(['provider', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_integrations');
    }
};
