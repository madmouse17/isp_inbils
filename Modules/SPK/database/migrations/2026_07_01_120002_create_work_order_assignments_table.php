<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('unassigned_at')->nullable();
            $table->foreignId('unassigned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'work_order_id']);
            $table->index(['company_id', 'technician_id']);
            $table->index(['work_order_id', 'unassigned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_assignments');
    }
};
