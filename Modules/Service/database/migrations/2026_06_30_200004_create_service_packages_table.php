<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code');
            $table->string('name');
            $table->foreignId('bandwidth_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('speed_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('sla_tier_id')->constrained('sla_tiers')->restrictOnDelete();
            $table->decimal('price_mrc', 15, 2);
            $table->decimal('price_otc', 15, 2)->default(0);
            $table->unsignedInteger('contract_min_months')->default(0);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'name']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_packages');
    }
};
