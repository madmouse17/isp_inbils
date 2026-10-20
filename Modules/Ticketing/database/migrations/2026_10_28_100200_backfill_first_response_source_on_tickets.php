<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    private const CHUNK = 1000;

    /**
     * Until now only the Mulai button set `first_response_at`, so every ticket that has a first response got it from a
     * start. Rows already marked are never touched, and `updated_at` stays as it is: it is the version a form carries.
     */
    public function up(): void
    {
        DB::table('tickets')
            ->whereNotNull('first_response_at')
            ->whereNull('first_response_source')
            ->chunkById(self::CHUNK, function ($rows): void {
                DB::table('tickets')
                    ->whereIn('id', $rows->pluck('id'))
                    ->update(['first_response_source' => 'start']);
            });
    }

    public function down(): void
    {
        DB::table('tickets')->where('first_response_source', 'start')->update(['first_response_source' => null]);
    }
};
