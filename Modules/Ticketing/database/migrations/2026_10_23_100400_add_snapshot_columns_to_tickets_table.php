<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    private const CHUNK = 500;

    /**
     * A closed ticket keeps the category and assignee names as they were at closing (spec master-lifecycle D4/D5).
     * Closed tickets that exist today get the live names once; rows that already have a category snapshot are never
     * touched. Joins ignore soft deletes, so trashed categories and users still count.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('tickets', 'category_name_snapshot')) {
                $table->string('category_name_snapshot')->nullable();
            }

            if (! Schema::hasColumn('tickets', 'assignee_name_snapshot')) {
                $table->string('assignee_name_snapshot')->nullable();
            }
        });

        DB::table('tickets')
            ->where('status', 'closed')
            ->whereNull('category_name_snapshot')
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($rows): void {
                DB::table('tickets')
                    ->join('ticket_categories', 'ticket_categories.id', '=', 'tickets.category_id')
                    ->leftJoin('users', 'users.id', '=', 'tickets.assigned_to')
                    ->whereIn('tickets.id', $rows->pluck('id'))
                    ->whereNull('tickets.category_name_snapshot')
                    ->update([
                        'tickets.category_name_snapshot' => DB::raw('ticket_categories.name'),
                        'tickets.assignee_name_snapshot' => DB::raw('users.name'),
                    ]);
            });
    }

    public function down(): void
    {
        $present = array_values(array_filter(
            ['category_name_snapshot', 'assignee_name_snapshot'],
            fn (string $column) => Schema::hasColumn('tickets', $column),
        ));

        if ($present !== []) {
            Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn($present));
        }
    }
};
