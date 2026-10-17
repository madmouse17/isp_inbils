<?php

namespace Database\Seeders\Demo;

use App\Models\Core\DocumentType;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\Vehicle;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyService;
use App\Services\Core\OrganizationService;
use Modules\Service\Models\BandwidthProfile;
use Modules\Service\Models\ServicePackage;
use Modules\Service\Models\ServicePackageCategory;
use Modules\Service\Models\SLATier;
use Modules\Service\Models\SpeedProfile;

/** The company itself: profile, organisation, vehicles, staff beyond the five demo logins, document types. */
final class FoundationDemo
{
    /** code => [name, type, parent code] */
    private const UNITS = [
        'INB-HQ' => ['Inbils Net Bandung', 'company', null],
        'INB-OPS' => ['Divisi Operasional', 'unit', 'INB-HQ'],
        'INB-NOC' => ['NOC & Monitoring', 'team', 'INB-OPS'],
        'INB-FLD' => ['Teknisi Lapangan', 'unit', 'INB-OPS'],
        'INB-FLD-UTR' => ['Tim Lapangan Bandung Utara', 'team', 'INB-FLD'],
        'INB-FLD-TMR' => ['Tim Lapangan Bandung Timur', 'team', 'INB-FLD'],
        'INB-FLD-SLT' => ['Tim Lapangan Bandung Selatan & Pusat', 'team', 'INB-FLD'],
        'INB-SLS' => ['Sales & Customer Care', 'unit', 'INB-HQ'],
        'INB-WH' => ['Gudang & Logistik', 'unit', 'INB-HQ'],
        'INB-FIN' => ['Keuangan & Penagihan', 'unit', 'INB-HQ'],
    ];

    /** [plate, type, brand, model, purchase date, active, notes] */
    private const VEHICLES = [
        ['D 3412 FAB', 'Motor', 'Honda', 'Vario 125', '2024-02-10', true, 'Motor operasional teknisi Bandung Utara'],
        ['D 5127 KDN', 'Motor', 'Honda', 'BeAT Street', '2024-05-21', true, 'Motor operasional teknisi Bandung Timur'],
        ['D 2290 UGH', 'Motor', 'Yamaha', 'Jupiter MX 135', '2023-09-04', true, 'Motor operasional teknisi Bandung Selatan'],
        ['D 8841 ZBA', 'Mobil', 'Daihatsu', 'Gran Max Pick-Up', '2023-01-16', true, 'Penarikan kabel dan pengantaran material'],
        ['D 1033 AKW', 'Mobil', 'Suzuki', 'APV Blind Van', '2022-10-03', true, 'Pengiriman perangkat ke POP'],
        ['D 6620 XYZ', 'Motor', 'Honda', 'Supra X 125', '2019-07-08', false, 'Dijual, tidak lagi operasional'],
    ];

    /** key => [name, email local part, role, unit, employee number, phone, hire date, skills, plate, status] */
    private const PEOPLE = [
        'tech_agus' => ['Agus Setiawan', 'agus.setiawan', 'technician', 'INB-FLD-UTR', 'INB-0101', '081322001101', '2024-03-04', ['Splicing FTTH', 'Konfigurasi ONT', 'OTDR'], 'D 3412 FAB', 'active'],
        'tech_dedi' => ['Dedi Kurniawan', 'dedi.kurniawan', 'technician', 'INB-FLD-TMR', 'INB-0102', '081322001102', '2024-06-17', ['Splicing FTTH', 'Analisa redaman'], 'D 5127 KDN', 'active'],
        'tech_yoga' => ['Yoga Pratama', 'yoga.pratama', 'technician', 'INB-FLD-SLT', 'INB-0103', '081322001103', '2025-01-13', ['Instalasi ONT', 'Penarikan kabel udara'], 'D 2290 UGH', 'active'],
        'sales_dewi' => ['Dewi Anggraini', 'dewi.anggraini', 'staff', 'INB-SLS', 'INB-0201', '081322002201', '2023-11-06', ['Penjualan rumahan', 'Survei lokasi'], null, 'active'],
        'sales_rizky' => ['Rizky Maulana', 'rizky.maulana', 'staff', 'INB-SLS', 'INB-0202', '081322002202', '2025-02-24', ['Penjualan korporat'], null, 'active'],
        'former_hari' => ['Hari Wibowo', 'hari.wibowo', 'technician', 'INB-FLD-SLT', 'INB-0090', '081322001090', '2023-05-02', ['Splicing FTTH'], null, 'terminated'],
    ];

