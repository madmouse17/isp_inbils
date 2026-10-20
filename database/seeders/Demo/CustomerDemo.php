<?php

namespace Database\Seeders\Demo;

use App\Actions\CreateCustomerOnboardingAction;
use App\Models\Core\Customer;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\DocumentService;
use App\Services\Core\SubscriptionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\BillingService;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\Service\Models\ServicePackage;
use Modules\SPK\Models\WorkOrder;

/**
 * Forty customers, each with the history their row describes: signed by a salesperson, an installation SPK
 * worked through its stages, the subscription that follows, and for the ones that stay, bills and payments.
 */
final class CustomerDemo
{
    /**
     * code, name, I(ndividual)|C(ompany), package, POP, days since sign-up, salesperson, fate, payment habit, materials.
     *
     * Fate: active, suspended and terminated customers got their installation approved; the others are still on
     * the way (generated, assigned, progress, review, rejected, cancelled). Materials default to what the
     * technician records himself; `ir:<stage>` has the technician get them through a warehouse request.
     */
    private const CUSTOMERS = [
        ['DEMO-C001', 'Asep Saepudin', 'I', 'PKG-HOME-20', 'dgo', 172, 'staff', 'active', 'prompt'],
        ['DEMO-C002', 'Neneng Hasanah', 'I', 'PKG-HOME-10', 'ant', 167, 'sales_dewi', 'active', 'prompt'],
        ['DEMO-C003', 'Warung Makan Sunda Sari Rasa', 'C', 'PKG-BIZ-100', 'hq', 163, 'manager', 'active', 'prompt'],
        ['DEMO-C004', 'Dadan Ramdani', 'I', 'PKG-HOME-20', 'bbh', 158, 'sales_rizky', 'suspended', 'delinq:2'],
        ['DEMO-C005', 'Yuli Rahmawati', 'I', 'PKG-HOME-50', 'dgo', 154, 'sales_dewi', 'active', 'prompt'],
        ['DEMO-C006', 'Kafe Kopi Dago Atas', 'C', 'PKG-BIZ-100-GOLD', 'dgo', 149, 'sales_rizky', 'active', 'late'],
        ['DEMO-C007', 'Ujang Sutisna', 'I', 'PKG-HOME-20', 'ant', 144, 'staff', 'terminated', 'prompt'],
        ['DEMO-C008', 'Iis Nurhayati', 'I', 'PKG-HOME-10', 'bbh', 139, 'sales_dewi', 'active', 'prompt', 'ir:reconciled'],
        ['DEMO-C009', 'Cecep Supriatna', 'I', 'PKG-HOME-20-PROMO', 'ant', 135, 'staff', 'suspended', 'delinq:2'],
        ['DEMO-C010', 'CV Mitra Digital Printing', 'C', 'PKG-BIZ-200', 'hq', 130, 'sales_rizky', 'active', 'partial'],
        ['DEMO-C011', 'Tini Kartini', 'I', 'PKG-HOME-50', 'dgo', 126, 'sales_dewi', 'active', 'prompt'],
        ['DEMO-C012', 'Rudi Hartono', 'I', 'PKG-HOME-20', 'bbh', 121, 'admin', 'active', 'prompt'],
        ['DEMO-C013', 'Klinik Pratama Sehat Sentosa', 'C', 'PKG-BIZ-100', 'ant', 116, 'sales_rizky', 'active', 'late'],
        ['DEMO-C014', 'Siti Aminah', 'I', 'PKG-HOME-10', 'dgo', 111, 'sales_dewi', 'active', 'prompt', 'ir:resolved'],
        ['DEMO-C015', 'Budi Santoso', 'I', 'PKG-HOME-20', 'bbh', 106, 'staff', 'suspended', 'delinq:3'],
        ['DEMO-C016', 'Kos-kosan Putri Mawar', 'C', 'PKG-HOME-50', 'hq', 100, 'manager', 'active', 'prompt'],
        ['DEMO-C017', 'Dewi Lestari', 'I', 'PKG-HOME-20-PROMO', 'ant', 95, 'sales_dewi', 'active', 'prompt'],
        ['DEMO-C018', 'Agung Prasetyo', 'I', 'PKG-HOME-50', 'dgo', 89, 'staff', 'terminated', 'prompt'],
        ['DEMO-C019', 'Toko Elektronik Maju Jaya', 'C', 'PKG-BIZ-100', 'bbh', 83, 'sales_rizky', 'active', 'prompt', 'ir:reconciled'],
        ['DEMO-C020', 'Rina Marlina', 'I', 'PKG-HOME-20', 'ant', 77, 'sales_dewi', 'active', 'late'],
        ['DEMO-C021', 'Hendra Gunawan', 'I', 'PKG-HOME-10', 'dgo', 70, 'staff', 'active', 'prompt'],
        ['DEMO-C022', 'Bimbel Cerdas Prima', 'C', 'PKG-HOME-50', 'bbh', 64, 'sales_rizky', 'suspended', 'delinq:1'],
        ['DEMO-C023', 'Lilis Suryani', 'I', 'PKG-HOME-20', 'ant', 57, 'sales_dewi', 'active', 'partial'],
        ['DEMO-C024', 'Teguh Wibowo', 'I', 'PKG-TRIAL', 'dgo', 50, 'staff', 'active', 'prompt'],
        ['DEMO-C025', 'Maya Anggraeni', 'I', 'PKG-HOME-50', 'bbh', 44, 'sales_dewi', 'active', 'prompt'],
        ['DEMO-C026', 'PT Karya Konveksi Nusantara', 'C', 'PKG-BIZ-200', 'ant', 38, 'sales_rizky', 'terminated', 'prompt'],
        ['DEMO-C027', 'Fajar Ramadhan', 'I', 'PKG-HOME-20', 'dgo', 32, 'staff', 'active', 'late'],
        ['DEMO-C028', 'Nurul Fitriani', 'I', 'PKG-HOME-10', 'ant', 27, 'sales_dewi', 'active', 'prompt'],
        ['DEMO-C029', 'Apotek Kimia Sehat', 'C', 'PKG-BIZ-100-GOLD', 'hq', 22, 'manager', 'active', 'prompt'],
        ['DEMO-C030', 'Eko Prabowo', 'I', 'PKG-HOME-20', 'bbh', 17, 'staff', 'active', 'prompt'],
        ['DEMO-C031', 'Ratna Sari', 'I', 'PKG-HOME-20', 'dgo', 16, 'sales_dewi', 'rejected', 'none'],
        ['DEMO-C032', 'Wahyu Hidayat', 'I', 'PKG-HOME-10', 'ant', 15, 'staff', 'cancelled', 'none'],
        ['DEMO-C033', 'Hotel Melati Bandung Indah', 'C', 'PKG-BIZ-200', 'bbh', 13, 'sales_rizky', 'review', 'none', 'ir:exception'],
        ['DEMO-C034', 'Intan Permata', 'I', 'PKG-HOME-50', 'ant', 11, 'sales_dewi', 'review', 'none', 'ir:declared'],
        ['DEMO-C035', 'Dian Sastrawan', 'I', 'PKG-HOME-20', 'dgo', 9, 'staff', 'progress', 'none', 'ir:correction'],
        ['DEMO-C036', 'Notaris Herlina S.H.', 'C', 'PKG-BIZ-100', 'hq', 8, 'sales_rizky', 'progress', 'none', 'ir:issued'],
        ['DEMO-C037', 'Yanti Mulyani', 'I', 'PKG-HOME-20-PROMO', 'bbh', 6, 'sales_dewi', 'assigned', 'none'],
        ['DEMO-C038', 'Gudang Distribusi Sinar Abadi', 'C', 'PKG-BIZ-100', 'dgo', 5, 'sales_rizky', 'assigned', 'none'],
        ['DEMO-C039', 'Bambang Suryadi', 'I', 'PKG-HOME-10', 'ant', 3, 'staff', 'generated', 'none'],
        ['DEMO-C040', 'Sri Wahyuni', 'I', 'PKG-HOME-20', 'bbh', 1, 'sales_dewi', 'generated', 'none'],
    ];

