<?php

namespace Database\Seeders\Demo;

use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use Modules\Ipam\Models\IpAddress;
use Modules\Ipam\Models\IpPrefix;
use Modules\Ipam\Models\Vlan;
use Modules\Ipam\Services\IpAssignmentService;
use Modules\Ipam\Services\IpPrefixService;
use Modules\Ipam\Services\VlanService;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Models\OdnSplitter;
use Modules\NetworkAsset\Services\OdnService;

/**
 * The address plan and the fibre tree of the demo company: a /22 static and a /22 dynamic customer pool per POP,
 * the management addresses of the core gear, PON ports on every OLT, a 1:4 splitter per ODC and a 1:8 splitter
 * per ODP wired from the OLT down, and a drop for every subscription that is in service. Written through
 * IpPrefixService, IpAssignmentService and OdnService only. Reads everything it needs from the database and
 * skips what exists, so it runs after a fresh seed and on a demo company that was seeded before these lanes.
 */
final class IpamOdnDemo
{
    private const PON_PORTS = 8;

    /** POP key => [location code, code suffix, VLAN id]; the index in this list is the second octet block of its pools. */
    private const POPS = [
        'hq' => ['POP-BDG-HQ', null, 100],
        'dgo' => ['POP-BDG-DGO', 'DGO', 110],
        'ant' => ['POP-BDG-ANT', 'ANT', 120],
        'bbh' => ['POP-BDG-BBH', 'BBH', 130],
    ];

    /** Customer types that get a fixed address with their service. */
    private const STATIC_IP_TYPE = 'Company';

    public static function seed(DemoContext $c): void
    {
        $c->as('manager', function (): void {
            $pops = self::pops();

            self::prefixes($pops);
            self::managementAddresses();
            self::fibreTree($pops);
            self::staticAddresses($pops);
            self::drops($pops);
        });
    }

    /** @return array<string, Location> POP key => POP location, for the POPs the company has */
    private static function pops(): array
    {
        $pops = [];

        foreach (self::POPS as $key => [$code]) {
            $location = Location::query()->where('code', $code)->first();

            if ($location !== null) {
                $pops[$key] = $location;
            }
        }

        return $pops;
    }

    /** @param array<string, Location> $pops */
    private static function prefixes(array $pops): void
    {
        self::prefix('10.10.0.0/22', 'infrastructure', null, null, 'Manajemen perangkat jaringan');
        self::prefix('10.20.0.0/16', 'container', null, null, 'Blok pelanggan Kota Bandung');

        $index = 0;

        foreach (self::POPS as $key => [, , $vid]) {
            if (! isset($pops[$key])) {
                continue;
            }

            $location = $pops[$key];
            $vlan = Vlan::query()->where('vid', $vid)->where('location_id', $location->id)->first()
                ?? VlanService::create(['vid' => $vid, 'name' => 'Pelanggan '.strtoupper($key), 'location_id' => $location->id]);

            $base = $index * 8;
            self::prefix("10.20.{$base}.0/22", 'customer_static', $location, $vlan, "IP statis pelanggan {$location->name}");
            self::prefix('10.20.'.($base + 4).'.0/22', 'customer_dynamic', $location, $vlan, "IP dinamis pelanggan {$location->name}");
            $index++;
        }
    }

    private static function prefix(string $cidr, string $usage, ?Location $location, ?Vlan $vlan, string $description): IpPrefix
    {
        return IpPrefix::query()->where('cidr', $cidr)->first() ?? IpPrefixService::create([
            'cidr' => $cidr,
            'usage' => $usage,
            'location_id' => $location?->id,
            'vlan_id' => $vlan?->id,
            'description' => $description,
        ]);
    }

    /** Every asset with a management or service address owns it in the register. */
    private static function managementAddresses(): void
    {
        $assets = NetworkAsset::query()
            ->where(fn ($query) => $query->whereNotNull('management_ip')->orWhereNotNull('ip_address'))
            ->orderBy('id')
            ->get();

        foreach ($assets as $asset) {
            IpAssignmentService::syncAssetAddresses($asset);
        }
    }

