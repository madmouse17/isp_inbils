<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('source');
            $table->foreignId('category_id')->constrained('ticket_categories')->restrictOnDelete();
            $table->string('status')->default('open');
            $table->string('priority')->default('medium');
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('service_subscriptions')->restrictOnDelete();
            $table->foreignId('network_asset_id')->nullable()->constrained('network_assets')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('spawned_spk_id')->nullable()->constrained('work_orders')->restrictOnDelete();
            $table->timestamp('sla_deadline')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'source']);
            $table->index(['company_id', 'category_id']);
            $table->index(['company_id', 'assigned_to']);
            $table->index(['company_id', 'customer_id']);
            $table->index(['company_id', 'sla_deadline']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