    /** Who signs for a business, and in what role. */
    private const CONTACTS = [
        'DEMO-C003' => ['Hj. Enung Komariah', 'Pemilik'],
        'DEMO-C006' => ['Reza Pahlevi', 'Manajer Operasional'],
        'DEMO-C010' => ['Anton Wijaya', 'Direktur'],
        'DEMO-C013' => ['dr. Ayu Lestari', 'Kepala Klinik'],
        'DEMO-C016' => ['Marni Susilowati', 'Pengelola Kos'],
        'DEMO-C019' => ['Hendri Tanuwijaya', 'Pemilik'],
        'DEMO-C022' => ['Sinta Rahayu', 'Kepala Cabang'],
        'DEMO-C026' => ['Darmawan Putra', 'Direktur Produksi'],
        'DEMO-C029' => ['Apt. Rosmala Dewi', 'Apoteker Penanggung Jawab'],
        'DEMO-C033' => ['Gunawan Halim', 'General Manager'],
        'DEMO-C036' => ['Herlina Kusuma, S.H., M.Kn.', 'Notaris dan PPAT'],
        'DEMO-C038' => ['Budiman Sutanto', 'Kepala Gudang'],
    ];

    /** Days ago a customer that does not pay is suspended, and the reason a leaver gives. */
    private const SUSPENDED_ON = ['DEMO-C004' => 66, 'DEMO-C009' => 48, 'DEMO-C015' => 36, 'DEMO-C022' => 14];