    /**
     * PON ports on every OLT, then per POP: ODC 1 and 2 on PON 1 and 2 of the POP's OLT, each ODP of an ODC on
     * one output of its ODC splitter.
     *
     * @param  array<string, Location>  $pops
     */
    private static function fibreTree(array $pops): void
    {
        foreach (NetworkAsset::query()->where('asset_type', 'olt')->orderBy('id')->get() as $olt) {
            if (! OdnPort::query()->where('network_asset_id', $olt->id)->where('kind', OdnPort::KIND_PON)->exists()) {
                OdnService::createPonPorts($olt, self::PON_PORTS);
            }
        }

        foreach ($pops as $key => $pop) {
            $suffix = self::POPS[$key][1];
            $olt = $suffix === null ? null : NetworkAsset::query()->where('asset_type', 'olt')->where('location_id', $pop->id)->orderBy('id')->first();

            if ($olt === null) {
                continue;
            }

            foreach ([1, 2] as $odcNumber) {
                $odc = NetworkAsset::query()->where('serial_number', sprintf('ODC-%s-%02d-SN', $suffix, $odcNumber))->first();

                if ($odc === null) {
                    continue;
                }

                $trunk = OdnPort::query()->where('network_asset_id', $olt->id)->where('kind', OdnPort::KIND_PON)->where('number', $odcNumber)->first();
                $odcSplitter = self::splitter($odc, 4, $trunk, "{$odc->name} 1:4");

                foreach ([1, 2, 3] as $odpNumber) {
                    $odp = NetworkAsset::query()->where('serial_number', sprintf('ODP-%s-%02d-%02d-SN', $suffix, $odcNumber, $odpNumber))->first();

                    if ($odp !== null) {
                        $feeder = OdnPort::query()->where('odn_splitter_id', $odcSplitter->id)->where('number', $odpNumber)->first();
                        self::splitter($odp, 8, $feeder, "{$odp->name} 1:8");
                    }
                }
            }
        }
    }

    private static function splitter(NetworkAsset $host, int $outputs, ?OdnPort $input, string $label): OdnSplitter
    {
        return OdnSplitter::query()->where('network_asset_id', $host->id)->first()
            ?? OdnService::createSplitter($host, $outputs, $input, $label);
    }

    /**
     * Businesses get a fixed address from the static pool of their serving POP; the first address of every pool
     * is held back as the gateway.
     *
     * @param  array<string, Location>  $pops
     */
    private static function staticAddresses(array $pops): void
    {
        $pools = IpPrefix::query()->where('usage', 'customer_static')->get()->keyBy('location_id');

        foreach ($pools as $pool) {
            $gateway = long2ip(ip2long(explode('/', $pool->cidr)[0]) + 1);

            if (! IpAddress::query()->where('address', $gateway)->exists()) {
                IpAssignmentService::reserve($pool, $gateway, 'Gateway');
            }
        }

        $subscriptions = ServiceSubscription::query()
            ->where('status', 'active')
            ->whereHas('customer', fn ($query) => $query->where('type', self::STATIC_IP_TYPE))
            ->orderBy('id')
            ->get();

        foreach ($subscriptions as $subscription) {
            $pool = $pools->get($subscription->serving_pop_id);

            if ($pool !== null && ! IpAddress::query()->where('subscription_id', $subscription->id)->exists()) {
                IpAssignmentService::assignToSubscription($subscription, $pool);
            }
        }
    }

    /**
     * Subscriptions in service take the next free output of an ODP under their serving POP, spread over its ODPs.
     *
     * @param  array<string, Location>  $pops
     */
    private static function drops(array $pops): void
    {
        $odps = [];

        foreach ($pops as $key => $pop) {
            $suffix = self::POPS[$key][1];

            if ($suffix === null) {
                continue;
            }

            $odps[$pop->id] = NetworkAsset::query()
                ->where('asset_type', 'odp')
                ->where('serial_number', 'like', "ODP-{$suffix}-%")
                ->orderBy('serial_number')
                ->pluck('id')
                ->all();
        }

        $turn = [];
        $subscriptions = ServiceSubscription::query()->whereIn('status', ['active', 'suspended'])->orderBy('id')->get();

        foreach ($subscriptions as $subscription) {
            $candidates = $odps[$subscription->serving_pop_id] ?? [];

            if ($candidates === [] || OdnPort::query()->where('subscription_id', $subscription->id)->exists()) {
                continue;
            }

            $turn[$subscription->serving_pop_id] ??= 0;

            for ($tries = 0; $tries < count($candidates); $tries++) {
                $odpId = $candidates[$turn[$subscription->serving_pop_id]++ % count($candidates)];
                $port = OdnPort::query()
                    ->where('network_asset_id', $odpId)
                    ->where('kind', OdnPort::KIND_SPLITTER_OUT)
                    ->whereNull('subscription_id')
                    ->orderBy('number')
                    ->first();

                if ($port !== null) {
                    OdnService::assignDrop($port, $subscription);

                    break;
                }
            }
        }
    }
}
