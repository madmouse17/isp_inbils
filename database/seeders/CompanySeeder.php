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
        $this->seedBandwidthProfiles($company);
        $this->seedSpeedProfiles($company);
        $this->seedServicePackages($company);
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
        if (! Schema::hasTable('sla_tiers') || ! Schema::hasColumns('sla_tiers', ['company_id', 'name', 'uptime_pct', 'response_time_hours', 'resolution_time_hours', 'credit_pct'])) {
            return;
        }

        foreach ([
            ['name' => 'Bronze', 'uptime_pct' => 99.00, 'response_time_hours' => 24, 'resolution_time_hours' => 48, 'credit_pct' => 5],
            ['name' => 'Silver', 'uptime_pct' => 99.50, 'response_time_hours' => 8, 'resolution_time_hours' => 24, 'credit_pct' => 10],
            ['name' => 'Gold', 'uptime_pct' => 99.90, 'response_time_hours' => 4, 'resolution_time_hours' => 12, 'credit_pct' => 15],
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

    private function seedBandwidthProfiles(Company $company): void
    {
        if (! Schema::hasTable('bandwidth_profiles')) {
            return;
        }

        if (DB::table('bandwidth_profiles')->where('company_id', $company->id)->exists()) {
            return;
        }

        $profiles = [
            ['name' => 'Home 10Mbps', 'download_mbps' => 10, 'upload_mbps' => 2, 'type' => 'shared', 'contention_ratio' => 8],
            ['name' => 'Home 20Mbps', 'download_mbps' => 20, 'upload_mbps' => 5, 'type' => 'shared', 'contention_ratio' => 4],
            ['name' => 'Home 50Mbps', 'download_mbps' => 50, 'upload_mbps' => 10, 'type' => 'shared', 'contention_ratio' => 4],
            ['name' => 'Business 100Mbps', 'download_mbps' => 100, 'upload_mbps' => 100, 'type' => 'dedicated', 'contention_ratio' => 1],
            ['name' => 'Business 200Mbps', 'download_mbps' => 200, 'upload_mbps' => 200, 'type' => 'dedicated', 'contention_ratio' => 1],
        ];

        foreach ($profiles as $p) {
            DB::table('bandwidth_profiles')->insert([
                ...$p,
                'company_id' => $company->id,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedSpeedProfiles(Company $company): void
    {
        if (! Schema::hasTable('speed_profiles')) {
            return;
        }

        if (DB::table('speed_profiles')->where('company_id', $company->id)->exists()) {
            return;
        }

        $profiles = [
            ['name' => 'SP-Home-10', 'download_max_mbps' => 10, 'upload_max_mbps' => 2, 'burst_download_mbps' => 15, 'burst_upload_mbps' => 3, 'radius_profile_name' => 'home-10m'],
            ['name' => 'SP-Home-20', 'download_max_mbps' => 20, 'upload_max_mbps' => 5, 'burst_download_mbps' => 30, 'burst_upload_mbps' => 7, 'radius_profile_name' => 'home-20m'],
            ['name' => 'SP-Home-50', 'download_max_mbps' => 50, 'upload_max_mbps' => 10, 'burst_download_mbps' => 75, 'burst_upload_mbps' => 15, 'radius_profile_name' => 'home-50m'],
            ['name' => 'SP-Biz-100', 'download_max_mbps' => 100, 'upload_max_mbps' => 100, 'burst_download_mbps' => null, 'burst_upload_mbps' => null, 'radius_profile_name' => 'biz-100m'],
            ['name' => 'SP-Biz-200', 'download_max_mbps' => 200, 'upload_max_mbps' => 200, 'burst_download_mbps' => null, 'burst_upload_mbps' => null, 'radius_profile_name' => 'biz-200m'],
        ];

        foreach ($profiles as $p) {
            DB::table('speed_profiles')->insert([
                ...$p,
                'company_id' => $company->id,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedServicePackages(Company $company): void
    {
        if (! Schema::hasTable('service_packages')) {
            return;
        }

        if (DB::table('service_packages')->where('company_id', $company->id)->exists()) {
            return;
        }

        $bwIds = DB::table('bandwidth_profiles')->where('company_id', $company->id)->orderBy('id')->pluck('id')->toArray();
        $spIds = DB::table('speed_profiles')->where('company_id', $company->id)->orderBy('id')->pluck('id')->toArray();
        $slaIds = DB::table('sla_tiers')->where('company_id', $company->id)->orderBy('id')->pluck('id')->toArray();

        $packages = [
            ['code' => 'PKG-HOME-10', 'name' => 'Home 10Mbps', 'price_mrc' => 150000, 'price_otc' => 250000, 'contract_min_months' => 12],
            ['code' => 'PKG-HOME-20', 'name' => 'Home 20Mbps', 'price_mrc' => 250000, 'price_otc' => 300000, 'contract_min_months' => 12],
            ['code' => 'PKG-HOME-50', 'name' => 'Home 50Mbps', 'price_mrc' => 350000, 'price_otc' => 350000, 'contract_min_months' => 12],
            ['code' => 'PKG-BIZ-100', 'name' => 'Business 100Mbps', 'price_mrc' => 750000, 'price_otc' => 500000, 'contract_min_months' => 24],
            ['code' => 'PKG-BIZ-200', 'name' => 'Business 200Mbps', 'price_mrc' => 1500000, 'price_otc' => 750000, 'contract_min_months' => 24],
            ['code' => 'PKG-HOME-20-PROMO', 'name' => 'Home 20Mbps Promo', 'price_mrc' => 199000, 'price_otc' => 0, 'contract_min_months' => 6],
            ['code' => 'PKG-BIZ-100-GOLD', 'name' => 'Business 100Mbps Gold', 'price_mrc' => 950000, 'price_otc' => 500000, 'contract_min_months' => 24],
            ['code' => 'PKG-TRIAL', 'name' => 'Trial 5Mbps', 'price_mrc' => 50000, 'price_otc' => 0, 'contract_min_months' => null],
        ];

        foreach ($packages as $i => $p) {
            DB::table('service_packages')->insert([
                ...$p,
                'company_id' => $company->id,
                'bandwidth_profile_id' => $bwIds[$i % count($bwIds)] ?? null,
                'speed_profile_id' => $spIds[$i % count($spIds)] ?? null,
                'sla_tier_id' => $slaIds[min(intdiv($i, 2), count($slaIds) - 1)] ?? null,
                'description' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
