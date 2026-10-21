<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * A role without a company would be global in every tenant (ADR-004), so after the cutover the column is required.
     * A global role that survived the cutover stops the migration here instead of failing inside MySQL.
     */
    public function up(): void
    {
        if (DB::table('roles')->whereNull('company_id')->exists()) {
            throw new RuntimeException('A role without a company is left: the cutover migration must copy the global roles into the companies first.');
        }

        Schema::table('roles', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable(false)->change();
        });
    }

    /** Lets the cutover migration's down() create the global roles again. */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->change();
        });
    }
};
