<?php

namespace Database\Seeders\Demo;

use App\Models\Core\Location;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Models\StockAdjustmentRequest;
use Modules\Inventory\Models\Unit;
use Modules\Inventory\Services\InventoryRequestService;
use Modules\Inventory\Services\MaterialReturnService;
use Modules\Inventory\Services\StockService;

/** Catalogue extension, purchases, distribution to the POPs, stock corrections and the warehouse request flow. */
final class InventoryDemo
{
    /** code => [name, unit, description] */
    private const CATEGORIES = [
        'CPE' => ['Perangkat Pelanggan (CPE)', 'pcs', 'ONT, router dan perangkat yang dipasang di rumah pelanggan'],
        'PSV' => ['Perangkat Pasif & Aksesoris FTTH', 'pcs', 'Splitter, klem, sleeve dan aksesoris jaringan distribusi fiber'],
    ];

    /** sku => [name, category, unit, type, cost, sell, min stock, description] */
    private const PRODUCTS = [
        'KBL-DROP-1C' => ['Kabel Dropcore FTTH 1 Core', 'KBL', 'meter', 'consumable', 950, 1500, 2000, 'Kabel fiber optik drop 1 core figure-8 untuk instalasi pelanggan'],
        'CON-FAST-SC' => ['Fast Connector SC/APC', 'CON', 'pcs', 'consumable', 4500, 9000, 60, 'Konektor cepat SC/APC untuk terminasi dropcore'],
        'PSV-CLAMP-DC' => ['Klem Kabel Dropcore (S-Clamp)', 'PSV', 'pcs', 'consumable', 700, 1500, 300, 'Klem tarik dropcore di tiang dan dinding rumah'],
        'PSV-SLEEVE-60' => ['Sleeve Protektor Splicing 60 mm', 'PSV', 'pcs', 'consumable', 250, 600, 200, 'Pelindung sambungan fusion splice'],
        'PSV-SPL-1X8' => ['Splitter PLC 1:8 SC/APC', 'PSV', 'pcs', 'consumable', 85000, 150000, 6, 'Splitter pasif untuk ODP'],
        'PSV-TIE-20' => ['Cable Tie 20 cm (isi 100)', 'PSV', 'box', 'consumable', 12000, 20000, 10, 'Pengikat kabel di tiang dan rack'],
        'CPE-ONT-HG8145V5' => ['ONT Huawei HG8145V5 Dual Band', 'CPE', 'pcs', 'asset', 480000, 0, 6, 'ONT GPON dual band, milik perusahaan, dipinjamkan ke pelanggan'],
        'CPE-ONT-F670L' => ['ONT ZTE ZXHN F670L Dual Band', 'CPE', 'pcs', 'asset', 520000, 0, 6, 'ONT GPON dual band, milik perusahaan, dipinjamkan ke pelanggan'],
    ];

    private const STAGE_RANK = [
        'draft' => 0, 'cancelled' => 1, 'submitted' => 1, 'rejected' => 2, 'approved' => 3, 'preparing' => 4,
        'ready' => 5, 'issued' => 6, 'declared' => 7, 'correction' => 8, 'reconciled' => 8, 'exception' => 8, 'resolved' => 9,
    ];

    public static function seedMaster(DemoContext $c): void
    {
        $units = Unit::query()->pluck('id', 'name');

        foreach (self::CATEGORIES as $code => [$name, $unit, $description]) {
            Category::query()->create(['unit_id' => $units[$unit], 'name' => $name, 'code' => $code, 'description' => $description, 'is_active' => true]);
        }

        $categories = Category::query()->pluck('id', 'code');

        foreach (self::PRODUCTS as $sku => [$name, $category, $unit, $type, $cost, $sell, $min, $description]) {
            Product::query()->create([
                'category_id' => $categories[$category],
                'unit_id' => $units[$unit],
                'sku' => $sku,
                'name' => $name,
                'description' => $description,
                'type' => $type,
                'track_stock' => true,
                'cost_price' => $cost,
                'sell_price' => $sell,
                'min_stock' => $min,
                'is_active' => true,
            ]);
        }

        foreach (Product::query()->pluck('id', 'sku') as $sku => $id) {
            $c->put('product', (string) $sku, $id);
        }

        $c->put('location', 'default', Location::query()->where('code', 'POP-01')->value('id'));
    }

