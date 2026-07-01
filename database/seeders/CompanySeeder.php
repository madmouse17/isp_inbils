<?php

namespace Database\Seeders;

use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Services\Core\LocationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CompanySeeder extends Seeder
{
    public function runFor(Company $company): void
    {
        $this->seedUnits($company);
        $this->seedTicketCategories($company);
        $this->seedSlaTiers($company);
        $this->seedLocations($company);
    }

    private function seedUnits(Company $company): void
    {
        if (! Schema::hasTable('units') || ! Schema::hasColumns('units', ['company_id', 'name', 'is_active'])) {
            return;
        }

        foreach (['pcs', 'meter', 'roll', 'box'] as $name) {
            DB::table('units')->updateOrInsert(
                ['company_id' => $company->id, 'name' => $name],
                ['is_active' => true, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    private function seedTicketCategories(Company $company): void
    {
        if (! Schema::hasTable('ticket_categories') || ! Schema::hasColumns('ticket_categories', ['company_id', 'name', 'code', 'default_sla_hours', 'default_priority', 'is_active'])) {
            return;
        }

        foreach ([
            ['name' => 'No Internet', 'code' => 'no_internet', 'default_sla_hours' => 4, 'default_priority' => 'urgent'],
            ['name' => 'Slow Connection', 'code' => 'slow_connection', 'default_sla_hours' => 8, 'default_priority' => 'high'],
            ['name' => 'Packet Loss', 'code' => 'packet_loss', 'default_sla_hours' => 8, 'default_priority' => 'high'],
            ['name' => 'Device Issue', 'code' => 'device_issue', 'default_sla_hours' => 12, 'default_priority' => 'medium'],
            ['name' => 'Fiber Issue', 'code' => 'fiber_issue', 'default_sla_hours' => 12, 'default_priority' => 'high'],
        ] as $category) {
            DB::table('ticket_categories')->updateOrInsert(
                ['company_id' => $company->id, 'code' => $category['code']],
                [...$category, 'company_id' => $company->id, 'is_active' => true, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    private function seedSlaTiers(Company $company): void
    {
        if (! Schema::hasTable('sla_tiers') || ! Schema::hasColumns('sla_tiers', ['company_id', 'name', 'uptime_percentage', 'response_hours', 'is_active'])) {
            return;
        }

        foreach ([
            ['name' => 'Bronze', 'uptime_percentage' => 99, 'response_hours' => 24],
            ['name' => 'Silver', 'uptime_percentage' => 99.5, 'response_hours' => 8],
            ['name' => 'Gold', 'uptime_percentage' => 99.9, 'response_hours' => 4],
        ] as $tier) {
            DB::table('sla_tiers')->updateOrInsert(
                ['company_id' => $company->id, 'name' => $tier['name']],
                [...$tier, 'company_id' => $company->id, 'is_active' => true, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    private function seedLocations(Company $company): void
    {
        if (! Schema::hasTable('locations')) {
            return;
        }

        if (Location::withoutCompany()->where('company_id', $company->id)->exists()) {
            return;
        }

        $region = LocationService::create(['code' => 'REG-01', 'name' => 'Default Region', 'type' => 'region', 'is_active' => true]);
        $area = LocationService::create(['parent_id' => $region->id, 'code' => 'AREA-01', 'name' => 'Default Area', 'type' => 'area', 'is_active' => true]);
        LocationService::create(['parent_id' => $area->id, 'code' => 'POP-01', 'name' => 'Default POP', 'type' => 'pop', 'is_active' => true]);
    }
}
