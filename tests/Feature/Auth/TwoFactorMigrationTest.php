<?php

namespace Tests\Feature\Auth;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TwoFactorMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_12_500001_add_two_factor_columns_to_users_table.php';

    private const COLUMNS = [
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'two_factor_last_used_step',
    ];

    /**
     * MySQL commits on DDL, so the user row this test writes is real: the test removes it, and puts
     * the columns back, before it ends.
     */
    public function test_the_migration_rolls_back_and_forward_without_touching_users(): void
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Kept Through The Migration',
            'email' => 'kept-through-2fa-migration@example.test',
            'password' => 'not-a-real-hash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $migration = $this->migration();

        try {
            $migration->down();

            foreach (self::COLUMNS as $column) {
                $this->assertFalse(Schema::hasColumn('users', $column), "{$column} should be gone after down()");
            }
            $this->assertSame('Kept Through The Migration', DB::table('users')->where('id', $id)->value('name'));

            $migration->up();

            foreach (self::COLUMNS as $column) {
                $this->assertTrue(Schema::hasColumn('users', $column), "{$column} should exist after up()");
            }
            $row = DB::table('users')->where('id', $id)->first();
            $this->assertSame('Kept Through The Migration', $row->name);
            foreach (self::COLUMNS as $column) {
                $this->assertNull($row->{$column}, "{$column} starts empty for existing users");
            }
        } finally {
            if (! Schema::hasColumn('users', 'two_factor_secret')) {
                $migration->up();
            }
            DB::table('users')->where('id', $id)->delete();
        }
    }

    private function migration(): Migration
    {
        return require database_path('migrations/'.self::MIGRATION);
    }
}