    /** Opening balances, purchases, distribution and corrections. Requests of technicians are planned by their own flows. */
    public static function plan(DemoContext $c): void
    {
        $c->timeline->at($c->day(178, 8, 0), fn (DemoContext $c) => self::openingBalances($c), 'opening balances');

        // The legacy warehouse is folded into the main one.
        $c->timeline->at($c->day(170, 10, 0), fn (DemoContext $c) => self::distribute($c, 'default', 'hq', ['CON-RJ45-CAT6' => 10, 'SPR-PSU-12V' => 15, 'PTC-SC-3M' => 20], 'Konsolidasi gudang lama ke gudang utama'), 'legacy warehouse');

        self::purchase($c, 176, 11, 'PT Jaringan Optik Nusantara', [
            'KBL-DROP-1C' => 8000, 'CON-FAST-SC' => 600, 'PSV-CLAMP-DC' => 2400, 'PSV-SLEEVE-60' => 1500,
            'PSV-SPL-1X8' => 24, 'PSV-TIE-20' => 40, 'CPE-ONT-HG8145V5' => 28, 'CPE-ONT-F670L' => 12,
        ]);
        self::distributeToPops($c, 172, 'Distribusi awal stok ke POP', [
            'KBL-DROP-1C' => [1500, 1500, 1500], 'CON-FAST-SC' => [150, 150, 150], 'PSV-CLAMP-DC' => [600, 600, 600],
            'PSV-SLEEVE-60' => [400, 400, 400], 'PSV-SPL-1X8' => [6, 6, 6], 'PSV-TIE-20' => [8, 8, 8],
            'CPE-ONT-HG8145V5' => [8, 8, 8], 'CPE-ONT-F670L' => [3, 3, 4],
        ]);

        self::purchase($c, 95, 24, 'PT Jaringan Optik Nusantara', [
            'KBL-DROP-1C' => 5000, 'CON-FAST-SC' => 500, 'PSV-CLAMP-DC' => 2000, 'PSV-SLEEVE-60' => 1200,
            'CPE-ONT-HG8145V5' => 20, 'CPE-ONT-F670L' => 12,
        ]);
        self::distributeToPops($c, 92, 'Distribusi ulang stok ke POP', [
            'KBL-DROP-1C' => [1200, 1200, 1200], 'CON-FAST-SC' => [120, 120, 120], 'PSV-CLAMP-DC' => [500, 500, 500],
            'PSV-SLEEVE-60' => [300, 300, 300], 'CPE-ONT-HG8145V5' => [6, 6, 4], 'CPE-ONT-F670L' => [3, 3, 4],
        ]);

        self::purchase($c, 40, 7, 'CV Sinar Teknik Elektrik', [
            'KBL-DROP-1C' => 3000, 'CON-FAST-SC' => 300, 'PSV-CLAMP-DC' => 1000, 'PSV-SPL-1X8' => 12, 'PSV-TIE-20' => 20,
        ]);
        self::distributeToPops($c, 36, 'Pengisian ulang stok POP', [
            'KBL-DROP-1C' => [600, 1000, 1000], 'CON-FAST-SC' => [100, 100, 100], 'PSV-CLAMP-DC' => [300, 300, 300],
        ]);

        self::planAdjustments($c);
        self::planStandaloneRequests($c);
    }

    /** @param array<string, int|float> $lines sku => quantity */
    private static function purchase(DemoContext $c, int $daysAgo, int $number, string $supplier, array $lines): void
    {
        $at = $c->day($daysAgo, 10, 15);
        $po = sprintf('PO-%s-%03d', $at->format('Y/m'), $number);

        $c->timeline->at($at, function (DemoContext $c) use ($po, $supplier, $lines): void {
            $c->as('staff', function () use ($c, $po, $supplier, $lines): void {
                foreach ($lines as $sku => $quantity) {
                    StockService::receive($c->get('product', $sku), $c->get('location', 'hq'), $quantity, "Pembelian {$po} dari {$supplier}");

                    if (str_starts_with($sku, 'CPE-')) {
                        NetworkDemo::receiveOnts($c, $sku, $quantity, $po);
                    }
                }
            });
        }, "purchase {$po}");
    }

