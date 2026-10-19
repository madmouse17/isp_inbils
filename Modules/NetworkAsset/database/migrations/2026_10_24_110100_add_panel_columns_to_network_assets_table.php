<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('network_assets', function (Blueprint $table) {
            // Which catalogue row the snapshot came from; null for a custom layout. Deleting the row keeps the snapshot.
            $table->string('template_key', 64)->nullable()->after('model');
            // The layout as it was when the model was applied: editing a catalogue row never moves an asset's ports.
            $table->json('port_layout')->nullable()->after('template_key');
            $table->foreignId('rack_asset_id')->nullable()->after('location_id')->constrained('network_assets')->restrictOnDelete();
            // Lowest rack unit the device occupies, U1 at the bottom.
            $table->unsignedTinyInteger('rack_unit')->nullable()->after('rack_asset_id');
            // The height of a device; for a rack, its size.
            $table->unsignedTinyInteger('height_u')->nullable()->after('rack_unit');

            $table->foreign('template_key')->references('key')->on('port_templates')->nullOnDelete();
            $table->unique(['rack_asset_id', 'rack_unit']);
        });
    }

    public function down(): void
    {
        Schema::table('network_assets', function (Blueprint $table) {
            $table->dropForeign(['template_key']);
            $table->dropForeign(['rack_asset_id']);
            $table->dropUnique(['rack_asset_id', 'rack_unit']);
        });

        Schema::table('network_assets', function (Blueprint $table) {
            $table->dropColumn(['template_key', 'port_layout', 'rack_asset_id', 'rack_unit', 'height_u']);
        });
    }
};
