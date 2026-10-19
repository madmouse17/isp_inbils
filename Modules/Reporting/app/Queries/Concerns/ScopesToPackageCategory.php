<?php

namespace Modules\Reporting\Queries\Concerns;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Limits subscription-bound rows to the package versions of one service package category: a subscription belongs to the
 * category its package had when it was sold, so moving a package to another category never moves its old sales. The
 * sub-selects name the company explicitly and ignore soft deletes: a package or subscription removed later keeps its
 * category and its money.
 */
trait ScopesToPackageCategory
{
    protected static function packageVersionIdsInCategory(int $companyId, int $categoryId): QueryBuilder
    {
        return DB::table('service_package_versions')
            ->where('company_id', $companyId)
            ->where('service_package_category_id', $categoryId)
            ->select('id');
    }

    protected static function subscriptionIdsInCategory(int $companyId, int $categoryId): QueryBuilder
    {
        return DB::table('service_subscriptions')
            ->where('company_id', $companyId)
            ->whereIn('service_package_version_id', self::packageVersionIdsInCategory($companyId, $categoryId))
            ->select('id');
    }
}