    private const LEFT_ON = [
        'DEMO-C007' => [70, 'Pindah domisili ke luar kota'],
        'DEMO-C018' => [40, 'Berpindah ke provider lain dengan harga lebih murah'],
        'DEMO-C026' => [12, 'Usaha ditutup, kontrak tidak diperpanjang'],
    ];

    /** Signed by the manager, credited to a salesperson (the manager signs two more customers for himself). */
    private const CREDITED_ELSEWHERE = 'DEMO-C003';

    private const TECHNICIANS = ['hq' => 'technician', 'dgo' => 'tech_agus', 'ant' => 'tech_dedi', 'bbh' => 'tech_yoga'];

    private const AREAS = ['hq' => 'pst', 'dgo' => 'utr', 'ant' => 'tmr', 'bbh' => 'slt'];

    private const COORDINATES = ['hq' => [-6.9215, 107.6075], 'dgo' => [-6.8860, 107.6125], 'ant' => [-6.9140, 107.6585], 'bbh' => [-6.9570, 107.6350]];

    private const STREETS = [
        'hq' => ['Jl. Braga', 'Jl. Naripan', 'Jl. Kebon Jati', 'Jl. Pajajaran', 'Jl. Riau', 'Jl. Dr. Rajiman', 'Jl. Pasirkaliki'],
        'dgo' => ['Jl. Ir. H. Juanda', 'Jl. Dipati Ukur', 'Jl. Cihampelas', 'Jl. Setiabudi', 'Jl. Sukajadi', 'Jl. Cipaganti', 'Jl. Sekeloa'],
        'ant' => ['Jl. Purwakarta', 'Jl. Terusan Jakarta', 'Jl. Cicaheum', 'Jl. A. H. Nasution', 'Jl. Sukamiskin', 'Jl. Antapani Lama'],
        'bbh' => ['Jl. Buah Batu', 'Jl. Soekarno-Hatta', 'Jl. Moh. Toha', 'Jl. Ibrahim Adjie', 'Jl. Lengkong Besar', 'Jl. Gatot Subroto', 'Jl. Turangga'],
    ];

    private const POSTAL_CODES = [
        'SUMUR BANDUNG' => '40111', 'ANDIR' => '40181', 'CICENDO' => '40171', 'BANDUNG WETAN' => '40115',
        'COBLONG' => '40132', 'SUKAJADI' => '40162', 'CIDADAP' => '40141',
        'ANTAPANI' => '40291', 'ARCAMANIK' => '40293', 'MANDALAJATI' => '40195', 'CIBEUNYING KIDUL' => '40121',
        'BUAHBATU' => '40286', 'LENGKONG' => '40262', 'KIARACONDONG' => '40275', 'REGOL' => '40251',
    ];

