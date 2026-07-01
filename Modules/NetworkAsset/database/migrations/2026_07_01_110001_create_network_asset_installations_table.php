<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_asset_installations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('network_asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('service_subscriptions')->restrictOnDelete();
            $table->unsignedBigInteger('spk_id')->nullable();
            $table->foreignId('installed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('installed_at');
            $table->timestamp('removed_at')->nullable();
            $table->string('removal_reason')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'network_asset_id']);
            $table->index(['company_id', 'location_id']);
            $table->index(['company_id', 'customer_id']);
            $table->index(['network_asset_id', 'removed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_asset_installations');
    }
};
