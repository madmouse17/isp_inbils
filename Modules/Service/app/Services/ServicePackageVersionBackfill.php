<?php

namespace Modules\Service\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Puts the packages and subscriptions that predate package versions (spec .scratch/master-lifecycle D3) on a version.
 * Query builder only: no company context, no model scopes, trashed rows included, so the migrations, the seeders and a
 * repair run all see every row. Each step touches only what still lacks a version, so a migration that stopped half
 * way, or rows written around the model hooks, are repaired by calling it again.
 */
final class ServicePackageVersionBackfill
{
    private const CHUNK = 500;

    /** Version 1 of every package that has none: its current terms, valid since the package was created. */
    public static function versions(): void
    {
        $now = now();

        DB::table('service_package_versions')->insertUsing(
            [
                'company_id', 'service_package_id', 'version', 'price_mrc', 'price_otc', 'bandwidth_profile_id',
                'speed_profile_id', 'sla_tier_id', 'service_package_category_id', 'valid_from', 'created_at', 'updated_at',
            ],
            DB::table('service_packages')
                ->whereNotExists(fn (Builder $versioned) => $versioned
                    ->selectRaw('1')
                    ->from('service_package_versions')
                    ->whereColumn('service_package_versions.service_package_id', 'service_packages.id'))
                ->selectRaw(
                    'company_id, id, 1, price_mrc, price_otc, bandwidth_profile_id, speed_profile_id, sla_tier_id, service_package_category_id, COALESCE(created_at, ?), ?, ?',
                    [$now, $now, $now],
                ),
        );
    }

    /** Every subscription without a version joins the latest version of its package. */
    public static function subscriptions(): void
    {
        DB::table('service_subscriptions')->whereNull('service_package_version_id')->chunkById(self::CHUNK, function (Collection $subscriptions): void {
            // Ascending by version, so pluck() keeps the id of the highest version for each package.
            $latest = DB::table('service_package_versions')
                ->whereIn('service_package_id', $subscriptions->pluck('service_package_id')->unique()->all())
                ->orderBy('version')
                ->pluck('id', 'service_package_id');

            foreach ($subscriptions->groupBy('service_package_id') as $packageId => $rows) {
                DB::table('service_subscriptions')
                    ->whereIn('id', $rows->pluck('id')->all())
                    ->update(['service_package_version_id' => $latest[$packageId]]);
            }
        });
    }
}
