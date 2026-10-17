<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('promo_banners', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('title', 80);
            $table->string('alt_text', 200);
            $table->enum('link_kind', ['none', 'app', 'url'])->default('none');
            $table->string('link_target', 500)->nullable();
            $table->enum('audience', ['all', 'targeted'])->default('all');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('tap_count')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'published_at', 'starts_at', 'ends_at'], 'promo_banners_feed_index');
            $table->index(['ends_at', 'id'], 'promo_banners_cleanup_index');
        });

        Schema::create('location_promo_banner', function (Blueprint $table): void {
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('promo_banner_id')->constrained()->cascadeOnDelete();
            $table->primary(['location_id', 'promo_banner_id']);
            $table->index('promo_banner_id');
        });

        Schema::create('promo_banner_service_package_category', function (Blueprint $table): void {
            $table->foreignId('promo_banner_id')->constrained(indexName: 'promo_category_banner_foreign')->cascadeOnDelete();
            $table->foreignId('service_package_category_id')->constrained(indexName: 'promo_category_category_foreign')->restrictOnDelete();
            $table->primary(['promo_banner_id', 'service_package_category_id'], 'promo_banner_category_primary');
            $table->index('service_package_category_id', 'promo_banner_category_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_banner_service_package_category');
        Schema::dropIfExists('location_promo_banner');
        Schema::dropIfExists('promo_banners');
    }
};
