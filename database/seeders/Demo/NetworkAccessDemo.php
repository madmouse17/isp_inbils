<?php

namespace Database\Seeders\Demo;

use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use Modules\Ipam\Services\IpAssignmentService;
use Modules\NetworkAsset\Models\NasDevice;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Services\Radius\Realm;

/**
 * NAS client rows and PPPoE accounts for the demo company, as data only. Every NAS has a fake shared secret
 * and RADIUS_ENABLED is off by default, so nothing reaches RADIUS: the accounts stay `pending`. Reads the database and skips what exists, like IpamOdnDemo.
 */
final class NetworkAccessDemo
{
    /** Router asset serial => the POPs it serves (the second router is the older border box at the head office). */
    private const ROUTERS = [
        'MKT-CCR2216-001' => ['hq', 'dgo'],
        'MKT-CCR-001' => ['ant', 'bbh'],
    ];

    /** The address the older router gets, so it can be registered as a NAS. */
    private const LEGACY_ROUTER_IP = '10.10.0.2';

    public static function seed(DemoContext $c): void
    {
        $c->as('manager', function (): void {
            $routers = self::routers();

            self::accounts($routers);
        });
    }

    /** @return array<string, NasDevice> POP key => the NAS router that serves it */
    private static function routers(): array
    {
        $byPop = [];

        foreach (self::ROUTERS as $serial => $pops) {
            $asset = NetworkAsset::query()->where('serial_number', $serial)->first();

            if ($asset === null) {
                continue;
            }

            if (blank($asset->getAttribute('management_ip'))) {
                $asset->update(['management_ip' => self::LEGACY_ROUTER_IP]);
                IpAssignmentService::syncAssetAddresses($asset);
            }

            $nas = NasDevice::query()->where('network_asset_id', $asset->id)->first() ?? NasDevice::query()->create([
                'network_asset_id' => $asset->id,
                'nasname' => $asset->getAttribute('management_ip'),
                'radius_secret' => 'demo-not-a-real-secret',
            ]);

            foreach ($pops as $pop) {
                $byPop[$pop] = $nas;
            }
        }

        return $byPop;
    }

    /**
     * Two of every three subscriptions in service get a PPPoE account on the router of their POP, named after
     * the customer code; suspended ones show the isolir state.
     *
     * @param  array<string, NasDevice>  $routers
     */
    private static function accounts(array $routers): void
    {
        $realm = Realm::forCompany((int) CompanyService::currentId());
        $popKeys = [];

        foreach (Location::query()->where('type', 'pop')->get(['id', 'code']) as $pop) {
            $popKeys[$pop->id] = strtolower(substr((string) $pop->code, -3));
        }

        $subscriptions = ServiceSubscription::query()
            ->whereIn('status', ['active', 'suspended'])
            ->with(['customer:id,code', 'servicePackage.speedProfile'])
            ->orderBy('id')
            ->get();

        foreach ($subscriptions->values() as $index => $subscription) {
            $nas = $routers[$popKeys[$subscription->serving_pop_id] ?? ''] ?? null;

            if ($realm === null || $index % 3 === 2 || $nas === null || $subscription->customer === null || NetworkAccount::query()->where('subscription_id', $subscription->id)->exists()) {
                continue;
            }

            NetworkAccount::query()->create([
                'subscription_id' => $subscription->id,
                'service_type' => 'pppoe',
                'username' => strtolower($subscription->customer->code).'@'.$realm,
                'password' => substr(hash_hmac('sha256', $subscription->customer->code, 'demo-pppoe'), 0, 12),
            ]);
        }
    }
}