    private const PHONE_PREFIXES = ['0811', '0812', '0813', '0821', '0822', '0852', '0857', '0878'];

    private const CONTRACT_PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R/Size 4>>\n%%EOF\n";

    public static function plan(DemoContext $c): void
    {
        foreach (self::CUSTOMERS as $row) {
            self::planCustomer($c, $row);
        }
    }

    /**
     * Customers that are still active, with their POP and the days since they signed up.
     *
     * @return list<array{code: string, pop: string, days: int}>
     */
    public static function activeCustomers(): array
    {
        $active = array_filter(self::CUSTOMERS, fn (array $row) => $row[7] === 'active');

        return array_values(array_map(fn (array $row) => ['code' => $row[0], 'pop' => $row[4], 'days' => $row[5]], $active));
    }

    public static function technicianFor(string $pop): string
    {
        return self::TECHNICIANS[$pop];
    }

    /** @param array<int, string|int> $row */
    private static function planCustomer(DemoContext $c, array $row): void
    {
        [$code, , $type, , $pop, $days, $sales, $fate] = $row;
        $materials = $row[9] ?? 'direct';
        $technician = self::TECHNICIANS[$pop];
        $reviewer = $sales === 'manager' ? 'admin' : 'manager';

        $chain = $c->timeline->chain($c->day($days, $c->int(9, 14), $c->int(0, 59)));
        $chain->step(fn (DemoContext $c) => self::onboard($c, $row), "onboard {$code}");

        if ($fate === 'generated') {
            return;
        }

        if ($fate === 'cancelled') {
            $chain->afterDays(3, function (DemoContext $c) use ($code): void {
                SpkFlow::cancel($c, $code, 'Pelanggan membatalkan pemasangan karena pindah rumah');
                $c->as('manager', fn () => SubscriptionService::terminate(self::subscription($c, $code), 'Pelanggan membatalkan sebelum instalasi'));
            }, "cancel {$code}");

            return;
        }

        $chain->afterDays(1, fn (DemoContext $c) => SpkFlow::assign($c, $code, $technician), "assign {$code}");

        if ($fate === 'assigned') {
            return;
        }

        $chain->afterDays(1, fn (DemoContext $c) => SpkFlow::start($c, $code, $technician), "start {$code}");

        $backed = str_starts_with((string) $materials, 'ir:');

        if ($backed) {
            $stage = substr((string) $materials, 3);
            $chain->wait(70);
            InventoryDemo::requestFlow($c, $chain, self::requestSpec($code, $pop, $technician, $type === 'C', $stage), $stage);

            if (in_array($stage, ['issued', 'correction'], true)) {
                return;
            }
        }

        $chain->afterHours($backed ? 2 : 5, fn (DemoContext $c) => SpkFlow::finish(
            $c,
            $code,
            $technician,
            $backed ? [] : SpkFlow::materials($c, $type === 'C'),
            [$pop, $c->int(1, 4) === 1 ? 'CPE-ONT-F670L' : 'CPE-ONT-HG8145V5'],
        ), "finish {$code}");

        if ($fate === 'review') {
            return;
        }

        if ($fate === 'rejected') {
            $chain->afterDays(1, fn (DemoContext $c) => SpkFlow::reject($c, $code, $reviewer, 'Foto redaman (OPM) tidak terlihat jelas, mohon unggah ulang bukti pengukuran'), "reject {$code}");

            return;
        }

        $chain->afterDays(1, fn (DemoContext $c) => SpkFlow::approve($c, $code, $reviewer), "approve {$code}");

        if ($type === 'C') {
            $chain->afterDays(1, fn (DemoContext $c) => self::contractDocument($c, $code), "contract {$code}");
        }

        $chain->afterDays(1, fn (DemoContext $c) => self::installationInvoice($c, $code), "installation invoice {$code}");

        if (isset(self::SUSPENDED_ON[$code])) {
            $c->timeline->at($c->day(self::SUSPENDED_ON[$code], 10, 30), fn (DemoContext $c) => $c->as('manager', fn () => SubscriptionService::suspend(
                self::subscription($c, $code),
                'Menunggak lebih dari satu periode tagihan, layanan dinonaktifkan sementara',
            )), "suspend {$code}");
        }

        if (isset(self::LEFT_ON[$code])) {
            [$leftDays, $reason] = self::LEFT_ON[$code];
            $c->timeline->at($c->day($leftDays, 11, 0), fn (DemoContext $c) => self::terminate($c, $code, $pop, $reason), "terminate {$code}");
        }
    }

