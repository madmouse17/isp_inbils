<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('symbol');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name']);
            $table->unique(['company_id', 'symbol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
