<?php

namespace Database\Seeders;

use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\Core\LocationService;
use App\Support\PublicId;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\NetworkAsset\Services\NetworkAssetService;
use Modules\Service\Models\ServicePackageCategory;
use Modules\Service\Services\ServicePackageVersionBackfill;

/**
 * The defaults a new company starts with. `$catalog` picks how much:
 *
 * - `empty`: units, package categories and ticket categories, what no company works without.
 * - `starter`: `empty` plus SLA tiers, the Default Region/Area/POP, bandwidth and speed profiles, packages, inventory
 *   categories and products. No stock rows and no network assets, so real stock never mixes with sample quantities.
 * - `demo`: `starter` plus stock rows and ten sample network assets, coded by the Penomoran sequence. The dev and E2E
 *   fixture company and the sample data of a trial tenant stand on them.
 *
 * A run never adds a second copy of a row, so it can repeat or grow from a smaller catalog to a larger one.
 */
class CompanySeeder extends Seeder
{
    public const CATALOG_EMPTY = 'empty';

    public const CATALOG_STARTER = 'starter';

    public const CATALOG_DEMO = 'demo';

    /** @var list<string> */
    public const CATALOGS = [self::CATALOG_EMPTY, self::CATALOG_STARTER, self::CATALOG_DEMO];

    public function runFor(Company $company, string $catalog = self::CATALOG_STARTER): void
    {
        if (! in_array($catalog, self::CATALOGS, true)) {
            throw new InvalidArgumentException("Unknown catalog [{$catalog}]: use ".implode(', ', self::CATALOGS).'.');
        }

        $previousUser = Auth::user();
        $actingUser = ($previousUser?->company_id === (int) $company->id)
            ? $previousUser
            : User::query()->where('company_id', $company->id)->first();

        if ($actingUser === null) {
            // company_id is not mass assignable: unguard so the system user really is homed in the company.
            $actingUser = User::unguarded(fn () => User::query()->firstOrCreate(
                ['email' => "system-seed-{$company->id}@inbils.internal"],
                [
                    'name' => "System Seed ({$company->name})",
                    'company_id' => $company->id,
                    'password' => Hash::make(Str::random(32)),
                    'email_verified_at' => now(),
                    'is_active' => true,
                ]
            ));
        }

        if ($actingUser !== null && $actingUser !== $previousUser) {
            Auth::setUser($actingUser);
            CompanyService::resetCache();
        }

        try {
            // The seeds act in the company they seed, whoever is signed in.
            CompanyService::runAs((int) $company->id, function () use ($company, $catalog): void {
                $this->seedUnits($company);
                $this->seedTicketCategories($company);
                $this->seedPackageCategories($company);

                if ($catalog === self::CATALOG_EMPTY) {
                    return;
                }

                $this->seedSlaTiers($company);
                $this->seedLocations($company);
                $this->seedBandwidthProfiles($company);
                $this->seedSpeedProfiles($company);
                $this->seedServicePackages($company);
                $this->seedInventoryCategories($company);

                if ($catalog === self::CATALOG_DEMO) {
                    $this->seedProductStocks($company);
                    $this->seedNetworkAssets($company);
                }
            });
        } finally {
            if ($actingUser !== $previousUser) {
                if ($previousUser !== null) {
                    Auth::setUser($previousUser);
                } else {
                    Auth::forgetGuards();
                }
                CompanyService::resetCache();
            }
        }
    }

    private function seedUnits(Company $company): void
    {
        if (! Schema::hasTable('units') || ! Schema::hasColumns('units', ['company_id', 'name', 'symbol'])) {
            return;
        }

        foreach ([
            ['name' => 'pcs', 'symbol' => 'pcs'],
            ['name' => 'meter', 'symbol' => 'm'],
            ['name' => 'roll', 'symbol' => 'roll'],
            ['name' => 'box', 'symbol' => 'box'],
        ] as $unit) {
            DB::table('units')->updateOrInsert(
                ['company_id' => $company->id, 'name' => $unit['name']],
                PublicId::onInsert(['symbol' => $unit['symbol'], 'updated_at' => now(), 'created_at' => now()]),
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
                PublicId::onInsert([...$category, 'company_id' => $company->id, 'is_active' => true, 'updated_at' => now(), 'created_at' => now()]),
            );
        }
    }

