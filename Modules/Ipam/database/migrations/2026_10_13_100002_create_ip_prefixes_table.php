<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('ip_prefixes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('ip_prefixes')->restrictOnDelete();
            $table->unsignedTinyInteger('family');
            $table->string('cidr', 43);
            // Fixed-width comparable strings (ip-lib): "is this address inside the prefix" is a plain BETWEEN.
            $table->string('range_from', 39)->charset('ascii')->collation('ascii_bin');
            $table->string('range_to', 39)->charset('ascii')->collation('ascii_bin');
            $table->unsignedTinyInteger('prefix_length');
            $table->enum('usage', ['container', 'customer_static', 'customer_dynamic', 'infrastructure']);
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->foreignId('vlan_id')->nullable()->constrained('vlans')->restrictOnDelete();
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'cidr']);
            $table->index(['company_id', 'family', 'range_from']);
        });

        DB::statement('ALTER TABLE ip_prefixes ADD CONSTRAINT ip_prefixes_family_valid CHECK (family IN (4, 6))');
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_prefixes');
    }
};
