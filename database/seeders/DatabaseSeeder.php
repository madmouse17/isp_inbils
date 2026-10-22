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

        // Reference data for every install; tests and E2E seed their own small subset instead (~80k village rows per reset).
        if (! app()->environment(['testing', 'e2e'])) {
            $this->call(IndonesiaRegionSeeder::class);
        }

        // Local/dev convenience: one-shot demo company + sample data.
        // Production install remains bootstrap command + setup wizard.
        if (app()->environment(['local', 'development', 'testing'])) {
            $this->call([
                DemoUserSeeder::class,
                DemoDataSeeder::class,
                DemoTeamSeeder::class,
                CustomerDemoSeeder::class,
            ]);
        }

        // E2E fixtures (E2E-SUB and friends) never reach local/development; tests/e2e/setup.php seeds them for APP_ENV=e2e.
        if (app()->environment('testing')) {
            $this->call(E2eFixtureSeeder::class);
        }
    }
}
