<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Primary admin account for the dashboard.
        User::factory()->create([
            'name' => 'Admin Inbils',
            'email' => 'admin@inbils.test',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);

        // A handful of sample users for list views / tables.
        User::factory(10)->create();
    }
}
