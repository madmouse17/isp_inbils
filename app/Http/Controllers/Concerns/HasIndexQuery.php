<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Requires the using class to define `private const SORTABLE = [...].`
 */
trait HasIndexQuery
{
    private function applySort(Builder $query, Request $request, string $default, string $defaultDir = 'asc'): Builder
    {
        $sort = $request->input('sort_by', $request->input('sort'));
        $sort = is_string($sort) && in_array($sort, self::SORTABLE, true) ? $sort : $default;
        $dir = strtolower((string) $request->input('sort_dir', $request->input('direction', $request->input('dir', $defaultDir))));
        $dir = $dir === 'desc' ? 'desc' : 'asc';

        return $query->orderBy($sort, $dir);
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->input('per_page', 10);

        return in_array($perPage, [10, 25, 50], true) ? $perPage : 10;
    }
}