    /** @return array{key: string, tech: string, warehouse: string, reason: string, items: array<string, int>, source_spk: string, returns: array<string, array{0: int, 1: int, 2: int, 3: int}>, resolution?: string} */
    private static function requestSpec(string $code, string $pop, string $technician, bool $business, string $stage): array
    {
        $issued = $business ? [150, 6, 12] : [100, 4, 8];
        $damaged = in_array($stage, ['exception', 'resolved'], true) ? 6 : 0;
        $good = $business ? 40 : 25;

        return [
            'key' => 'IR-'.substr($code, -4),
            'tech' => $technician,
            'warehouse' => $pop,
            'reason' => "Material pemasangan baru {$code}",
            'items' => ['KBL-DROP-1C' => $issued[0], 'CON-FAST-SC' => $issued[1], 'PSV-CLAMP-DC' => $issued[2]],
            'source_spk' => $code,
            'returns' => [
                'KBL-DROP-1C' => [$issued[0] - $good - $damaged, $good, $damaged, 0],
                'CON-FAST-SC' => [$issued[1] - 1, 1, 0, 0],
                'PSV-CLAMP-DC' => [$issued[2] - 3, 3, 0, 0],
            ],
            'resolution' => 'Selisih kabel rusak saat penarikan dibebankan sebagai scrap, disetujui manajer operasional',
        ];
    }

    /** @param array<int, string|int> $row */
    private static function onboard(DemoContext $c, array $row): void
    {
        [$code, $name, $type, $packageCode, $pop, , $sales, , $habit] = $row;

        $package = ServicePackage::query()->where('code', $packageCode)->firstOrFail();
        $phone = $c->phone($c->pick(self::PHONE_PREFIXES).sprintf('%08d', $c->int(10000000, 99999999)));
        $email = $c->customerEmail(Str::slug($name, '.'));
        $business = $type === 'C';
        [$person, $position] = $business ? self::CONTACTS[$code] : [$name, 'Pemilik'];

        $addresses = [self::address($c, $pop, 'Utama', true, true)];

        if ($business || $c->chance(15)) {
            $addresses[] = self::address($c, $pop, $business ? 'Kantor Pusat' : 'Alamat Penagihan', false, false);
        }

        $contacts = [['name' => $person, 'position' => $position, 'phone' => $phone, 'email' => $email, 'is_primary' => true, 'notes' => null]];

        if ($business) {
            $contacts[] = ['name' => $c->pick(['Rina Maharani', 'Dedi Hermawan', 'Wulan Sari', 'Hasan Basri']), 'position' => 'Staf Keuangan', 'phone' => '022'.sprintf('%07d', $c->int(1000000, 9999999)), 'email' => 'keuangan.'.$email, 'is_primary' => false, 'notes' => 'Penerima tagihan'];
        }

        $data = [
            'code' => $code,
            'name' => $name,
            'type' => $business ? 'Company' : 'Individual',
            'email' => $email,
            'phone' => $phone,
            'tax_id' => $business ? sprintf('%02d.%03d.%03d.%d-%03d.000', $c->int(1, 99), $c->int(1, 999), $c->int(1, 999), $c->int(1, 9), $c->int(401, 449)) : null,
            'contact_person' => $business ? $person : null,
            'area_coverage_id' => $c->get('location', self::AREAS[$pop]),
            'notes' => $c->chance(30) ? $c->pick(['Referensi dari tetangga pelanggan lama', 'Mendaftar lewat promo di media sosial', 'Pelanggan meminta jadwal pemasangan sore hari', 'Datang langsung ke kantor pusat']) : null,
            'is_active' => true,
            'addresses' => $addresses,
            'contacts' => $contacts,
            'subscription' => [
                'service_package_id' => $package->id,
                'serving_pop_id' => $c->get('location', $pop),
                'billing_day' => $c->int(1, 10),
                'mrc_amount' => $package->getAttribute('price_mrc'),
                'otc_installation_fee' => $package->getAttribute('price_otc'),
                'contract_months' => (int) $package->getAttribute('contract_min_months') > 0 ? (int) $package->getAttribute('contract_min_months') : null,
                'notes' => null,
            ],
        ];

        if ($code === self::CREDITED_ELSEWHERE) {
            $data['acquired_by_user_id'] = $c->user('sales_rizky')->id;
        }

        $customer = $c->as($sales, fn ($creator) => CreateCustomerOnboardingAction::execute($data, $creator));
        $subscription = $customer->subscriptions()->firstOrFail();

        $c->put('customer', $code, $customer->id);
        $c->put('subscription', $code, $subscription->id);
        $c->put('spk', $code, WorkOrder::query()->where('subscription_id', $subscription->id)->firstOrFail()->id);
        $c->put('payer', (string) $customer->id, $habit);
        $c->put('pop', $code, $pop);
    }

