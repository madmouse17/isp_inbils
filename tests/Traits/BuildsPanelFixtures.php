<?php

namespace Tests\Traits;

use App\Models\Core\Company;
use App\Services\Core\CompanyService;
use Illuminate\Support\Str;
use Modules\NetworkAsset\Models\AssetPort;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Services\AssetPanelService;
use Modules\NetworkAsset\Services\OdnService;

/**
 * Hardware panel fixtures (spec .scratch/hardware-panel-ui): an OLT with a 128-port face and subscribers behind it, a
 * router with a catalogue model and a rack. Each is built for an explicit company, the acting one by default.
 * Needs BuildsIpamOdnFixtures, BuildsNetworkFixtures and BuildsOdnTopology in the same class, and fakeRadius() when the
 * test provisions accounts (InteractsWithRadius).
 */
trait BuildsPanelFixtures
{
    /**
     * A custom OLT: $ponPorts PON ports in slots of 16 (the last slot also carries 4 uplinks UP1 to UP4). Every PON port
     * feeds a 1:2 splitter on one ODP, and the first $drops of those splitters give output 1 to an active subscriber
     * with a network account `panel{n}@realm`. $linkAll patches the uplinks to ports 1 to 4 of a new 8 port switch.
     */
    protected function panelOlt(int $ponPorts, int $drops, bool $linkAll = false, ?Company $company = null): NetworkAsset
    {
        $company ??= $this->company;

        return CompanyService::runAs($company->id, function () use ($ponPorts, $drops, $linkAll, $company): NetworkAsset {
            $olt = $this->makeAsset($company, 'olt', null, ['code' => 'PNL-OLT-'.Str::upper(Str::random(6))]);
            $odp = $this->makeAsset($company, 'odp', null, ['code' => 'PNL-ODP-'.Str::upper(Str::random(6))]);

            $rows = [];

            for ($slot = 1, $left = $ponPorts; $left > 0; $slot++, $left -= 16) {
                $rows[] = [['group' => "pon{$slot}", 'kind' => 'sfp', 'count' => min(16, $left), 'role' => 'pon', 'slot' => $slot, 'name' => 'gpon-olt_1/{slot}/{port}', 'title' => "Slot {$slot}"]];
            }

            $rows[array_key_last($rows)][] = ['group' => 'uplink', 'kind' => 'sfp', 'count' => 4, 'name' => 'UP{port}', 'title' => 'Uplink'];
            AssetPanelService::apply($olt, null, ['rows' => $rows], null);

            $realm = $this->realmOf($company);

            foreach (OdnPort::query()->where('network_asset_id', $olt->id)->orderBy('number')->get() as $index => $pon) {
                [, $outputs] = $this->splitter($odp, 2, $pon);

                if ($index >= $drops) {
                    continue;
                }

                $subscription = $this->makeSubscription($company);
                NetworkAccount::factory()->create([
                    'company_id' => $company->id,
                    'subscription_id' => $subscription->id,
                    'username' => "panel{$index}@{$realm}",
                ]);
                OdnService::assignDrop($outputs[0], $subscription);
            }

            if ($linkAll) {
                $switch = $this->panelSwitch('generic-switch-8', $company);

                foreach (range(1, 4) as $number) {
                    AssetPanelService::link($this->panelPort($olt, 'uplink', $number), $this->panelPort($switch, 'ether', $number)->id);
                }
            }

            return $olt->refresh();
        });
    }

    /** A router with a catalogue model (default: the CCR2004 with 16 RJ45 and 2 SFP+). */
    protected function panelRouter(string $templateKey = 'mikrotik-ccr2004-16g-2s', ?Company $company = null): NetworkAsset
    {
        return $this->panelDevice('router', $templateKey, $company);
    }

    protected function panelSwitch(string $templateKey = 'generic-switch-8', ?Company $company = null): NetworkAsset
    {
        return $this->panelDevice('switch', $templateKey, $company);
    }

    /** A rack of $heightU units (1 to 52). */
    protected function panelRack(int $heightU = 42, ?Company $company = null): NetworkAsset
    {
        $company ??= $this->company;

        return CompanyService::runAs($company->id, function () use ($heightU, $company): NetworkAsset {
            $rack = $this->makeAsset($company, 'rack', null, ['code' => 'RACK-'.Str::upper(Str::random(6))]);
            AssetPanelService::apply($rack, null, null, $heightU);

            return $rack->refresh();
        });
    }

    /** The asset_ports row of a group and number, e.g. ('ether', 3) or ('uplink', 1). */
    protected function panelPort(NetworkAsset $asset, string $group, int $number): AssetPort
    {
        return AssetPort::withoutCompany()
            ->where('network_asset_id', $asset->id)->where('group_key', $group)->where('number', $number)->firstOrFail();
    }

    private function panelDevice(string $type, string $templateKey, ?Company $company): NetworkAsset
    {
        $company ??= $this->company;

        return CompanyService::runAs($company->id, function () use ($type, $templateKey, $company): NetworkAsset {
            $device = $this->makeAsset($company, $type, null, ['code' => strtoupper(substr($type, 0, 3)).'-'.Str::upper(Str::random(6))]);
            AssetPanelService::apply($device, $templateKey, null, null);

            return $device->refresh();
        });
    }
}
