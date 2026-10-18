<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Service\Services\ServicePackageVersionBackfill;

return new class() extends Migration
{
    /**
     * A subscription points at the package version it was sold under (spec .scratch/master-lifecycle D3), in three
     * steps like App\Support\Database\PublicIdColumn: a nullable column, the backfill, then NOT NULL with the foreign
     * key. Each step checks what is already there, so a run that stopped half way (a package a stale writer inserted
     * between M1 and here still gets version 1 first) is repaired by running it again. `service_package_id` stays:
     * many readers filter by it, and both columns are always written together.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('service_subscriptions', 'service_package_version_id')) {
            Schema::table('service_subscriptions', function (Blueprint $table) {
                $table->unsignedBigInteger('service_package_version_id')->nullable()->after('service_package_id');
            });
        }

        ServicePackageVersionBackfill::versions();
        ServicePackageVersionBackfill::subscriptions();

        $hasForeignKey = $this->hasForeignKey();

        Schema::table('service_subscriptions', function (Blueprint $table) use ($hasForeignKey) {
            $table->unsignedBigInteger('service_package_version_id')->nullable(false)->change();

            if (! $hasForeignKey) {
                $table->foreign('service_package_version_id')->references('id')->on('service_package_versions')->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('service_subscriptions', 'service_package_version_id')) {
            return;
        }

        $hasForeignKey = $this->hasForeignKey();

        Schema::table('service_subscriptions', function (Blueprint $table) use ($hasForeignKey) {
            if ($hasForeignKey) {
                $table->dropForeign(['service_package_version_id']);
            }

            $table->dropColumn('service_package_version_id');
        });
    }

    private function hasForeignKey(): bool
    {
        foreach (Schema::getForeignKeys('service_subscriptions') as $foreignKey) {
            if ($foreignKey['columns'] === ['service_package_version_id']) {
                return true;
            }
        }

        return false;
    }
};
