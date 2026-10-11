<?php

namespace Modules\Reporting\Queries\Sources;

use Illuminate\Database\Eloquent\Builder;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\NetworkAssetInstallation;
use Modules\Reporting\Queries\Concerns\AppliesDateRange;
use Modules\Reporting\Queries\Concerns\ScopesToCompany;

/**
 * The assets and installation records behind the Asset report and the Business asset
 * distribution. Shared by the aggregates and the drill-down lists. v1: only the status
 * distribution honours the location and type filters.
 */
final class AssetSources
{
    use AppliesDateRange;
    use ScopesToCompany;

    /** @return Builder<NetworkAsset> */
    public static function assets(?int $companyId, ?int $locationId = null, ?string $assetType = null): Builder
    {
        $query = self::scoped(NetworkAsset::class, $companyId);

        if ($locationId) {
            $query->where('location_id', $locationId);
        }

        if ($assetType) {
            $query->where('asset_type', $assetType);
        }

        return $query;
    }

    /**
     * Assets with a location, joined to it for the per-location breakdown. v1: grouped by location
     * name, so same-named locations merge.
     *
     * @return Builder<NetworkAsset>
     */
    public static function withLocation(?int $companyId): Builder
    {
        return self::scoped(NetworkAsset::class, $companyId)
            ->join('locations', 'network_assets.location_id', '=', 'locations.id')
            ->whereNotNull('network_assets.location_id');
    }

    /** @return Builder<NetworkAssetInstallation> */
    public static function installations(?int $companyId, ?string $dateFrom, ?string $dateTo): Builder
    {
        $query = self::scoped(NetworkAssetInstallation::class, $companyId);
        self::applyDateRange($query, 'installed_at', $dateFrom, $dateTo);

        return $query;
    }

    /** @return Builder<NetworkAsset> */
    public static function customerLinked(?int $companyId): Builder
    {
        return self::assets($companyId)->whereNotNull('customer_id');
    }

    /** @return Builder<NetworkAsset> */
    public static function retired(?int $companyId): Builder
    {
        return self::assets($companyId)->where('status', 'retired');
    }
}
