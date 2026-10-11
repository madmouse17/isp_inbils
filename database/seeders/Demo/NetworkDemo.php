<?php

namespace Database\Seeders\Demo;

use App\Models\Core\Location;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Services\StockService;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Services\NetworkAssetService;
use RuntimeException;

/** Network gear: the core of each POP, ODC and ODP boxes, and the serialised ONT units bought for customers. */
final class NetworkDemo
{
    /** sku => [vendor, model, name, serial prefix, MAC prefix] */
    private const ONTS = [
        'CPE-ONT-HG8145V5' => ['Huawei', 'HG8145V5', 'ONT Huawei HG8145V5', 'HWTC', '48:57:02'],
        'CPE-ONT-F670L' => ['ZTE', 'ZXHN F670L', 'ONT ZTE ZXHN F670L', 'ZTEG', '30:0D:9E'],
    ];

    /** key => [name, type, serial, vendor, model, price, POP, management IP] */
    private const CORE = [
        'olt-ant' => ['OLT Huawei MA5800-X2 Antapani', 'olt', 'HW-MA5800X2-003', 'Huawei', 'MA5800-X2', 185000000, 'ant', '10.10.2.2'],
        'olt-bbh' => ['OLT Huawei MA5800-X2 Buah Batu', 'olt', 'HW-MA5800X2-004', 'Huawei', 'MA5800-X2', 185000000, 'bbh', '10.10.3.2'],
        'sw-dgo' => ['Switch Cisco SG350-28 Dago', 'switch', 'CSC-SG350-002', 'Cisco', 'SG350-28', 8500000, 'dgo', '10.10.1.3'],
        'sw-ant' => ['Switch TP-Link TL-SG3428 Antapani', 'switch', 'TPL-SG3428-001', 'TP-Link', 'TL-SG3428', 5200000, 'ant', '10.10.2.3'],
        'rtr-core' => ['Router Mikrotik CCR2216 Border BGP', 'router', 'MKT-CCR2216-001', 'Mikrotik', 'CCR2216-1G-12XS-2XQ', 62000000, 'hq', '10.10.0.1'],
        'ups-hq' => ['UPS APC Smart-UPS 3000VA POP Pusat', 'power', 'APC-SMT3000-001', 'APC', 'SMT3000RMI2U', 24000000, 'hq', null],
        'ups-dgo' => ['UPS APC Smart-UPS 1500VA POP Dago', 'power', 'APC-SMT1500-001', 'APC', 'SMT1500RMI2U', 13500000, 'dgo', null],
        'ups-ant' => ['UPS APC Smart-UPS 1500VA POP Antapani', 'power', 'APC-SMT1500-002', 'APC', 'SMT1500RMI2U', 13500000, 'ant', null],
        'ups-bbh' => ['UPS APC Smart-UPS 1500VA POP Buah Batu', 'power', 'APC-SMT1500-003', 'APC', 'SMT1500RMI2U', 13500000, 'bbh', null],
    ];

    /** The kinds of gear the company seeder created without a place: serial => POP it is installed in. */
    private const LEGACY_INSTALL = [
        'HW-MA5800-001' => 'hq',
        'HW-MA5800-002' => 'dgo',
        'CSC-SG350-001' => 'hq',
        'MKT-CCR-001' => 'hq',
        'RCK-42U-001' => 'hq',
        'UBQ-ANT-001' => 'bbh',
        'UBQ-RAD-001' => 'bbh',
    ];

    private const POP_LABELS = ['dgo' => ['Dago', 'DGO'], 'ant' => ['Antapani', 'ANT'], 'bbh' => ['Buah Batu', 'BBH']];

