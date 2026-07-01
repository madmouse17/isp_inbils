<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_package_id')->constrained()->restrictOnDelete();
            $table->foreignId('installation_address_id')->constrained('customer_addresses')->restrictOnDelete();
            $table->string('code');
            $table->string('status')->default('pending');
            $table->date('activation_date')->nullable();
            $table->date('expiration_date')->nullable();
            $table->unsignedTinyInteger('billing_day')->default(1);
            $table->date('next_invoice_date')->nullable();
            $table->unsignedBigInteger('ont_asset_id')->nullable();
            $table->foreignId('serving_pop_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->decimal('mrc_amount', 15, 2);
            $table->decimal('otc_installation_fee', 15, 2)->default(0);
            $table->integer('contract_months')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->text('terminated_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'customer_id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'serving_pop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_subscriptions');
    }
};
