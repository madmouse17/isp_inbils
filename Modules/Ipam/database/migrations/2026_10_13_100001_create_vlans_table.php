<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('vlans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->unsignedSmallInteger('vid');
            $table->string('name', 64);
            $table->string('description')->nullable();
            $table->timestamps();

            // MySQL treats NULL location_id as distinct, so company-wide duplicates are refused in VlanService.
            $table->unique(['company_id', 'location_id', 'vid']);
        });

        DB::statement('ALTER TABLE vlans ADD CONSTRAINT vlans_vid_range CHECK (vid BETWEEN 1 AND 4094)');
    }

    public function down(): void
    {
        Schema::dropIfExists('vlans');
    }
};