    /** The three demo logins that already have a profile: key => [unit, phone, hire date, skills, plate] */
    private const EXISTING = [
        'manager' => ['INB-OPS', '081322000001', '2022-03-01', ['Manajemen operasional', 'Persetujuan SPK'], null],
        'staff' => ['INB-WH', '081322000002', '2023-02-13', ['Administrasi gudang', 'Penagihan'], null],
        'technician' => ['INB-FLD-SLT', '081322000003', '2022-08-22', ['Splicing FTTH', 'Maintenance jaringan'], 'D 8841 ZBA'],
    ];

    /** The logins a trial company gets besides its real admin: key (also the role) => [name, has an employee profile]. */
    private const TRIAL_LOGINS = [
        'manager' => ['Demo Manager', true],
        'staff' => ['Demo Staff', true],
        'technician' => ['Demo Technician', true],
        'customer' => ['Demo Customer', false],
    ];

    /** code => [name, applies to, required, expiry days] */
    private const DOCUMENT_TYPES = [
        'KTP' => ['Kartu Tanda Penduduk (KTP)', 'customer', true, null],
        'NPWP' => ['NPWP Badan Usaha', 'customer', false, null],
        'NIB' => ['Nomor Induk Berusaha (NIB)', 'customer', false, null],
        'SPB' => ['Surat Perjanjian Berlangganan', 'customer', true, 730],
        'BAI' => ['Berita Acara Instalasi', 'customer', false, null],
    ];

    /** code => [name, bandwidth [down, up, type, contention], speed [max down, up, burst down, burst up, radius profile], SLA tier, MRC, OTC, months, description] */
    private const PACKAGES = [
        'PKG-HOME-30' => ['Home 30Mbps', [30, 10, 'shared', 4], [30, 10, 45, 15, 'home-30m'], 'Silver', 299000, 300000, 12, 'Paket keluarga 30 Mbps untuk streaming dan sekolah daring'],
        'PKG-GAMER-100' => ['Gamer 100Mbps', [100, 50, 'shared', 2], [100, 50, 150, 75, 'gamer-100m'], 'Silver', 449000, 350000, 12, 'Latensi rendah untuk gamer dan kreator konten'],
        'PKG-KOS-50' => ['Kos dan Asrama 50Mbps', [50, 20, 'shared', 8], [50, 20, null, null, 'kos-50m'], 'Bronze', 550000, 400000, 12, 'Paket bersama untuk kos-kosan hingga 20 kamar'],
    ];

    public static function seed(DemoContext $c): void
    {
        // A trial company keeps the profile its admin gave it: the sample address and bank accounts would print on its invoices.
        if (! $c->trial) {
            self::companyProfile($c);
        }

        $units = self::organization($c);
        $vehicles = self::vehicles();
        self::people($c, $units, $vehicles);
        self::documentTypes();
        self::catalogue();
    }

    private static function companyProfile(DemoContext $c): void
    {
        $profile = [
            'address' => $c->company->address ?: 'Jl. Asia Afrika No. 112, Braga, Sumur Bandung, Kota Bandung 40111',
            'phone' => $c->company->phone ?: '022-4231188',
            'email' => $c->company->email ?: 'halo@inbils.demo.test',
            'website' => $c->company->website ?: 'https://inbils.demo.test',
        ];

        CompanyService::updateProfile($profile);
        CompanyService::updateSettings([
            'bank_account_info' => CompanyService::setting('bank_account_info') ?: "Bank BCA 028 123 4567 a.n. Inbils Net Bandung\nBank Mandiri 130 00 9876543 2 a.n. Inbils Net Bandung",
        ]);
        $c->company->refresh();
    }

    /** @return array<string, int> unit code => id */
    private static function organization(DemoContext $c): array
    {
        $ids = [];

        foreach (self::UNITS as $code => [$name, $type, $parent]) {
            $ids[$code] = OrganizationService::create([
                'parent_id' => $parent === null ? null : $ids[$parent],
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'address' => $code === 'INB-HQ' ? $c->company->address : null,
                'phone' => $code === 'INB-HQ' ? $c->company->phone : null,
                'is_active' => true,
            ])->id;
        }

        return $ids;
    }

    /** @return array<string, int> plate => id */
    private static function vehicles(): array
    {
        $ids = [];

        foreach (self::VEHICLES as [$plate, $type, $brand, $model, $purchased, $active, $notes]) {
            $ids[$plate] = Vehicle::query()->create([
                'plate_number' => $plate,
                'type' => $type,
                'brand' => $brand,
                'model' => $model,
                'purchase_date' => $purchased,
                'is_active' => $active,
                'notes' => $notes,
            ])->id;
        }

        return $ids;
    }

