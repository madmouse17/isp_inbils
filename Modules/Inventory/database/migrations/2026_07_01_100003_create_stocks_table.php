<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->decimal('quantity', 15, 2)->default(0);
            $table->decimal('reserved_quantity', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'product_id', 'location_id']);
            $table->index(['company_id', 'location_id']);
            $table->index(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
