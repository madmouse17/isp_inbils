<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;

/**
 * Requires the using class to define `private const SORTABLE = [...]`.
 */
trait HasIndexQuery
{
    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function applySort(Builder|Relation $query, Request $request, string $default, string $defaultDir = 'asc'): Builder
    {
        $sort = $request->input('sort_by', $request->input('sort'));
        $sort = is_string($sort) && in_array($sort, self::SORTABLE, true) ? $sort : $default;
        $dir = strtolower((string) $request->input('sort_dir', $request->input('direction', $request->input('dir', $defaultDir))));
        $dir = $dir === 'desc' ? 'desc' : 'asc';

        if ($query instanceof Relation) {
            $query = $query->getQuery();
        }

        return $query->orderBy($sort, $dir);
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->input('per_page', 10);

        return in_array($perPage, [10, 25, 50], true) ? $perPage : 10;
    }
}
