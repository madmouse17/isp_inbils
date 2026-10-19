<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        // One row per physical port that is not in the ODN register (PON ports and splitter outputs stay in odn_ports).
        Schema::create('asset_ports', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('network_asset_id')->constrained('network_assets')->restrictOnDelete();
            // The block group of the layout ("ether", "sfp", "uplink", "lan"...); semantic, so a new model keeps the links.
            $table->string('group_key', 16);
            $table->unsignedSmallInteger('number');
            $table->enum('kind', ['rj45', 'sfp', 'sc', 'rj11']);
            // The vendor name derived when the model was applied: "ether1", "sfp-sfpplus1", "LAN1".
            $table->string('name', 32);
            $table->string('label', 64)->nullable();
            $table->string('note', 255)->nullable();
            $table->boolean('admin_down')->default(false);
            // A link is stored in both rows. The column is unique, so a port is the target of at most one other.
            // MySQL refuses a CHECK on an AUTO_INCREMENT column, so "not itself" is a service rule.
            $table->unsignedBigInteger('linked_port_id')->nullable()->unique();
            $table->timestamps();

            $table->unique(['network_asset_id', 'group_key', 'number']);
            $table->index(['company_id', 'network_asset_id']);
            $table->foreign('linked_port_id')->references('id')->on('asset_ports')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_ports');
    }
};