    /** @param array<string, list<int>> $lines sku => quantity for dgo, ant, bbh */
    private static function distributeToPops(DemoContext $c, int $daysAgo, string $note, array $lines): void
    {
        foreach (['dgo', 'ant', 'bbh'] as $index => $pop) {
            $c->timeline->at($c->day($daysAgo, 13, 10 + $index * 20), fn (DemoContext $c) => self::distribute(
                $c,
                'hq',
                $pop,
                array_map(fn (array $quantities) => $quantities[$index], $lines),
                $note,
            ), "distribute {$pop}");
        }
    }

    /** @param array<string, int|float> $lines sku => quantity */
    private static function distribute(DemoContext $c, string $from, string $to, array $lines, string $note): void
    {
        $c->as('staff', function () use ($c, $from, $to, $lines, $note): void {
            foreach ($lines as $sku => $quantity) {
                if ($quantity <= 0) {
                    continue;
                }

                StockService::transfer($c->get('product', $sku), $c->get('location', $from), $c->get('location', $to), $quantity, "{$note}: ".strtoupper($to));

                if (str_starts_with($sku, 'CPE-')) {
                    NetworkDemo::moveOnts($c, $sku, $from, $to, (int) $quantity);
                }
            }
        });
    }

    /** Stock rows the company seeder wrote without a ledger entry get an opening-balance movement. */
    private static function openingBalances(DemoContext $c): void
    {
        $c->as('admin', function (): void {
            foreach (Stock::query()->where('quantity', '>', 0)->get() as $stock) {
                $hasLedger = DB::table('stock_movements')
                    ->where('company_id', $stock->company_id)
                    ->where('product_id', $stock->product_id)
                    ->whereRaw('COALESCE(to_location_id, from_location_id) = ?', [$stock->location_id])
                    ->exists();

                if ($hasLedger) {
                    continue;
                }

                // adjust() writes the delta from the current balance, so the balance restarts at zero and the movement restores it.
                $opening = (float) $stock->quantity;
                DB::table('stocks')->where('id', $stock->id)->update(['quantity' => 0]);
                StockService::adjust($stock->product_id, $stock->location_id, $opening, 'Saldo awal stok: pencatatan ledger dimulai');
            }
        });
    }

    private static function planAdjustments(DemoContext $c): void
    {
        self::adjustment($c, 60, 'CON-RJ45-CAT6', 'default', fn () => 8.0, 'Hasil stock opname bulanan: 12 pcs rusak atau tidak ditemukan', 'approve', 'Sesuai berita acara opname');
        self::adjustment($c, 52, 'SPR-PSU-12V', 'default', fn () => 27.0, 'Menambah 2 pcs adaptor hasil temuan di laci teknisi', 'reject', 'Tidak ada dokumen penerimaan, lakukan opname ulang');
        self::adjustment($c, 25, 'KBL-DROP-1C', 'dgo', fn (float $current) => max(0.0, $current - 40), 'Sisa potongan dropcore kurang dari 3 m dibuang (scrap 40 m)', 'approve', 'Scrap wajar, disetujui');
        self::adjustment($c, 3, 'CON-FAST-SC', 'ant', fn (float $current) => max(0.0, $current - 25), 'Fast connector rusak saat terminasi, 25 pcs dijadikan scrap', 'pending');
        self::adjustment($c, 1, 'PTC-SC-3M', 'default', fn () => 58.0, 'Patch cord cacat pabrik, 7 pcs akan diretur ke pemasok', 'pending');

        $c->timeline->at($c->day(40, 15, 30), function (DemoContext $c): void {
            $c->as('admin', function () use ($c): void {
                StockService::adjust($c->get('product', 'TLS-CRIMP-RJ45'), $c->get('location', 'default'), 48.0, 'Koreksi hasil audit gudang (break-glass admin)', ['reason' => 'internal_audit']);
            });
        }, 'direct adjustment');
    }