    private function seedSlaTiers(Company $company): void
    {
        if (! Schema::hasTable('sla_tiers') || ! Schema::hasColumns('sla_tiers', ['company_id', 'name', 'response_time_hours', 'resolution_time_hours'])) {
            return;
        }

        foreach ([
            ['name' => 'Bronze', 'response_time_hours' => 24, 'resolution_time_hours' => 48],
            ['name' => 'Silver', 'response_time_hours' => 8, 'resolution_time_hours' => 24],
            ['name' => 'Gold', 'response_time_hours' => 4, 'resolution_time_hours' => 12],
        ] as $tier) {
            DB::table('sla_tiers')->updateOrInsert(
                ['company_id' => $company->id, 'name' => $tier['name']],
                PublicId::onInsert([...$tier, 'company_id' => $company->id, 'is_active' => true, 'updated_at' => now(), 'created_at' => now()]),
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

        $region = LocationService::create(['company_id' => $company->id, 'code' => 'REG-01', 'name' => 'Default Region', 'type' => 'region', 'is_active' => true]);
        $area = LocationService::create(['company_id' => $company->id, 'parent_id' => $region->id, 'code' => 'AREA-01', 'name' => 'Default Area', 'type' => 'area', 'is_active' => true]);
        LocationService::create(['company_id' => $company->id, 'parent_id' => $area->id, 'code' => 'POP-01', 'name' => 'Default POP', 'type' => 'pop', 'is_active' => true]);
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
                'public_id' => PublicId::new(),
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
                'public_id' => PublicId::new(),
                'company_id' => $company->id,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedPackageCategories(Company $company): void
    {
        if (Schema::hasTable('service_package_categories')) {
            ServicePackageCategory::ensureDefaults((int) $company->id);
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
        $categoryIds = DB::table('service_package_categories')->where('company_id', $company->id)->pluck('id', 'code');

        $packages = [
            ['code' => 'PKG-HOME-10', 'name' => 'Home 10Mbps', 'price_mrc' => 150000, 'price_otc' => 250000, 'contract_min_months' => 12, 'category' => 'RUMAHAN'],
            ['code' => 'PKG-HOME-20', 'name' => 'Home 20Mbps', 'price_mrc' => 250000, 'price_otc' => 300000, 'contract_min_months' => 12, 'category' => 'RUMAHAN'],
            ['code' => 'PKG-HOME-50', 'name' => 'Home 50Mbps', 'price_mrc' => 350000, 'price_otc' => 350000, 'contract_min_months' => 12, 'category' => 'RUMAHAN'],
            ['code' => 'PKG-BIZ-100', 'name' => 'Business 100Mbps', 'price_mrc' => 750000, 'price_otc' => 500000, 'contract_min_months' => 24, 'category' => 'BISNIS'],
            ['code' => 'PKG-BIZ-200', 'name' => 'Business 200Mbps', 'price_mrc' => 1500000, 'price_otc' => 750000, 'contract_min_months' => 24, 'category' => 'BISNIS'],
            ['code' => 'PKG-HOME-20-PROMO', 'name' => 'Home 20Mbps Promo', 'price_mrc' => 199000, 'price_otc' => 0, 'contract_min_months' => 6, 'category' => 'RUMAHAN'],
            ['code' => 'PKG-BIZ-100-GOLD', 'name' => 'Business 100Mbps Gold', 'price_mrc' => 950000, 'price_otc' => 500000, 'contract_min_months' => 24, 'category' => 'BISNIS'],
            ['code' => 'PKG-TRIAL', 'name' => 'Trial 5Mbps', 'price_mrc' => 50000, 'price_otc' => 0, 'contract_min_months' => 0, 'category' => 'RUMAHAN'],
        ];

        foreach ($packages as $i => $p) {
            DB::table('service_packages')->insert([
                ...Arr::except($p, 'category'),
                'public_id' => PublicId::new(),
                'company_id' => $company->id,
                'bandwidth_profile_id' => $bwIds[$i % count($bwIds)] ?? null,
                'speed_profile_id' => $spIds[$i % count($spIds)] ?? null,
                'sla_tier_id' => $slaIds[min(intdiv($i, 2), count($slaIds) - 1)] ?? null,
                'service_package_category_id' => $categoryIds[$p['category']],
                'description' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // The raw inserts skip the model hook that records a package's first version.
        ServicePackageVersionBackfill::versions();
    }

    private function seedInventoryCategories(Company $company): void
    {
        if (! Schema::hasTable('categories')) {
            return;
        }

        if (DB::table('categories')->where('company_id', $company->id)->exists()) {
            return;
        }

        $categories = [
            ['name' => 'Kabel', 'code' => 'KBL', 'description' => 'Kabel jaringan dan fiber'],
            ['name' => 'Connector', 'code' => 'CON', 'description' => 'Connector dan adaptor'],
            ['name' => 'Sparepart', 'code' => 'SPR', 'description' => 'Sparepart perangkat'],
            ['name' => 'Tools', 'code' => 'TLS', 'description' => 'Alat kerja'],
            ['name' => 'Patch Cord', 'code' => 'PTC', 'description' => 'Patch cord fiber'],
        ];

        $unitIds = DB::table('units')->where('company_id', $company->id)->orderBy('id')->pluck('id')->toArray();

        if (empty($unitIds)) {
            return;
        }

        // Category unit ownership: Kabel/Patch Cord meter, rest pcs.
        $categoryUnitMap = [
            'KBL' => 1,
            'CON' => 0,
            'SPR' => 0,
            'TLS' => 0,
            'PTC' => 1,
        ];

        foreach ($categories as $c) {
            $unitIdx = $categoryUnitMap[$c['code']] ?? 0;
            DB::table('categories')->insert([
                ...$c,
                'public_id' => PublicId::new(),
                'company_id' => $company->id,
                'parent_id' => null,
                'unit_id' => $unitIds[$unitIdx] ?? $unitIds[0],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Seed products
        if (! Schema::hasTable('products')) {
            return;
        }

        $catIds = DB::table('categories')->where('company_id', $company->id)->orderBy('id')->pluck('id')->toArray();

        $products = [
            ['sku' => 'KBL-UTP-CAT6', 'name' => 'Kabel UTP Cat6', 'category_idx' => 0, 'unit_idx' => 0, 'sell_price' => 5000, 'cost_price' => 3500],
            ['sku' => 'KBL-FO-SINGLE', 'name' => 'Kabel Fiber Optic Single', 'category_idx' => 0, 'unit_idx' => 1, 'sell_price' => 15000, 'cost_price' => 10000],
            ['sku' => 'CON-RJ45-CAT6', 'name' => 'Connector RJ45 Cat6', 'category_idx' => 1, 'unit_idx' => 0, 'sell_price' => 2000, 'cost_price' => 1000],
            ['sku' => 'CON-SC-APC', 'name' => 'Connector SC/APC', 'category_idx' => 1, 'unit_idx' => 0, 'sell_price' => 5000, 'cost_price' => 3000],
            ['sku' => 'SPR-PSU-12V', 'name' => 'Power Supply 12V 2A', 'category_idx' => 2, 'unit_idx' => 0, 'sell_price' => 50000, 'cost_price' => 35000],
            ['sku' => 'SPR-SFP-LX', 'name' => 'SFP Module LX', 'category_idx' => 2, 'unit_idx' => 0, 'sell_price' => 250000, 'cost_price' => 180000],
            ['sku' => 'TLS-CRIMP-RJ45', 'name' => 'Crimping Tool RJ45', 'category_idx' => 3, 'unit_idx' => 0, 'sell_price' => 75000, 'cost_price' => 50000],
            ['sku' => 'TLS-STRIP-FO', 'name' => 'Fiber Stripper', 'category_idx' => 3, 'unit_idx' => 0, 'sell_price' => 120000, 'cost_price' => 80000],
            ['sku' => 'PTC-LC-1M', 'name' => 'Patch Cord LC/UPC 1m', 'category_idx' => 4, 'unit_idx' => 1, 'sell_price' => 25000, 'cost_price' => 15000],
            ['sku' => 'PTC-SC-3M', 'name' => 'Patch Cord SC/APC 3m', 'category_idx' => 4, 'unit_idx' => 1, 'sell_price' => 35000, 'cost_price' => 22000],
        ];

        $categoryUnits = DB::table('categories')
            ->where('company_id', $company->id)
            ->pluck('unit_id', 'id');

        foreach ($products as $p) {
            $categoryId = $catIds[$p['category_idx']] ?? $catIds[0];
            DB::table('products')->insert([
                'public_id' => PublicId::new(),
                'company_id' => $company->id,
                'category_id' => $categoryId,
                'unit_id' => $categoryUnits[$categoryId] ?? $unitIds[$p['unit_idx']] ?? $unitIds[0],
                'sku' => $p['sku'],
                'name' => $p['name'],
                'description' => null,
                'type' => 'consumable',
                'track_stock' => true,
                'sell_price' => $p['sell_price'],
                'cost_price' => $p['cost_price'],
                'min_stock' => 10,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedProductStocks(Company $company): void
    {
        if (! Schema::hasTable('stocks') || ! Schema::hasTable('products') || ! Schema::hasTable('locations')) {
            return;
        }

        if (DB::table('stocks')->where('company_id', $company->id)->exists()) {
            return;
        }

        $locationId = DB::table('locations')
            ->where('company_id', $company->id)
            ->where('type', 'pop')
            ->orderBy('id')
            ->value('id')
            ?? DB::table('locations')
                ->where('company_id', $company->id)
                ->orderBy('id')
                ->value('id');

        if (! $locationId) {
            return;
        }

        $products = DB::table('products')
            ->where('company_id', $company->id)
            ->where('track_stock', true)
            ->orderBy('id')
            ->get(['id']);

        foreach ($products as $index => $product) {
            DB::table('stocks')->updateOrInsert(
                [
                    'company_id' => $company->id,
                    'product_id' => $product->id,
                    'location_id' => $locationId,
                ],
                PublicId::onInsert([
                    'quantity' => 20 + ($index * 5),
                    'reserved_quantity' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
            );
        }
    }

    private function seedNetworkAssets(Company $company): void
    {
        if (! Schema::hasTable('network_assets')) {
            return;
        }

        if (DB::table('network_assets')->where('company_id', $company->id)->exists()) {
            return;
        }

        $assets = [
            ['name' => 'OLT Huawei MA5800', 'asset_type' => 'olt', 'serial_number' => 'HW-MA5800-001', 'vendor' => 'Huawei', 'model' => 'MA5800-X7'],
            ['name' => 'OLT Huawei MA5800', 'asset_type' => 'olt', 'serial_number' => 'HW-MA5800-002', 'vendor' => 'Huawei', 'model' => 'MA5800-X7'],
            ['name' => 'Switch Cisco SG350', 'asset_type' => 'switch', 'serial_number' => 'CSC-SG350-001', 'vendor' => 'Cisco', 'model' => 'SG350-28'],
            ['name' => 'Router Mikrotik CCR', 'asset_type' => 'router', 'serial_number' => 'MKT-CCR-001', 'vendor' => 'Mikrotik', 'model' => 'CCR2004-1G-12S+XS'],
            ['name' => 'ONT Huawei HG8245', 'asset_type' => 'onu_ont', 'serial_number' => 'HW-HG8245-001', 'vendor' => 'Huawei', 'model' => 'HG8245Q'],
            ['name' => 'ONT Huawei HG8245', 'asset_type' => 'onu_ont', 'serial_number' => 'HW-HG8245-002', 'vendor' => 'Huawei', 'model' => 'HG8245Q'],
            ['name' => 'ONT Huawei HG8245', 'asset_type' => 'onu_ont', 'serial_number' => 'HW-HG8245-003', 'vendor' => 'Huawei', 'model' => 'HG8245Q'],
            ['name' => 'Antenna Sector 5GHz', 'asset_type' => 'antenna', 'serial_number' => 'UBQ-ANT-001', 'vendor' => 'Ubiquiti', 'model' => 'AM-5G17-90'],
            ['name' => 'Radio AirMax AC', 'asset_type' => 'radio', 'serial_number' => 'UBQ-RAD-001', 'vendor' => 'Ubiquiti', 'model' => 'Rocket 5AC Lite'],
            ['name' => 'Rack 42U Server', 'asset_type' => 'rack', 'serial_number' => 'RCK-42U-001', 'vendor' => null, 'model' => '42U Standard'],
        ];

        foreach ($assets as $a) {
            DB::table('network_assets')->insert([
                ...$a,
                'public_id' => PublicId::new(),
                'company_id' => $company->id,
                'product_id' => null,
                // The Penomoran sequence, like every asset made in the app: one code scheme in one list.
                'code' => NetworkAssetService::generateCode(),
                'mac_address' => null,
                'ip_address' => null,
                'management_ip' => null,
                'location_id' => null,
                'customer_id' => null,
                'subscription_id' => null,
                'status' => 'available',
                'ownership' => 'owned',
                'purchase_date' => null,
                'purchase_price' => null,
                'warranty_expiry' => null,
                'notes' => null,
                'installed_at' => null,
                'retired_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
