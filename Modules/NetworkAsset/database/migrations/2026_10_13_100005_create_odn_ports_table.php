<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('odn_ports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            // The OLT for a pon port; the host odc/odp for a splitter_out port.
            $table->foreignId('network_asset_id')->constrained('network_assets')->restrictOnDelete();
            $table->foreignId('odn_splitter_id')->nullable()->constrained('odn_splitters')->restrictOnDelete();
            $table->enum('kind', ['pon', 'splitter_out']);
            $table->unsignedSmallInteger('number');
            $table->string('label')->nullable();
            $table->foreignId('subscription_id')->nullable()->constrained('service_subscriptions')->restrictOnDelete();
            // Lets MySQL enforce "one PON number per OLT"; NULL for splitter outputs, which MySQL keeps distinct.
            $table->unsignedSmallInteger('pon_number')->nullable()->storedAs("case when `kind` = 'pon' then `number` else null end");
            $table->timestamps();

            $table->unique('subscription_id');
            $table->unique(['network_asset_id', 'pon_number']);
            $table->unique(['odn_splitter_id', 'number']);
            $table->index(['company_id', 'network_asset_id']);
        });

        // A PON port belongs to no splitter and never holds a customer; an output belongs to exactly one splitter.
        DB::statement(
            'ALTER TABLE odn_ports ADD CONSTRAINT odn_ports_kind_consistent CHECK ('
            ."(kind = 'pon' AND odn_splitter_id IS NULL AND subscription_id IS NULL)"
            ." OR (kind = 'splitter_out' AND odn_splitter_id IS NOT NULL))"
        );

        Schema::table('odn_splitters', function (Blueprint $table) {
            $table->foreign('input_port_id')->references('id')->on('odn_ports')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('odn_splitters', function (Blueprint $table) {
            $table->dropForeign(['input_port_id']);
        });

        Schema::dropIfExists('odn_ports');
    }
};
