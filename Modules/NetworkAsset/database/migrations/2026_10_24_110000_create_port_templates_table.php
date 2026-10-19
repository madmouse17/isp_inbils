<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        // The catalogue of device faces: shared reference data like `settings`, so no company_id and no public_id.
        Schema::create('port_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('vendor', 64);
            $table->string('model', 128);
            $table->enum('asset_type', ['router', 'switch', 'olt', 'onu_ont', 'odc', 'odp']);
            // Rack units; null for a desktop or wall unit.
            $table->unsignedTinyInteger('height_u')->nullable();
            $table->json('layout');
            // The datasheet the row was checked against.
            $table->string('source_url', 255)->nullable();
            $table->timestamps();

            $table->index('asset_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('port_templates');
    }
};
