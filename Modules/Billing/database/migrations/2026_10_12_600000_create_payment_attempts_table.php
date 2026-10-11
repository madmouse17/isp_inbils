<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            // Midtrans needs one order_id per merchant, and the merchant is shared by every company.
            $table->string('order_id', 50)->unique();
            $table->decimal('amount', 15, 2);
            $table->enum('status', ['pending', 'paid', 'failed', 'expired', 'exception'])->default('pending');
            $table->string('status_reason')->nullable();
            $table->string('snap_token')->nullable();
            $table->text('redirect_url')->nullable();
            $table->string('transaction_id')->nullable();
            $table->string('payment_type')->nullable();
            $table->json('last_notification')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('settled_at')->nullable();
            // The immutable ledger row written on settlement; unique so one payment closes one attempt.
            $table->foreignId('payment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'invoice_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
