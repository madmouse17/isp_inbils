<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('ip_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            // Smallest registered prefix containing the address; null = outside every prefix (legacy asset IPs).
            $table->foreignId('ip_prefix_id')->nullable()->constrained('ip_prefixes')->restrictOnDelete();
            $table->unsignedTinyInteger('family');
            $table->string('address', 39);
            $table->string('address_cmp', 39)->charset('ascii')->collation('ascii_bin');
            $table->enum('status', ['assigned', 'reserved']);
            $table->foreignId('subscription_id')->nullable()->constrained('service_subscriptions')->restrictOnDelete();
            $table->foreignId('network_asset_id')->nullable()->constrained('network_assets')->restrictOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The conflict backstop: one holder per address in a company, one address per family per subscription.
            $table->unique(['company_id', 'address_cmp']);
            $table->unique(['subscription_id', 'family']);
        });

        DB::statement('ALTER TABLE ip_addresses ADD CONSTRAINT ip_addresses_family_valid CHECK (family IN (4, 6))');
        // Reserved rows hold nothing; assigned rows are held by exactly one subscription or one asset.
        DB::statement(
            'ALTER TABLE ip_addresses ADD CONSTRAINT ip_addresses_holder_consistent CHECK ('
            ."(status = 'reserved' AND subscription_id IS NULL AND network_asset_id IS NULL)"
            ." OR (status = 'assigned' AND ((subscription_id IS NOT NULL) + (network_asset_id IS NOT NULL)) = 1))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_addresses');
    }
};
