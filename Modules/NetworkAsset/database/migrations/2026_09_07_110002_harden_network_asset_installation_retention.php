<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('network_asset_installations', function (Blueprint $table) {
            $table->dropForeign(['network_asset_id']);
            $table->foreign('network_asset_id')
                ->references('id')
                ->on('network_assets')
                ->restrictOnDelete();

            $table->string('asset_code_snapshot', 64)->nullable()->after('network_asset_id');
            $table->string('asset_serial_snapshot', 128)->nullable()->after('asset_code_snapshot');
        });
    }

    public function down(): void
    {
        // Non-destructive rollback contract: retain restrictOnDelete constraint and snapshot columns
        // (asset_code_snapshot, asset_serial_snapshot) so installation history cannot be cascaded or lost.
    }
};
