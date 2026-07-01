<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('users')->restrictOnDelete();
            $table->string('reference_type');
            $table->unsignedBigInteger('reference_id');
            $table->decimal('score', 3, 1);
            $table->decimal('customer_rating', 3, 1)->nullable();
            $table->unsignedInteger('first_response_minutes')->nullable();
            $table->unsignedInteger('resolution_minutes')->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('evaluator_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('evaluated_at');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'employee_id']);
            $table->index(['company_id', 'reference_type', 'reference_id'], 'eval_ref_index');
            $table->index(['company_id', 'evaluator_id']);
            $table->index(['company_id', 'evaluated_at']);
            $table->unique(['reference_type', 'reference_id', 'evaluator_id'], 'eval_ref_evaluator_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_evaluations');
    }
};