    /**
     * @param  array<string, int>  $units
     * @param  array<string, int>  $vehicles
     */
    private static function people(DemoContext $c, array $units, array $vehicles): void
    {
        self::logins($c);

        foreach (self::EXISTING as $key => [$unit, $phone, $hired, $skills, $plate]) {
            EmployeeProfile::query()->where('user_id', $c->user($key)->id)->firstOrFail()->update([
                'organization_id' => $units[$unit],
                'vehicle_id' => $plate === null ? null : $vehicles[$plate],
                'phone' => $c->phone($phone),
                'hire_date' => $hired,
                'skills' => $skills,
            ]);
        }

        foreach (self::PEOPLE as $key => [$name, $local, $role, $unit, $number, $phone, $hired, $skills, $plate, $status]) {
            $user = new User(['name' => $name, 'email' => $c->email($local), 'password' => $c->password(), 'is_active' => $status === 'active']);
            $user->forceFill(['company_id' => $c->companyId(), 'email_verified_at' => $c->start])->save();
            $user->assignRole($role);
            app(CompanyMembershipService::class)->grant($user, $c->company, true);

            EmployeeProfile::query()->create([
                'user_id' => $user->id,
                'organization_id' => $units[$unit],
                'vehicle_id' => $plate === null ? null : $vehicles[$plate],
                'employee_number' => $number,
                'phone' => $c->phone($phone),
                'hire_date' => $hired,
                'status' => $status,
                'skills' => $skills,
                'notes' => $status === 'terminated' ? 'Mengundurkan diri, serah terima perangkat selesai.' : null,
            ]);

            $c->addUser($key, $user);
        }
    }

    /**
     * The five logins every company of the story has. The DEMO company already has them from DemoUserSeeder. A trial
     * company has its real admin (registered by DemoDataSeeder::seedFor); the other four are created here with
     * addresses of that company and credentials nobody is ever told.
     */
    private static function logins(DemoContext $c): void
    {
        if (! $c->trial) {
            foreach (['admin', 'manager', 'staff', 'technician', 'customer'] as $key) {
                $c->addUser($key, User::query()->where('email', $c->email($key))->firstOrFail());
            }

            return;
        }

        foreach (self::TRIAL_LOGINS as $key => [$name, $employee]) {
            $user = new User(['name' => $name, 'email' => $c->email($key), 'password' => $c->password(), 'is_active' => true]);
            $user->forceFill(['company_id' => $c->companyId(), 'email_verified_at' => $c->start])->save();
            $user->assignRole($key);
            app(CompanyMembershipService::class)->grant($user, $c->company, true);

            if ($employee) {
                EmployeeProfile::query()->create(['user_id' => $user->id, 'employee_number' => 'DEMO-'.strtoupper($key), 'status' => 'active']);
            }

            $c->addUser($key, $user);
        }
    }

    private static function documentTypes(): void
    {
        foreach (self::DOCUMENT_TYPES as $code => [$name, $appliesTo, $required, $expiryDays]) {
            DocumentType::query()->create([
                'code' => $code,
                'name' => $name,
                'applies_to' => $appliesTo,
                'is_required' => $required,
                'expiry_days' => $expiryDays,
                'is_active' => true,
            ]);
        }
    }

    /** Packages beyond the eight the company seeder starts with, each with its own bandwidth and speed profile. */
    private static function catalogue(): void
    {
        $tiers = SLATier::query()->pluck('id', 'name');
        $residential = ServicePackageCategory::ensureDefaults((int) CompanyService::currentId())['RUMAHAN'];

        foreach (self::PACKAGES as $code => [$name, $bandwidth, $speed, $tier, $mrc, $otc, $months, $description]) {
            $bandwidthProfile = BandwidthProfile::query()->create([
                'name' => $name,
                'download_mbps' => $bandwidth[0],
                'upload_mbps' => $bandwidth[1],
                'type' => $bandwidth[2],
                'contention_ratio' => $bandwidth[3],
                'is_active' => true,
            ]);
            $speedProfile = SpeedProfile::query()->create([
                'name' => 'SP-'.$name,
                'download_max_mbps' => $speed[0],
                'upload_max_mbps' => $speed[1],
                'burst_download_mbps' => $speed[2],
                'burst_upload_mbps' => $speed[3],
                'radius_profile_name' => $speed[4],
                'is_active' => true,
            ]);

            ServicePackage::query()->create([
                'code' => $code,
                'name' => $name,
                'bandwidth_profile_id' => $bandwidthProfile->id,
                'speed_profile_id' => $speedProfile->id,
                'sla_tier_id' => $tiers[$tier],
                'service_package_category_id' => $residential,
                'price_mrc' => $mrc,
                'price_otc' => $otc,
                'contract_min_months' => $months,
                'description' => $description,
                'is_active' => true,
            ]);
        }
    }
}
