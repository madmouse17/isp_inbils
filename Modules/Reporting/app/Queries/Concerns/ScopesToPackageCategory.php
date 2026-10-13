<?php

namespace Modules\Reporting\Queries\Concerns;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Limits subscription-bound rows to the packages of one service package category. The sub-selects name the company
 * explicitly and ignore soft deletes: a package or subscription removed later keeps its category and its money.
 */
trait ScopesToPackageCategory
{
    protected static function packageIdsInCategory(int $companyId, int $categoryId): QueryBuilder
    {
        return DB::table('service_packages')
            ->where('company_id', $companyId)
            ->where('service_package_category_id', $categoryId)
            ->select('id');
    }

    protected static function subscriptionIdsInCategory(int $companyId, int $categoryId): QueryBuilder
    {
        return DB::table('service_subscriptions')
            ->where('company_id', $companyId)
            ->whereIn('service_package_id', self::packageIdsInCategory($companyId, $categoryId))
            ->select('id');
    }
}
