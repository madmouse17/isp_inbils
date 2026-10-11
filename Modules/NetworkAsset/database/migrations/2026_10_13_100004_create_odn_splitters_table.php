<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('odn_splitters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            // Host asset: an odc or odp (type enforced by OdnService).
            $table->foreignId('network_asset_id')->constrained('network_assets')->restrictOnDelete();
            $table->unsignedTinyInteger('outputs');
            // Upstream port (a PON port or another splitter's output). The foreign key is added by
            // create_odn_ports_table because the two tables reference each other.
            $table->unsignedBigInteger('input_port_id')->nullable();
            $table->string('label')->nullable();
            $table->timestamps();

            $table->unique('input_port_id');
            $table->index(['company_id', 'network_asset_id']);
        });

        DB::statement('ALTER TABLE odn_splitters ADD CONSTRAINT odn_splitters_outputs_valid CHECK (outputs IN (2, 4, 8, 16, 32, 64))');
    }

    public function down(): void
    {
        Schema::dropIfExists('odn_splitters');
    }
};