    /** @param \Closure(float): float $target */
    private static function adjustment(DemoContext $c, int $daysAgo, string $sku, string $location, \Closure $target, string $reason, string $outcome, ?string $reviewNote = null): void
    {
        $requestedAt = $c->day($daysAgo, 11, 20);
        $chain = $c->timeline->chain($requestedAt);

        $chain->step(function (DemoContext $c) use ($sku, $location, $target, $reason): void {
            $c->as('staff', function () use ($c, $sku, $location, $target, $reason): void {
                $current = (float) Stock::query()
                    ->where('product_id', $c->get('product', $sku))
                    ->where('location_id', $c->get('location', $location))
                    ->value('quantity');
                $request = StockService::requestAdjustment($c->get('product', $sku), $c->get('location', $location), $target($current), $reason);
                $c->put('adjustment', "{$sku}@{$location}", $request->id);
            });
        }, "adjustment request {$sku}");

        if ($outcome === 'pending') {
            return;
        }

        $chain->afterHours(5, function (DemoContext $c) use ($sku, $location, $outcome, $reviewNote): void {
            $c->as('manager', function ($manager) use ($c, $sku, $location, $outcome, $reviewNote): void {
                $request = StockAdjustmentRequest::query()->findOrFail($c->get('adjustment', "{$sku}@{$location}"));

                $outcome === 'approve'
                    ? StockService::approveAdjustment($request, $manager->id)
                    : StockService::rejectAdjustment($request, (string) $reviewNote, $manager->id);
            });
        }, "adjustment review {$sku}");
    }

    private static function planStandaloneRequests(DemoContext $c): void
    {
        $dropcore = 'KBL-DROP-1C';
        $connector = 'CON-FAST-SC';
        $clamp = 'PSV-CLAMP-DC';

        self::requestFlow($c, $c->timeline->chain($c->day(150, 9, 30)), [
            'key' => 'IR-S1', 'tech' => 'tech_agus', 'warehouse' => 'dgo', 'reason' => 'Persediaan material perbaikan jaringan ODP Dago',
            'items' => [$dropcore => 150, $connector => 10, $clamp => 20],
            'returns' => [$dropcore => [90, 60, 0, 0], $connector => [6, 4, 0, 0], $clamp => [14, 6, 0, 0]],
        ], 'reconciled');

        self::requestFlow($c, $c->timeline->chain($c->day(60, 9, 45)), [
            'key' => 'IR-S9', 'tech' => 'technician', 'warehouse' => 'bbh', 'reason' => 'Penarikan ulang jalur dropcore terputus di Jl. Buah Batu',
            'items' => [$dropcore => 100, $connector => 8],
            'returns' => [$dropcore => [80, 15, 5, 0], $connector => [8, 0, 0, 0]],
            'resolution' => 'Selisih kabel rusak 5 m dibebankan sebagai scrap, disetujui manajer operasional',
        ], 'resolved');

        self::requestFlow($c, $c->timeline->chain($c->day(33, 14, 0)), [
            'key' => 'IR-S5', 'tech' => 'tech_agus', 'warehouse' => 'dgo', 'reason' => 'Material penarikan kabel Jl. Setiabudi',
            'items' => [$dropcore => 120, $clamp => 30], 'cancel_reason' => 'Material ditemukan di sisa pekerjaan sebelumnya',
        ], 'cancelled');

        self::requestFlow($c, $c->timeline->chain($c->day(21, 10, 0)), [
            'key' => 'IR-S4', 'tech' => 'technician', 'warehouse' => 'bbh', 'reason' => 'Persiapan penarikan kabel baru Jl. Moh. Toha',
            'items' => [$dropcore => 400, $connector => 20], 'reject_reason' => 'Stok POP Buah Batu tidak mencukupi untuk 400 m, ajukan ulang maksimal 200 m',
        ], 'rejected');

        self::requestFlow($c, $c->timeline->chain($c->day(4, 9, 15)), [
            'key' => 'IR-S6', 'tech' => 'tech_dedi', 'warehouse' => 'ant', 'reason' => 'Material penggantian konektor ODP Antapani',
            'items' => [$connector => 40, $clamp => 100],
        ], 'approved');

        self::requestFlow($c, $c->timeline->chain($c->day(3, 9, 30)), [
            'key' => 'IR-S7', 'tech' => 'tech_yoga', 'warehouse' => 'bbh', 'reason' => 'Material pemasangan ODP baru jalur Lengkong',
            'items' => [$clamp => 80, 'PSV-SLEEVE-60' => 40],
        ], 'preparing');

        self::requestFlow($c, $c->timeline->chain($c->day(2, 8, 45)), [
            'key' => 'IR-S8', 'tech' => 'tech_agus', 'warehouse' => 'dgo', 'reason' => 'Dropcore untuk tiga pemasangan baru minggu ini',
            'items' => [$dropcore => 300],
        ], 'ready');

        self::requestFlow($c, $c->timeline->chain($c->day(1, 16, 0)), [
            'key' => 'IR-S3', 'tech' => 'tech_yoga', 'warehouse' => 'bbh', 'reason' => 'Dropcore dan konektor untuk pemasangan pelanggan Regol',
            'items' => [$dropcore => 200, $connector => 12],
        ], 'submitted');

        self::requestFlow($c, $c->timeline->chain($c->day(2, 15, 10)), [
            'key' => 'IR-S2', 'tech' => 'technician', 'warehouse' => 'hq', 'reason' => 'Splitter dan konektor cadangan untuk ODC kantor pusat',
            'items' => ['PSV-SPL-1X8' => 2, $connector => 20],
        ], 'draft');
    }

