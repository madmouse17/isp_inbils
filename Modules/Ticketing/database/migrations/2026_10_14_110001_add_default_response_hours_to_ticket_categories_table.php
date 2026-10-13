<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * The default is half of default_sla_hours, rounded up. It fills the rows that exist today and every
     * row inserted without the column, so a category never lacks a response target.
     */
    public function up(): void
    {
        Schema::table('ticket_categories', function (Blueprint $table) {
            if (! Schema::hasColumn('ticket_categories', 'default_response_hours')) {
                $table->unsignedInteger('default_response_hours')
                    ->default(new Expression('(CEIL(default_sla_hours / 2))'))
                    ->after('default_sla_hours');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ticket_categories', function (Blueprint $table) {
            if (Schema::hasColumn('ticket_categories', 'default_response_hours')) {
                $table->dropColumn('default_response_hours');
            }
        });
    }
};
