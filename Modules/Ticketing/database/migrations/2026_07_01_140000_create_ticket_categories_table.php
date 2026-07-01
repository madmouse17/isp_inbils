<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ticket_categories')) {
            Schema::create('ticket_categories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->restrictOnDelete();
                $table->string('name');
                $table->string('code');
                $table->unsignedInteger('default_sla_hours');
                $table->string('default_priority')->default('medium');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['company_id', 'code']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_categories');
    }
};
