<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('asset_type');
            $table->string('serial_number')->nullable();
            $table->string('mac_address')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('management_ip')->nullable();
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('service_subscriptions')->restrictOnDelete();
            $table->string('status')->default('available');
            $table->string('ownership')->default('owned');
            $table->string('vendor')->nullable();
            $table->string('model')->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_price', 15, 2)->nullable();
            $table->date('warranty_expiry')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'serial_number']);
            $table->index(['company_id', 'asset_type']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'location_id']);
            $table->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_assets');
    }
};
