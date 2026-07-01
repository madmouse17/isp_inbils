<?php

namespace Modules\Service\Database\Seeders;

use App\Models\Core\Company;
use Illuminate\Database\Seeder;
use Modules\Service\Models\BandwidthProfile;
use Modules\Service\Models\ServicePackage;
use Modules\Service\Models\SLATier;
use Modules\Service\Models\SpeedProfile;

class ServiceCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $companyId = Company::query()->value('id');

        if ($companyId !== null) {
            $this->runFor((int) $companyId);
        }
    }

    public function runFor(int $companyId): void
    {
        $bandwidths = collect([
            ['name' => '20M/5M Shared 1:8', 'download_mbps' => 20, 'upload_mbps' => 5, 'type' => 'shared', 'contention_ratio' => 8],
            ['name' => '50M/10M Shared 1:8', 'download_mbps' => 50, 'upload_mbps' => 10, 'type' => 'shared', 'contention_ratio' => 8],
            ['name' => '100M/20M Shared 1:4', 'download_mbps' => 100, 'upload_mbps' => 20, 'type' => 'shared', 'contention_ratio' => 4],
            ['name' => '100M/100M Dedicated', 'download_mbps' => 100, 'upload_mbps' => 100, 'type' => 'dedicated', 'contention_ratio' => null],
            ['name' => '500M/500M Dedicated', 'download_mbps' => 500, 'upload_mbps' => 500, 'type' => 'dedicated', 'contention_ratio' => null],
        ])->map(fn (array $data) => BandwidthProfile::query()->firstOrCreate(['company_id' => $companyId, 'name' => $data['name']], $data + ['is_active' => true]));

        $speeds = collect([
            ['name' => 'Up to 20Mbps', 'download_max_mbps' => 20, 'upload_max_mbps' => 5, 'burst_download_mbps' => 40, 'burst_upload_mbps' => 10, 'radius_profile_name' => 'home-20m'],
            ['name' => 'Up to 50Mbps', 'download_max_mbps' => 50, 'upload_max_mbps' => 10, 'burst_download_mbps' => 100, 'burst_upload_mbps' => 20, 'radius_profile_name' => 'home-50m'],
            ['name' => 'Up to 100Mbps', 'download_max_mbps' => 100, 'upload_max_mbps' => 20, 'burst_download_mbps' => 150, 'burst_upload_mbps' => 40, 'radius_profile_name' => 'home-100m'],
            ['name' => 'Guaranteed 100Mbps', 'download_max_mbps' => 100, 'upload_max_mbps' => 100, 'burst_download_mbps' => null, 'burst_upload_mbps' => null, 'radius_profile_name' => 'biz-100m'],
            ['name' => 'Guaranteed 500Mbps', 'download_max_mbps' => 500, 'upload_max_mbps' => 500, 'burst_download_mbps' => null, 'burst_upload_mbps' => null, 'radius_profile_name' => 'enterprise-500m'],
        ])->map(fn (array $data) => SpeedProfile::query()->firstOrCreate(['company_id' => $companyId, 'name' => $data['name']], $data + ['is_active' => true]));

        $slas = collect([
            ['name' => 'Bronze', 'uptime_pct' => 99.00, 'response_time_hours' => 24, 'resolution_time_hours' => 48, 'credit_pct' => 0],
            ['name' => 'Silver', 'uptime_pct' => 99.50, 'response_time_hours' => 8, 'resolution_time_hours' => 24, 'credit_pct' => 5],
            ['name' => 'Gold', 'uptime_pct' => 99.90, 'response_time_hours' => 4, 'resolution_time_hours' => 12, 'credit_pct' => 10],
            ['name' => 'Platinum', 'uptime_pct' => 99.95, 'response_time_hours' => 2, 'resolution_time_hours' => 8, 'credit_pct' => 15],
        ])->map(fn (array $data) => SLATier::query()->firstOrCreate(['company_id' => $companyId, 'name' => $data['name']], $data + ['is_active' => true]));

        foreach ([
            ['PKG-HOME-20M', 'Home 20Mbps', 0, 0, 0, 150000, 300000, 0],
            ['PKG-HOME-50M', 'Home 50Mbps', 1, 1, 1, 250000, 500000, 0],
            ['PKG-HOME-100M', 'Home 100Mbps', 2, 2, 1, 450000, 750000, 0],
            ['PKG-BIZ-50M', 'Business 50Mbps', 1, 1, 2, 750000, 1000000, 12],
            ['PKG-BIZ-100M', 'Business 100Mbps Dedicated', 3, 3, 2, 1500000, 1500000, 12],
            ['PKG-ENT-100M', 'Enterprise 100Mbps', 3, 3, 3, 2500000, 2500000, 24],
            ['PKG-ENT-500M', 'Enterprise 500Mbps', 4, 4, 3, 8000000, 5000000, 24],
            ['PKG-SOHO-20M', 'SOHO 20Mbps', 0, 0, 1, 300000, 500000, 6],
        ] as [$code, $name, $bandwidth, $speed, $sla, $mrc, $otc, $contract]) {
            ServicePackage::query()->firstOrCreate(['company_id' => $companyId, 'code' => $code], [
                'name' => $name,
                'bandwidth_profile_id' => $bandwidths[$bandwidth]->id,
                'speed_profile_id' => $speeds[$speed]->id,
                'sla_tier_id' => $slas[$sla]->id,
                'price_mrc' => $mrc,
                'price_otc' => $otc,
                'contract_min_months' => $contract,
                'description' => $name.' service package.',
                'is_active' => true,
            ]);
        }
    }
}