    /** New core gear plus an ODC per rack and an ODP per site. */
    public static function seedCore(DemoContext $c): void
    {
        foreach (self::CORE as $key => [$name, $type, $serial, $vendor, $model, $price, , $managementIp]) {
            $asset = NetworkAsset::query()->create([
                'code' => NetworkAssetService::generateCode(),
                'name' => $name,
                'asset_type' => $type,
                'serial_number' => $serial,
                'management_ip' => $managementIp,
                'status' => 'available',
                'ownership' => 'owned',
                'vendor' => $vendor,
                'model' => $model,
                'purchase_date' => now()->subMonths(8)->toDateString(),
                'purchase_price' => $price,
                'warranty_expiry' => now()->addYears(3)->toDateString(),
            ]);
            $c->put('asset', $key, $asset->id);
        }

        $retired = NetworkAsset::query()->create([
            'code' => NetworkAssetService::generateCode(),
            'name' => 'Router Mikrotik RB951 (lama)',
            'asset_type' => 'router',
            'serial_number' => 'MKT-RB951-OLD',
            'status' => 'available',
            'ownership' => 'owned',
            'vendor' => 'Mikrotik',
            'model' => 'RB951Ui-2HnD',
            'purchase_date' => now()->subYears(5)->toDateString(),
            'purchase_price' => 750000,
        ]);
        $c->put('asset', 'rtr-old', $retired->id);

        foreach (self::POP_LABELS as $pop => [$label, $suffix]) {
            foreach ([1, 2] as $odc) {
                self::passive($c, "{$pop}-odc{$odc}", sprintf('ODC %s %02d', $label, $odc), 'odc', sprintf('ODC-%s-%02d-SN', $suffix, $odc), 'Datacom', 'ODC 144 Core', 14500000);

                foreach ([1, 2, 3] as $odp) {
                    self::passive($c, "{$pop}-odp{$odc}{$odp}", sprintf('ODP %s %02d-%02d', $label, $odc, $odp), 'odp', sprintf('ODP-%s-%02d-%02d-SN', $suffix, $odc, $odp), 'Datacom', 'ODP 16 Port', 1850000);
                }
            }
        }
    }

    private static function passive(DemoContext $c, string $locationKey, string $name, string $type, string $serial, string $vendor, string $model, int $price): void
    {
        $asset = NetworkAsset::query()->create([
            'code' => NetworkAssetService::generateCode(),
            'name' => $name,
            'asset_type' => $type,
            'serial_number' => $serial,
            'status' => 'available',
            'ownership' => 'owned',
            'vendor' => $vendor,
            'model' => $model,
            'purchase_date' => now()->subMonths(8)->toDateString(),
            'purchase_price' => $price,
        ]);
        $c->put('asset', $locationKey, $asset->id);
    }

    /** Installs the gear in its POP, rack or site and takes a few units out of service. */
    public static function planCore(DemoContext $c): void
    {
        $c->timeline->at($c->day(175, 9, 30), function (DemoContext $c): void {
            $c->as('manager', function () use ($c): void {
                foreach (self::LEGACY_INSTALL as $serial => $pop) {
                    NetworkAssetService::install(NetworkAsset::query()->where('serial_number', $serial)->firstOrFail(), $c->get('location', $pop));
                }

                foreach (self::CORE as $key => [, , , , , , $pop]) {
                    NetworkAssetService::install(NetworkAsset::query()->findOrFail($c->get('asset', $key)), $c->get('location', $pop));
                }

                foreach (array_keys(self::POP_LABELS) as $pop) {
                    foreach ([1, 2] as $odc) {
                        NetworkAssetService::install(NetworkAsset::query()->findOrFail($c->get('asset', "{$pop}-odc{$odc}")), $c->get('location', "{$pop}-odc{$odc}"));

                        foreach ([1, 2, 3] as $odp) {
                            NetworkAssetService::install(NetworkAsset::query()->findOrFail($c->get('asset', "{$pop}-odp{$odc}{$odp}")), $c->get('location', "{$pop}-odp{$odc}{$odp}"));
                        }
                    }
                }
            });
        }, 'install core network gear');

        $c->timeline->at($c->day(150, 14, 0), fn (DemoContext $c) => $c->as('manager', fn () => NetworkAssetService::retire(
            NetworkAsset::query()->findOrFail($c->get('asset', 'rtr-old')),
            'Diganti Mikrotik CCR2216, perangkat end of life',
        )), 'retire old router');

        $c->timeline->at($c->day(41, 13, 20), fn (DemoContext $c) => $c->as('technician', fn () => NetworkAssetService::setDamaged(
            NetworkAsset::query()->where('serial_number', 'UBQ-RAD-001')->firstOrFail(),
            'Radio backhaul terkena sambaran petir, port ethernet mati',
        )), 'radio damaged');

        $c->timeline->at($c->day(6, 10, 10), fn (DemoContext $c) => $c->as('technician', fn () => NetworkAssetService::setMaintenance(
            NetworkAsset::query()->findOrFail($c->get('asset', 'sw-dgo')),
            'Upgrade firmware dan penggantian modul SFP, dijadwalkan selesai minggu ini',
        )), 'switch maintenance');
    }

