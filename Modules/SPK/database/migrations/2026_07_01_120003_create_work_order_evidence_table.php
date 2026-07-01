<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->string('caption')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('uploaded_at');
            $table->timestamps();

            $table->index(['company_id', 'work_order_id']);
            $table->index(['work_order_id', 'uploaded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_evidence');
    }
};
