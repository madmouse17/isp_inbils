<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('title', 120);
            $table->text('body');
            $table->enum('kind', ['info', 'outage', 'maintenance']);
            $table->enum('audience', ['all', 'targeted'])->default('all');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'published_at', 'starts_at', 'ends_at'], 'announcements_feed_index');
            $table->index(['kind', 'notified_at', 'starts_at'], 'announcements_notify_index');
        });

        Schema::create('announcement_location', function (Blueprint $table): void {
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->primary(['announcement_id', 'location_id']);
            $table->index('location_id');
        });

        Schema::create('announcement_service_package_category', function (Blueprint $table): void {
            $table->foreignId('announcement_id')->constrained(indexName: 'announcement_category_announcement_foreign')->cascadeOnDelete();
            $table->foreignId('service_package_category_id')->constrained(indexName: 'announcement_category_category_foreign')->restrictOnDelete();
            $table->primary(['announcement_id', 'service_package_category_id'], 'announcement_package_category_primary');
            $table->index('service_package_category_id', 'announcement_package_category_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_service_package_category');
        Schema::dropIfExists('announcement_location');
        Schema::dropIfExists('announcements');
    }
};
