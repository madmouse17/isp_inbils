<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('speed_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name');
            $table->unsignedInteger('download_max_mbps');
            $table->unsignedInteger('upload_max_mbps');
            $table->unsignedInteger('burst_download_mbps')->nullable();
            $table->unsignedInteger('burst_upload_mbps')->nullable();
            $table->string('radius_profile_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('speed_profiles');
    }
};
