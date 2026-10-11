<?php

namespace Modules\Reporting\Queries;

use Illuminate\Support\Facades\DB;
use Modules\Reporting\Queries\Sources\AssetSources;

class AssetUtilizationQuery
{
    /**
     * Every figure reads the builders of AssetSources, the same ones the drill-down lists use.
     * Without a company id the ambient tenant scope of the authenticated user applies.
     */
    public static function execute(?int $locationId = null, ?string $assetType = null, ?string $dateFrom = null, ?string $dateTo = null, ?int $companyId = null): array
    {
        $statusDist = AssetSources::assets($companyId, $locationId, $assetType)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')->pluck('count', 'status')->toArray();

        $byLocation = AssetSources::withLocation($companyId)
            ->select('locations.name', DB::raw('count(*) as count'))
            ->groupBy('locations.name')->pluck('count', 'name')->toArray();

        $installCount = AssetSources::installations($companyId, $dateFrom, $dateTo)->count();

        $customerLinked = AssetSources::customerLinked($companyId)->count();
        $retiredCount = AssetSources::retired($companyId)->count();

        return [
            'status_distribution' => $statusDist,
            'by_location' => $byLocation,
            'installation_count' => $installCount,
            'customer_linked_count' => $customerLinked,
            'retired_count' => $retiredCount,
        ];
    }
}