    /** Creates the serialised units of a purchase of ONT devices, in the main warehouse. */
    public static function receiveOnts(DemoContext $c, string $sku, int $quantity, string $po): void
    {
        [$vendor, $model, $name, $serialPrefix, $macPrefix] = self::ONTS[$sku];
        $product = Product::query()->findOrFail($c->get('product', $sku));
        $counter = $c->has('counter', $sku) ? $c->get('counter', $sku) : 0;

        for ($i = 0; $i < $quantity; $i++) {
            $counter++;
            $hash = strtoupper(md5($sku.$counter));

            NetworkAsset::query()->create([
                'product_id' => $product->id,
                'code' => NetworkAssetService::generateCode(),
                'name' => $name,
                'asset_type' => 'onu_ont',
                'serial_number' => $serialPrefix.substr($hash, 0, 8),
                'mac_address' => $macPrefix.':'.implode(':', str_split(substr($hash, 8, 6), 2)),
                'location_id' => $c->get('location', 'hq'),
                'status' => 'available',
                'ownership' => 'owned',
                'vendor' => $vendor,
                'model' => $model,
                'purchase_date' => now()->toDateString(),
                'purchase_price' => $product->cost_price,
                'warranty_expiry' => now()->addYears(2)->toDateString(),
                'notes' => "Pembelian {$po}",
            ]);
        }

        $c->put('counter', $sku, $counter);
    }

    /** Moves the units along with a stock transfer, so asset locations match the ledger. */
    public static function moveOnts(DemoContext $c, string $sku, string $from, string $to, int $quantity): void
    {
        $ids = NetworkAsset::query()
            ->where('product_id', $c->get('product', $sku))
            ->where('status', 'available')
            ->where('location_id', $c->get('location', $from))
            ->orderBy('id')
            ->limit($quantity)
            ->pluck('id');

        if ($ids->count() < $quantity) {
            throw new RuntimeException("Only {$ids->count()} of {$quantity} {$sku} units are at {$from}.");
        }

        NetworkAsset::query()->whereIn('id', $ids)->update(['location_id' => $c->get('location', $to)]);
    }

    /** A spare ONT at the POP, the preferred model first. */
    public static function pickOnt(DemoContext $c, string $pop, string $preferredSku): NetworkAsset
    {
        $skus = $preferredSku === 'CPE-ONT-F670L' ? ['CPE-ONT-F670L', 'CPE-ONT-HG8145V5'] : ['CPE-ONT-HG8145V5', 'CPE-ONT-F670L'];

        foreach ($skus as $sku) {
            $asset = NetworkAsset::query()
                ->where('product_id', $c->get('product', $sku))
                ->where('status', 'available')
                ->where('location_id', $c->get('location', $pop))
                ->orderBy('id')
                ->first();

            if ($asset !== null) {
                return $asset;
            }
        }

        throw new RuntimeException("No spare ONT left at POP {$pop}.");
    }

    /** A returned ONT goes back on the shelf of the POP: asset location and stock agree again. */
    public static function shelveOnt(DemoContext $c, NetworkAsset $asset, string $pop): void
    {
        $asset->forceFill(['location_id' => $c->get('location', $pop)])->save();

        StockService::returnStock((int) $asset->product_id, $c->get('location', $pop), 1, 'ONT dikembalikan pelanggan: '.$asset->serial_number);
    }
}
