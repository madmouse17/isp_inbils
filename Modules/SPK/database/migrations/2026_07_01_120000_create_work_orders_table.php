<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code');
            $table->string('type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('service_subscriptions')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->string('source')->default('manual');
            $table->string('priority')->default('medium');
            $table->date('scheduled_date')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('result')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'type']);
            $table->index(['company_id', 'assigned_to']);
            $table->index(['company_id', 'customer_id']);
            $table->index(['company_id', 'subscription_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};
