<?php

namespace Modules\Reporting\Queries\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait ScopesToCompany
{
    /**
     * Fail-closed tenant scope when a company id is given; the ambient global scope of the
     * authenticated user otherwise (what the report queries relied on before drill-down existed).
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return Builder<TModel>
     */
    protected static function scoped(string $model, ?int $companyId): Builder
    {
        return $companyId === null ? $model::query() : $model::forCompany($companyId);
    }
}
