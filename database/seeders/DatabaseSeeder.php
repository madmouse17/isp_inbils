<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            SystemSettingSeeder::class,
        ]);

        // Local/dev convenience: one-shot demo company + sample data.
        // Production install remains bootstrap command + setup wizard.
        if (app()->environment(['local', 'development', 'testing'])) {
            $this->call([
                DemoUserSeeder::class,
                CustomerDemoSeeder::class,
                E2eFixtureSeeder::class,
            ]);
        }
    }
}
