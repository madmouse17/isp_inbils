<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('material_return_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('inventory_request_id')->unique()->constrained('inventory_requests')->cascadeOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->foreignId('warehouse_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('technician_id')->constrained('users');
            $table->string('status')->default('awaiting_declaration'); // awaiting_declaration, awaiting_receipt, correction_required, reconciled, exception_open, resolved
            $table->unsignedInteger('declaration_version')->default(1);
            $table->foreignId('declared_by')->nullable()->constrained('users');
            $table->dateTime('declared_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users');
            $table->dateTime('received_at')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users');
            $table->dateTime('reconciled_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users');
            $table->dateTime('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->dateTime('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key'], 'mrc_idempotency_unique');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'technician_id']);
            $table->index(['company_id', 'work_order_id']);
        });

        Schema::create('material_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('material_return_case_id')->constrained('material_return_cases')->cascadeOnDelete();
            $table->foreignId('inventory_request_item_id')->constrained('inventory_request_items')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->decimal('issued_qty', 15, 2);
            $table->decimal('actual_used_qty', 15, 2)->default(0);
            $table->decimal('declared_good_return_qty', 15, 2)->default(0);
            $table->decimal('declared_discrepancy_qty', 15, 2)->default(0);
            $table->decimal('accepted_good_return_qty', 15, 2)->default(0);
            $table->decimal('damaged_return_qty', 15, 2)->default(0);
            $table->decimal('lost_qty', 15, 2)->default(0);
            $table->decimal('other_discrepancy_qty', 15, 2)->default(0);
            $table->string('discrepancy_code')->nullable(); // damaged, lost, other
            $table->text('discrepancy_reason')->nullable();
            $table->timestamps();

            $table->unique(['material_return_case_id', 'inventory_request_item_id'], 'mrc_item_unique');
            $table->index(['company_id', 'product_id']);
        });

        Schema::create('material_return_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('material_return_case_id')->constrained('material_return_cases')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('action'); // created, declared, received_accepted, received_rejected, exception_opened, resolved
            $table->foreignId('actor_id')->constrained('users');
            $table->text('reason')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'material_return_case_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_return_events');
        Schema::dropIfExists('material_return_lines');
        Schema::dropIfExists('material_return_cases');
    }
};
