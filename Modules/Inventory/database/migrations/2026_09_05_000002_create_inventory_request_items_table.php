<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('inventory_request_id')->constrained('inventory_requests')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('source_location_id')->nullable()->constrained('locations');
            $table->decimal('quantity_requested', 15, 2);
            $table->decimal('quantity_approved', 15, 2)->nullable();
            $table->decimal('quantity_issued', 15, 2)->default(0);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['inventory_request_id', 'product_id']);
            $table->index(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_request_items');
    }
};
