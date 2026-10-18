<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    private const CHUNK = 500;

    /**
     * A completed SPK keeps the technician name, the location code and the technician's unit as they were at
     * completion (spec master-lifecycle D4). Completed SPKs that exist today get the live values once; rows that
     * already have a technician snapshot are never touched. Joins ignore soft deletes, so trashed users, locations
     * and profiles still count.
     */
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('work_orders', 'technician_name_snapshot')) {
                $table->string('technician_name_snapshot')->nullable();
            }

            if (! Schema::hasColumn('work_orders', 'location_code_snapshot')) {
                $table->string('location_code_snapshot')->nullable();
            }

            if (! Schema::hasColumn('work_orders', 'organization_unit_id')) {
                $table->foreignId('organization_unit_id')->nullable()->constrained('organization_units')->restrictOnDelete();
            }
        });

        DB::table('work_orders')
            ->where('status', 'completed')
            ->whereNull('technician_name_snapshot')
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($rows): void {
                DB::table('work_orders')
                    ->leftJoin('users', 'users.id', '=', 'work_orders.assigned_to')
                    ->leftJoin('locations', 'locations.id', '=', 'work_orders.location_id')
                    ->leftJoin('employee_profiles', 'employee_profiles.user_id', '=', 'work_orders.assigned_to')
                    ->whereIn('work_orders.id', $rows->pluck('id'))
                    ->whereNull('work_orders.technician_name_snapshot')
                    ->update([
                        'work_orders.technician_name_snapshot' => DB::raw('users.name'),
                        'work_orders.location_code_snapshot' => DB::raw('locations.code'),
                        'work_orders.organization_unit_id' => DB::raw('employee_profiles.organization_id'),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            if (Schema::hasColumn('work_orders', 'organization_unit_id')) {
                $table->dropConstrainedForeignId('organization_unit_id');
            }

            $present = array_values(array_filter(
                ['technician_name_snapshot', 'location_code_snapshot'],
                fn (string $column) => Schema::hasColumn('work_orders', $column),
            ));

            if ($present !== []) {
                $table->dropColumn($present);
            }
        });
    }
};
