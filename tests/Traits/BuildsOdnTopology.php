<?php

namespace Tests\Traits;

use App\Models\Core\Company;
use App\Models\Core\Location;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Models\OdnSplitter;
use Modules\NetworkAsset\Services\OdnService;

/**
 * A POP with the usual fibre path under it: an OLT at the POP, an ODC at a rack and an ODP at a site, the way
 * the demo data models them (rack = ODC location, site = ODP location). Needs BuildsIpamOdnFixtures.
 */
trait BuildsOdnTopology
{
    /**
     * @return array{pop: Location, rack: Location, site: Location, olt: NetworkAsset, odc: NetworkAsset, odp: NetworkAsset}
     */
    protected function topology(Company $company, string $suffix = 'A'): array
    {
        $pop = $this->makeLocation($company, "POP-{$suffix}", 'pop');
        $rack = $this->makeLocation($company, "RACK-{$suffix}1", 'rack', $pop);
        $site = $this->makeLocation($company, "SITE-{$suffix}11", 'site', $rack);

        return [
            'pop' => $pop,
            'rack' => $rack,
            'site' => $site,
            'olt' => $this->makeAsset($company, 'olt', $pop, ['code' => "OLT-{$suffix}1"]),
            'odc' => $this->makeAsset($company, 'odc', $rack, ['code' => "ODC-{$suffix}1"]),
            'odp' => $this->makeAsset($company, 'odp', $site, ['code' => "ODP-{$suffix}11"]),
        ];
    }

    /** An extra ODP under the given site location. */
    protected function odpAt(Company $company, Location $site, string $code): NetworkAsset
    {
        return $this->makeAsset($company, 'odp', $site, ['code' => $code]);
    }

    /** @return list<OdnPort> */
    protected function ponPorts(NetworkAsset $olt, int $count = 4): array
    {
        return OdnService::createPonPorts($olt, $count)->all();
    }

    /** @return array{0: OdnSplitter, 1: list<OdnPort>} the splitter and its outputs in number order */
    protected function splitter(NetworkAsset $host, int $outputs, OdnPort|int|null $input = null, ?string $label = null): array
    {
        $splitter = OdnService::createSplitter($host, $outputs, $input, $label);

        return [$splitter, $splitter->ports()->orderBy('number')->get()->all()];
    }
}