    /**
     * Plans the life of one warehouse request on $chain up to the stage $until. A request that backs a
     * work order names it with `source_spk` (the customer code the SPK is registered under). Returns of
     * used material are sku => [used, good return, damaged, lost].
     *
     * @param  array{key: string, tech: string, warehouse: string, reason: string, items: array<string, int|float>, source_spk?: string, returns?: array<string, array{0: int|float, 1: int|float, 2: int|float, 3: int|float}>, reject_reason?: string, cancel_reason?: string, resolution?: string}  $spec
     */
    public static function requestFlow(DemoContext $c, DemoChain $chain, array $spec, string $until): void
    {
        $rank = self::STAGE_RANK[$until];
        $key = $spec['key'];

        $chain->step(function (DemoContext $c) use ($spec, $until): void {
            $c->as($spec['tech'], function ($tech) use ($c, $spec, $until): void {
                $request = InventoryRequestService::create($tech, [
                    'warehouse_location_id' => $c->get('location', $spec['warehouse']),
                    'reason' => $spec['reason'],
                    'source_type' => isset($spec['source_spk']) ? 'work_order' : null,
                    'source_id' => isset($spec['source_spk']) ? $c->get('spk', $spec['source_spk']) : null,
                    'needed_at' => now()->addDays(2),
                    'submit' => ! in_array($until, ['draft', 'cancelled'], true),
                    'items' => array_map(
                        fn (string $sku, int|float $quantity) => ['product_id' => $c->get('product', $sku), 'quantity_requested' => $quantity],
                        array_keys($spec['items']),
                        array_values($spec['items']),
                    ),
                ]);
                $c->put('inventory_request', $spec['key'], self::renumber($request)->id);
            });
        }, "request {$key} create");

        if ($until === 'cancelled') {
            $chain->afterMinutes(95, fn (DemoContext $c) => $c->as($spec['tech'], fn ($tech) => InventoryRequestService::cancel(self::request($c, $key), $tech, $spec['cancel_reason'] ?? null)), "request {$key} cancel");

            return;
        }

        if ($rank < self::STAGE_RANK['rejected']) {
            return;
        }

        if ($until === 'rejected') {
            $chain->afterHours(4, fn (DemoContext $c) => $c->as('manager', fn ($manager) => InventoryRequestService::reject(self::request($c, $key), $manager, $spec['reject_reason'] ?? 'Permintaan ditolak')), "request {$key} reject");

            return;
        }

        $chain->afterHours(3, fn (DemoContext $c) => $c->as('manager', fn ($manager) => InventoryRequestService::approve(self::request($c, $key), $manager, ['approval_note' => 'Disetujui, stok direservasi di POP'])), "request {$key} approve");

        if ($rank >= self::STAGE_RANK['preparing']) {
            $chain->afterDays(1, fn (DemoContext $c) => $c->as('staff', fn ($staff) => InventoryRequestService::prepare(self::request($c, $key), $staff)), "request {$key} prepare");
        }

        if ($rank >= self::STAGE_RANK['ready']) {
            $chain->afterMinutes(50, fn (DemoContext $c) => $c->as('staff', fn ($staff) => InventoryRequestService::markReady(self::request($c, $key), $staff)), "request {$key} ready");
        }

        if ($rank >= self::STAGE_RANK['issued']) {
            $chain->afterHours(2, fn (DemoContext $c) => $c->as('staff', fn ($staff) => InventoryRequestService::issue(self::request($c, $key), $staff)), "request {$key} issue");
        }

        if ($rank >= self::STAGE_RANK['declared']) {
            $chain->afterDays(2, fn (DemoContext $c) => self::declare($c, $spec), "request {$key} declare");
        }

        if ($until === 'correction') {
            $chain->afterDays(1, fn (DemoContext $c) => $c->as('staff', fn ($staff) => MaterialReturnService::receiveReject(self::returnCase($c, $key), $staff, $spec['reject_reason'] ?? 'Jumlah fisik tidak sesuai deklarasi, mohon hitung ulang')), "request {$key} receive reject");
        }

        if (in_array($until, ['reconciled', 'exception', 'resolved'], true)) {
            $chain->afterDays(1, fn (DemoContext $c) => self::receive($c, $spec), "request {$key} receive");
        }

        if ($until === 'resolved') {
            $chain->afterDays(1, fn (DemoContext $c) => $c->as('manager', fn ($manager) => MaterialReturnService::resolveException(self::returnCase($c, $key), $manager, $spec['resolution'] ?? 'Selisih disetujui')), "request {$key} resolve");
        }
    }

