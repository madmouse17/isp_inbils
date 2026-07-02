<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('entity_type');
            $table->string('prefix');
            $table->unsignedInteger('next_number')->default(1);
            $table->unsignedTinyInteger('padding')->default(5);
            $table->boolean('year_suffix')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'entity_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