    /** @return array<string, mixed> */
    private static function address(DemoContext $c, string $pop, string $label, bool $installation, bool $primary): array
    {
        $district = $c->pick(LocationDemo::districts($pop));
        $region = LocationDemo::region($c, $pop, $district);
        [$lat, $lng] = self::COORDINATES[$pop];

        return [
            'label' => $label,
            'address' => sprintf(
                '%s No. %d, RT %02d/RW %02d, Kel. %s, Kec. %s',
                $c->pick(self::STREETS[$pop]),
                $c->int(1, 320),
                $c->int(1, 12),
                $c->int(1, 9),
                Str::title(strtolower($region['village'])),
                Str::title(strtolower($district)),
            ),
            'postal_code' => self::POSTAL_CODES[$district],
            'province_code' => $region['province_code'],
            'city_code' => $region['city_code'],
            'district_code' => $region['district_code'],
            'village_code' => $region['village_code'],
            'lat' => round($lat + $c->int(-120, 120) / 10000, 7),
            'lng' => round($lng + $c->int(-120, 120) / 10000, 7),
            'is_installation_point' => $installation,
            'is_primary' => $primary,
            'notes' => $installation ? $c->pick(['Rumah dua lantai, ONT di ruang tamu', 'Ruko tiga lantai, ONT di lantai satu', 'Rumah di gang, kabel ditarik dari tiang seberang', null]) : null,
        ];
    }

    private static function subscription(DemoContext $c, string $code): ServiceSubscription
    {
        return ServiceSubscription::query()->findOrFail($c->get('subscription', $code));
    }

    /** The installation invoice the approval drafted (K17): sent, then paid as the customer's habit goes. An SPK that bills nothing leaves none. */
    private static function installationInvoice(DemoContext $c, string $code): void
    {
        $invoice = $c->as('staff', function () use ($c, $code): ?Invoice {
            $draft = Invoice::query()->where('work_order_id', (int) $c->get('spk', $code))->first();

            return $draft === null ? null : BillingService::send($draft);
        });

        if ($invoice !== null) {
            BillingDemo::schedulePayments($c, $invoice->id);
        }
    }

    private static function contractDocument(DemoContext $c, string $code): void
    {
        $path = tempnam(sys_get_temp_dir(), 'spb');
        file_put_contents($path, self::CONTRACT_PDF);

        $c->as('admin', fn () => DocumentService::upload(
            Customer::query()->findOrFail($c->get('customer', $code)),
            new UploadedFile($path, "SPB-{$code}.pdf", 'application/pdf', null, true),
            'SPB',
        ));
    }

    /** The customer leaves: the subscription ends, the ONT comes back to the POP's shelf. */
    private static function terminate(DemoContext $c, string $code, string $pop, string $reason): void
    {
        $subscription = self::subscription($c, $code);
        $asset = NetworkAsset::query()->findOrFail($subscription->ont_asset_id);

        $c->as('manager', fn () => SubscriptionService::terminate($subscription, $reason, true));
        $c->as('staff', fn () => NetworkDemo::shelveOnt($c, $asset->refresh(), $pop));
    }
}
