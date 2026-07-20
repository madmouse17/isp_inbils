<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            $table->foreignId('network_asset_id')->nullable()->after('product_id')->constrained('network_assets')->restrictOnDelete();
            $table->index(['work_order_id', 'network_asset_id']);
        });
    }

    public function down(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            $table->dropIndex(['work_order_id', 'network_asset_id']);
            $table->dropConstrainedForeignId('network_asset_id');
        });
    }
};