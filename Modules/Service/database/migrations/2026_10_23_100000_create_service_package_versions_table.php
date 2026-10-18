<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Service\Services\ServicePackageVersionBackfill;

return new class() extends Migration
{
    /**
     * Append-only history of the terms a package was sold under (spec .scratch/master-lifecycle D3). The table is
     * created only when missing and every package without a version gets version 1, so a run that stopped half way
     * is repaired by running it again. The package owns its versions (cascade); a version a subscription points at
     * still blocks the delete through the subscription's own foreign key.
     */
    public function up(): void
    {
        if (! Schema::hasTable('service_package_versions')) {
            Schema::create('service_package_versions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('service_package_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('version');
                $table->decimal('price_mrc', 15, 2);
                $table->decimal('price_otc', 15, 2)->default(0);
                $table->foreignId('bandwidth_profile_id')->constrained()->restrictOnDelete();
                $table->foreignId('speed_profile_id')->constrained()->restrictOnDelete();
                $table->foreignId('sla_tier_id')->constrained('sla_tiers')->restrictOnDelete();
                $table->foreignId('service_package_category_id')->constrained()->restrictOnDelete();
                $table->timestamp('valid_from');
                $table->timestamps();

                $table->unique(['service_package_id', 'version']);
                // Named by hand: the default name is 69 characters, MySQL stops at 64.
                $table->index(['company_id', 'service_package_category_id'], 'service_package_versions_company_category_index');
            });
        }

        ServicePackageVersionBackfill::versions();
    }

    public function down(): void
    {
        Schema::dropIfExists('service_package_versions');
    }
};
