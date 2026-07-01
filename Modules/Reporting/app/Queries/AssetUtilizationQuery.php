<?php

namespace Modules\Reporting\Queries;

use Illuminate\Support\Facades\DB;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\NetworkAssetInstallation;

class AssetUtilizationQuery
{
    public static function execute(?int $locationId = null, ?string $assetType = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $query = NetworkAsset::query();
        if ($locationId) $query->where('location_id', $locationId);
        if ($assetType) $query->where('asset_type', $assetType);

        $statusDist = (clone $query)->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')->pluck('count', 'status')->toArray();

        $byLocation = NetworkAsset::query()
            ->join('locations', 'network_assets.location_id', '=', 'locations.id')
            ->select('locations.name', DB::raw('count(*) as count'))
            ->whereNotNull('network_assets.location_id')
            ->groupBy('locations.name')->pluck('count', 'name')->toArray();

        $installQuery = NetworkAssetInstallation::query();
        if ($dateFrom && $dateTo) {
            $installQuery->whereBetween('installed_at', [$dateFrom, $dateTo]);
        }
        $installCount = $installQuery->count();

        $customerLinked = NetworkAsset::whereNotNull('customer_id')->count();
        $retiredCount = NetworkAsset::where('status', 'retired')->count();

        return [
            'status_distribution' => $statusDist,
            'by_location' => $byLocation,
            'installation_count' => $installCount,
            'customer_linked_count' => $customerLinked,
            'retired_count' => $retiredCount,
        ];
    }
}
