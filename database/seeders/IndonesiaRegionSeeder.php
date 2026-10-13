<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Laravolt\Indonesia\Models\Province;
use Laravolt\Indonesia\Models\Village;
use Laravolt\Indonesia\Seeds\DatabaseSeeder as LaravoltSeeder;

/** Provinces down to villages from laravolt/indonesia. Safe to re-run: a complete table set is left alone. */
class IndonesiaRegionSeeder extends Seeder
{
    private const PROVINCES = 38;

    public function run(): void
    {
        if (! self::needsSeeding()) {
            return;
        }

        // The laravolt seeder truncates all four tables and leaves foreign key checks off.
        try {
            $this->call(LaravoltSeeder::class);
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    public static function needsSeeding(): bool
    {
        return Province::query()->count() < self::PROVINCES || ! Village::query()->exists();
    }
}
