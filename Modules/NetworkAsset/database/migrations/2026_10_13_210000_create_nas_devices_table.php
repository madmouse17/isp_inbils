<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('nas_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            // The router address is the asset's management_ip, never a copy.
            $table->foreignId('network_asset_id')->constrained('network_assets')->restrictOnDelete();
            $table->unsignedSmallInteger('api_port')->default(443);
            $table->string('api_username', 64);
            $table->text('api_password'); // encrypted cast: the ciphertext is longer than the secret
            $table->string('isolir_profile', 64)->default('isolir');
            $table->boolean('provisioning_enabled')->default(false); // per-router write flag
            $table->string('routeros_version', 64)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('unreachable_since')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->unsignedInteger('unmanaged_count')->default(0);
            $table->json('unmanaged_usernames')->nullable();
            $table->timestamps();

            $table->unique('network_asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nas_devices');
    }
};
