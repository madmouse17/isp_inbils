<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('network_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->constrained('service_subscriptions')->restrictOnDelete();
            $table->foreignId('nas_device_id')->constrained('nas_devices')->restrictOnDelete();
            $table->enum('service_type', ['pppoe', 'hotspot'])->default('pppoe');
            $table->string('username', 64);
            $table->text('password'); // encrypted cast: the ciphertext is longer than the secret

            // What the router holds now (the last successful apply); provisioned_profile null = absent there.
            $table->foreignId('provisioned_nas_device_id')->nullable()->constrained('nas_devices')->restrictOnDelete();
            $table->string('provisioned_username', 64)->nullable();
            $table->string('provisioned_profile', 64)->nullable();
            $table->enum('provisioned_service_type', ['pppoe', 'hotspot'])->nullable(); // so a type change can clean up the old menu
            // HMAC of the password last pushed: the router never gives passwords back, so this is how a change is seen.
            $table->char('provisioned_password_hash', 64)->nullable();
            $table->timestamp('provisioned_at')->nullable();

            $table->enum('sync_status', ['pending', 'synced', 'failed'])->default('pending');
            $table->string('last_error', 255)->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_online_at')->nullable();
            $table->string('current_address', 45)->nullable();
            $table->timestamps();

            $table->unique('subscription_id');
            $table->unique(['company_id', 'username']);
            $table->index(['company_id', 'nas_device_id', 'sync_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_accounts');
    }
};