    /** The service stamps request codes with the real date; a request made months ago carries the day it was made. */
    private static function renumber(InventoryRequest $request): InventoryRequest
    {
        $prefix = 'REQ-'.now()->format('Ymd').'-';

        if (str_starts_with($request->code, $prefix)) {
            return $request;
        }

        $last = InventoryRequest::query()->where('code', 'like', $prefix.'%')->max('code');
        $request->update(['code' => $prefix.str_pad((string) (($last === null ? 0 : (int) substr((string) $last, -4)) + 1), 4, '0', STR_PAD_LEFT)]);

        return $request;
    }

    private static function request(DemoContext $c, string $key): InventoryRequest
    {
        return InventoryRequest::query()->findOrFail($c->get('inventory_request', $key));
    }

    private static function returnCase(DemoContext $c, string $key): MaterialReturnCase
    {
        return MaterialReturnCase::query()->where('inventory_request_id', $c->get('inventory_request', $key))->firstOrFail();
    }

    /** @param array<string, mixed> $spec */
    private static function declare(DemoContext $c, array $spec): void
    {
        $skuById = array_flip($c->all('product'));
        $case = self::returnCase($c, $spec['key']);
        $returns = $spec['returns'] ?? [];

        $lines = $case->lines->map(function ($line) use ($skuById, $returns): array {
            [$used, $good, $damaged, $lost] = $returns[$skuById[$line->product_id]] ?? [(float) $line->issued_qty, 0, 0, 0];
            $discrepancy = $damaged + $lost;

            return [
                'id' => $line->id,
                'actual_used_qty' => $used,
                'declared_good_return_qty' => $good,
                'declared_discrepancy_qty' => $discrepancy,
                'discrepancy_code' => $discrepancy > 0 ? ($damaged > 0 ? 'damaged' : 'lost') : null,
                'discrepancy_reason' => $discrepancy > 0 ? ($damaged > 0 ? 'Kabel terjepit dan tergores saat penarikan' : 'Material tidak ditemukan setelah pekerjaan') : null,
            ];
        })->all();

        $c->as($spec['tech'], fn ($tech) => MaterialReturnService::declareUsage($case, $tech, ['lines' => $lines, 'reason' => 'Pekerjaan selesai, sisa material dikembalikan ke gudang']));
    }

    /** @param array<string, mixed> $spec */
    private static function receive(DemoContext $c, array $spec): void
    {
        $skuById = array_flip($c->all('product'));
        $case = self::returnCase($c, $spec['key']);
        $returns = $spec['returns'] ?? [];

        $lines = $case->lines->map(function ($line) use ($skuById, $returns): array {
            [, $good, $damaged, $lost] = $returns[$skuById[$line->product_id]] ?? [0, 0, 0, 0];

            return [
                'id' => $line->id,
                'accepted_good_return_qty' => $good,
                'damaged_return_qty' => $damaged,
                'lost_qty' => $lost,
                'other_discrepancy_qty' => 0,
                'discrepancy_code' => $line->discrepancy_code,
                'discrepancy_reason' => $line->discrepancy_reason,
            ];
        })->all();

        $c->as('staff', fn ($staff) => MaterialReturnService::receiveAccept($case, $staff, ['lines' => $lines]));
    }
}
